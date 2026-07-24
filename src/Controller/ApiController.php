<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use ExeLearning\Service\ElpFileService;

/**
 * REST API controller for eXeLearning operations.
 */
class ApiController extends AbstractActionController
{
    use CsrfValidationTrait;

    /** @var ElpFileService */
    protected $elpService;

    /**
     * @param ElpFileService $elpService
     */
    public function __construct(ElpFileService $elpService)
    {
        $this->elpService = $elpService;
    }

    /**
     * Build an absolute content proxy URL for the given hash.
     *
     * Derives the base path from the actual request URI path so that the
     * playground prefix (/playground/{uuid}/php83/) is correctly included
     * even in PHP-WASM environments where getBasePath() is unreliable.
     */
    protected function buildContentUrl(string $hash): string
    {
        $request = $this->getRequest();
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $port = $uri->getPort();
        $serverUrl = $scheme . '://' . $uri->getHost();
        if ($port && !(($scheme === 'http' && $port == 80) || ($scheme === 'https' && $port == 443))) {
            $serverUrl .= ':' . $port;
        }
        $basePath = $this->extractBasePath($uri->getPath());
        return $serverUrl . $basePath . '/exelearning/content/' . $hash . '/index.html';
    }

    /**
     * Derive the Omeka base path from the actual request URI path.
     *
     * Strips everything from the first known Omeka route segment onward.
     * Reliable in PHP-WASM where the full URL path is preserved in the URI.
     */
    protected function extractBasePath(string $uriPath): string
    {
        // Strip from the marker that appears EARLIEST in the path, not the
        // first one in this list — otherwise a path like
        // `/sub/s/site/admin/...` would be cut at `/admin/` and keep too much.
        $earliest = null;
        foreach (['/admin/', '/s/', '/api/'] as $marker) {
            $pos = strpos($uriPath, $marker);
            if ($pos !== false && ($earliest === null || $pos < $earliest)) {
                $earliest = $pos;
            }
        }
        return $earliest === null ? '' : substr($uriPath, 0, $earliest);
    }

    /**
     * Create a JSON error response with status code.
     */
    protected function errorResponse(int $statusCode, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($statusCode);
        return new JsonModel(['success' => false, 'message' => $message]);
    }

    /**
     * Get media by ID or return null if not found.
     */
    protected function getMediaOrFail(int $mediaId)
    {
        try {
            return $this->api()->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Save an edited eXeLearning file.
     *
     * POST /api/exelearning/save/:id
     *
     * @return JsonModel
     *
     * @codeCoverageIgnore
     */
    public function saveAction()
    {
        try {
            return $this->doSave();
        } catch (\Throwable $e) {
            // Log the detail server-side; never leak file paths / line numbers
            // back to the client.
            error_log(sprintf(
                '[ExeLearning] save error: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return $this->errorResponse(500, 'An unexpected error occurred while saving.');
        }
    }

    private function doSave()
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->errorResponse(405, 'Method not allowed');
        }

        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }

        if (!$this->validateCsrf($request)) {
            return $this->errorResponse(403, 'CSRF: Invalid or missing CSRF token');
        }

        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->errorResponse(400, 'Media ID required');
        }

        $media = $this->getMediaOrFail((int) $mediaId);
        if (!$media) {
            return $this->errorResponse(404, 'Media not found');
        }

        // Check permissions
        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            return $this->errorResponse(403, 'Forbidden');
        }

        // Accept file via multipart upload OR raw binary body.
        // Raw binary is needed for php-wasm environments where $_FILES is not populated.
        $contentType = $request->getHeaders()->get('Content-Type');
        $contentTypeValue = $contentType ? $contentType->getFieldValue() : '';
        $tmpFile = null;

        if (stripos($contentTypeValue, 'application/octet-stream') !== false
            || stripos($contentTypeValue, 'application/zip') !== false) {
            $body = $request->getContent();
            if (empty($body)) {
                return $this->errorResponse(400, 'Empty request body');
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'exelearning-save-');
            if (file_put_contents($tmpFile, $body) === false) {
                @unlink($tmpFile);
                return $this->errorResponse(500, 'Failed to write request body to temp file');
            }
        } else {
            $files = $request->getFiles();
            if (!empty($files['file'])) {
                if ($files['file']['error'] !== UPLOAD_ERR_OK) {
                    return $this->errorResponse(400, 'Upload failed: error code ' . $files['file']['error']);
                }
                $tmpFile = $files['file']['tmp_name'];
            }
        }

        if (!$tmpFile) {
            return $this->errorResponse(400, 'No file uploaded');
        }

        try {
            // Replace the file
            $result = $this->elpService->replaceFile($media, $tmpFile);

            // Return a relative content path; JS prepends the correct base from
            // window.location (PHP cannot see the playground SW scope prefix).
            $contentPath = $result['hasPreview']
                ? '/exelearning/content/' . $result['hash'] . '/index.html'
                : null;

            return new JsonModel([
                'success' => true,
                'message' => 'File saved successfully',
                'media_id' => (int) $mediaId,
                'contentPath' => $contentPath,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, 'Save failed: ' . $e->getMessage());
        }
    }

    /**
     * Get eXeLearning file data.
     *
     * GET /api/exelearning/elp-data/:id
     */
    public function getDataAction(): JsonModel
    {
        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->errorResponse(400, 'Media ID required');
        }

        $media = $this->getMediaOrFail((int) $mediaId);
        if (!$media) {
            return $this->errorResponse(404, 'Media not found');
        }

        $hash = $this->elpService->getMediaHash($media);
        $hasPreview = $this->elpService->hasPreview($media);
        $hasScreenshot = $this->elpService->hasScreenshot($media);

        // Return relative paths; JS prepends the correct base from
        // window.location (PHP cannot see the playground SW scope prefix).
        $contentPath = ($hash && $hasPreview)
            ? '/exelearning/content/' . $hash . '/index.html'
            : null;
        $screenshotPath = ($hash && $hasScreenshot)
            ? '/exelearning/content/' . $hash . '/' . \ExeLearning\Service\ElpFileService::SCREENSHOT_FILENAME
            : null;

        return new JsonModel([
            'success' => true,
            'id' => (int) $mediaId,
            'url' => $media->originalUrl(),
            'title' => $media->displayTitle(),
            'filename' => $media->filename(),
            'hasPreview' => $hasPreview,
            'contentPath' => $contentPath,
            'hasScreenshot' => $hasScreenshot,
            'screenshotPath' => $screenshotPath,
            'teacherModeVisible' => $this->elpService->isTeacherModeVisible($media),
        ]);
    }

    /**
     * Persist teacher mode visibility setting for a media item.
     *
     * POST /api/exelearning/teacher-mode/:id
     */
    public function setTeacherModeAction(): JsonModel
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->errorResponse(405, 'Method not allowed');
        }

        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }

        if (!$this->validateCsrf($request)) {
            return $this->errorResponse(403, 'CSRF: Invalid or missing CSRF token');
        }

        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->errorResponse(400, 'Media ID required');
        }

        $media = $this->getMediaOrFail((int) $mediaId);
        if (!$media) {
            return $this->errorResponse(404, 'Media not found');
        }

        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            return $this->errorResponse(403, 'Forbidden');
        }

        $rawValue = $request->getPost('teacher_mode_visible', '0');
        $visible = !in_array(strtolower((string) $rawValue), ['0', 'false', 'no'], true);

        try {
            $this->elpService->setTeacherModeVisible($media, $visible);
            return new JsonModel([
                'success' => true,
                'media_id' => (int) $mediaId,
                'teacherModeVisible' => $visible,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, 'Update failed: ' . $e->getMessage());
        }
    }
}

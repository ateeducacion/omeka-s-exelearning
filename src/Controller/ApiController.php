<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\StaticEditorInstaller;

/**
 * REST API controller for eXeLearning operations.
 */
class ApiController extends AbstractActionController
{
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
        foreach (['/admin/', '/s/', '/api/'] as $marker) {
            $pos = strpos($uriPath, $marker);
            if ($pos !== false) {
                return substr($uriPath, 0, $pos);
            }
        }
        return '';
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
            return $this->errorResponse(500, $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

        $csrfToken = $request->getPost('csrf');
        if (!$csrfToken) {
            $csrfHeader = $request->getHeaders()->get('X-CSRF-Token');
            $csrfToken = $csrfHeader ? $csrfHeader->getFieldValue() : null;
        }
        if ($csrfToken) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'csrf']);
            if (!$csrf->isValid($csrfToken)) {
                return $this->errorResponse(403, 'CSRF: Invalid or missing CSRF token');
            }
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

        // Return a relative content path; JS prepends the correct base from
        // window.location (PHP cannot see the playground SW scope prefix).
        $contentPath = ($hash && $hasPreview)
            ? '/exelearning/content/' . $hash . '/index.html'
            : null;

        return new JsonModel([
            'success' => true,
            'id' => (int) $mediaId,
            'url' => $media->originalUrl(),
            'title' => $media->displayTitle(),
            'filename' => $media->filename(),
            'hasPreview' => $hasPreview,
            'contentPath' => $contentPath,
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

        $rawValue = $request->getPost('teacher_mode_visible', '1');
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

    /**
     * Install or update the static eXeLearning editor.
     *
     * Supports three modes:
     * - Mode A (download_url): JS provides a CORS-friendly URL (e.g. proxy).
     *   PHP downloads it directly. Best for Playground (PHP-WASM) where the
     *   proxy adds CORS headers so PHP's file_get_contents works via browser fetch.
     * - Mode B (file upload): Browser downloaded the ZIP and uploads it here.
     * - Mode C (server download): No file/URL; PHP downloads from GitHub directly.
     *   Works in normal Omeka S deployments.
     *
     * POST /api/exelearning/install-editor
     * - csrf: CSRF token
     * - download_url (optional): CORS-friendly URL to download ZIP from
     * - version (optional): version string
     * - file (optional): uploaded ZIP
     *
     * @return JsonModel
     */
    public function installEditorAction()
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->errorResponse(405, 'Method not allowed');
        }

        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }

        // Validate CSRF token (POST body, query string, or header)
        $csrfToken = $request->getPost('csrf');
        if (!$csrfToken && method_exists($request, 'getQuery')) {
            $csrfToken = $request->getQuery('csrf');
        }
        if (!$csrfToken) {
            $header = $request->getHeaders()->get('X-CSRF-Token');
            if ($header) {
                $csrfToken = $header->getFieldValue();
            }
        }
        if ($csrfToken) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'csrf']);
            if (!$csrf->isValid($csrfToken)) {
                return $this->errorResponse(403, 'CSRF: Invalid or missing CSRF token');
            }
        }

        // Check admin permissions
        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Module', 'update')) {
            return $this->errorResponse(403, 'Forbidden');
        }

        $installer = new StaticEditorInstaller();

        // Query params (used by chunked upload and raw body modes)
        $action = method_exists($request, 'getQuery')
            ? $request->getQuery('action', '')
            : '';
        $qVersion = method_exists($request, 'getQuery')
            ? $request->getQuery('version', 'unknown')
            : 'unknown';
        $uploadId = method_exists($request, 'getQuery')
            ? $request->getQuery('upload_id', '')
            : '';

        $downloadUrl = $request->getPost('download_url');
        $files = $request->getFiles();
        $hasUpload = !empty($files['file']) && $files['file']['error'] === UPLOAD_ERR_OK;

        try {
            // --- Chunked upload (for PHP-WASM where large single requests fail) ---
            if ($action === 'chunk') {
                return $this->handleChunk($request, $uploadId);
            }
            if ($action === 'finalize') {
                return $this->handleFinalize($installer, $uploadId, $qVersion);
            }

            if ($downloadUrl) {
                // PHP downloads from a CORS-friendly URL (e.g. proxy)
                $version = $request->getPost('version', 'unknown');
                $tmpFile = $installer->downloadAsset($downloadUrl);
                try {
                    $result = $installer->installFromFile($tmpFile, $version);
                } finally {
                    @unlink($tmpFile);
                }
            } elseif ($hasUpload) {
                // Browser uploaded the ZIP via multipart
                $version = $request->getPost('version', 'unknown');
                $result = $installer->installFromFile($files['file']['tmp_name'], $version);
            } else {
                // Server downloads from GitHub directly
                $result = $installer->installLatestEditor();
            }

            // Save settings
            $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
            $settings->set(StaticEditorInstaller::SETTING_VERSION, $result['version']);
            $settings->set(StaticEditorInstaller::SETTING_INSTALLED_AT, $result['installed_at']);

            return new JsonModel([
                'success' => true,
                'message' => sprintf('eXeLearning editor v%s installed successfully.', $result['version']),
                'version' => $result['version'],
                'installed_at' => $result['installed_at'],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse(500, $e->getMessage());
        }
    }

    /**
     * Receive a chunk of the editor ZIP and append to a temp file.
     *
     * @codeCoverageIgnore
     */
    private function handleChunk($request, string $uploadId): JsonModel
    {
        if (!$uploadId || !preg_match('/^[a-zA-Z0-9]{8,32}$/', $uploadId)) {
            return $this->errorResponse(400, 'Invalid upload_id');
        }

        $tmpFile = sys_get_temp_dir() . '/exelearning-chunk-' . $uploadId;
        $body = $request->getContent();
        if (empty($body)) {
            return $this->errorResponse(400, 'Empty chunk');
        }

        // Append chunk to temp file
        $written = file_put_contents($tmpFile, $body, FILE_APPEND);
        if ($written === false) {
            return $this->errorResponse(500, 'Failed to write chunk');
        }

        $totalSize = file_exists($tmpFile) ? filesize($tmpFile) : 0;

        return new JsonModel([
            'success' => true,
            'received' => $written,
            'total_size' => $totalSize,
        ]);
    }

    /**
     * Finalize a chunked upload: validate, extract, and install.
     *
     * @codeCoverageIgnore
     */
    private function handleFinalize(
        StaticEditorInstaller $installer,
        string $uploadId,
        string $version
    ): JsonModel {
        if (!$uploadId || !preg_match('/^[a-zA-Z0-9]{8,32}$/', $uploadId)) {
            return $this->errorResponse(400, 'Invalid upload_id');
        }

        $tmpFile = sys_get_temp_dir() . '/exelearning-chunk-' . $uploadId;
        if (!file_exists($tmpFile)) {
            return $this->errorResponse(400, 'No chunks received for this upload_id');
        }

        try {
            $result = $installer->installFromFile($tmpFile, $version);

            $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
            $settings->set(StaticEditorInstaller::SETTING_VERSION, $result['version']);
            $settings->set(StaticEditorInstaller::SETTING_INSTALLED_AT, $result['installed_at']);

            return new JsonModel([
                'success' => true,
                'message' => sprintf('eXeLearning editor v%s installed successfully.', $result['version']),
                'version' => $result['version'],
                'installed_at' => $result['installed_at'],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse(500, $e->getMessage());
        } finally {
            @unlink($tmpFile);
        }
    }
}

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
     * @codeCoverageIgnore
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

        $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
        $status = StaticEditorInstaller::getStoredInstallStatus($settings);
        if ($status['running']) {
            return $this->errorResponse(409, 'An editor installation is already in progress.');
        }

        $startedAt = time();
        StaticEditorInstaller::storeInstallStatus($settings, 'checking', 'Checking latest version...', [
            'started_at' => $startedAt,
            'target_version' => '',
            'success' => false,
            'error' => '',
        ]);

        $installer = (new StaticEditorInstaller())->setStatusCallback(
            function (string $phase, string $message, array $extra = []) use ($settings, $startedAt): void {
                $extra['started_at'] = $startedAt;
                StaticEditorInstaller::storeInstallStatus($settings, $phase, $message, $extra);
            }
        );

        try {
            $result = $installer->installLatestEditor();

            $settings->set(StaticEditorInstaller::SETTING_VERSION, $result['version']);
            $settings->set(StaticEditorInstaller::SETTING_INSTALLED_AT, $result['installed_at']);

            StaticEditorInstaller::storeInstallStatus($settings, 'done', sprintf(
                'eXeLearning editor v%s installed successfully.',
                $result['version']
            ), [
                'started_at' => $startedAt,
                'target_version' => $result['version'],
                'success' => true,
                'error' => '',
            ]);

            return new JsonModel([
                'success' => true,
                'message' => sprintf('eXeLearning editor v%s installed successfully.', $result['version']),
                'version' => $result['version'],
                'installed_at' => $result['installed_at'],
                'status' => $this->buildInstallStatusPayload($settings),
            ]);
        } catch (\Throwable $e) {
            StaticEditorInstaller::storeInstallStatus($settings, 'error', $e->getMessage(), [
                'started_at' => $startedAt,
                'target_version' => '',
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(500, $e->getMessage());
        }
    }

    /**
     * Return the current editor installation status.
     *
     * @codeCoverageIgnore
     */
    public function installEditorStatusAction(): JsonModel
    {
        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }

        $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
        return new JsonModel([
            'success' => true,
            'status' => $this->buildInstallStatusPayload($settings),
        ]);
    }

    /**
     * Build the install status payload used by the admin UI.
     *
     * @codeCoverageIgnore
     */
    private function buildInstallStatusPayload($settings): array
    {
        $stored = StaticEditorInstaller::getStoredInstallStatus($settings);
        $isInstalled = StaticEditorInstaller::isEditorInstalled();
        $version = (string) $settings->get(StaticEditorInstaller::SETTING_VERSION, '');
        $installedAt = (string) $settings->get(StaticEditorInstaller::SETTING_INSTALLED_AT, '');

        if ($stored['stale']) {
            $stored['phase'] = 'error';
            $stored['message'] = 'The previous installation appears to have stalled. Please try again.';
            $stored['error'] = $stored['message'];
            $stored['running'] = false;
        }

        return [
            'phase' => $stored['phase'],
            'message' => $stored['message'],
            'target_version' => $stored['target_version'],
            'running' => $stored['running'],
            'finished' => !$stored['running'] && in_array($stored['phase'], ['done', 'error', 'idle'], true),
            'success' => $stored['success'],
            'error' => $stored['error'],
            'is_installed' => $isInstalled,
            'installed_version' => $version,
            'installed_at' => $installedAt,
            'button_label' => $isInstalled ? 'Update to Latest Version' : 'Download & Install Editor',
            'button_class' => $isInstalled ? 'button' : 'button active',
            'description' => $isInstalled
                ? ''
                : 'The embedded eXeLearning editor is not installed. You can download and install the latest version automatically from GitHub.',
        ];
    }
}

<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\StaticEditorInstaller;

/**
 * Controller for the eXeLearning editor page.
 */
class EditorController extends AbstractActionController
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
     * Display the eXeLearning editor.
     *
     * @return ViewModel|\Laminas\Http\Response
     *
     * @codeCoverageIgnore
     */
    public function editAction()
    {
        $user = $this->identity();
        if (!$user) {
            return $this->redirect()->toRoute('login');
        }

        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->redirect()->toRoute('admin');
        }

        $api = $this->api();
        try {
            $media = $api->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            $this->messenger()->addError('Media not found.');
            return $this->redirect()->toRoute('admin');
        }

        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            $this->messenger()->addError('You do not have permission to edit media.');
            return $this->redirect()->toRoute('admin');
        }

        $filename = $media->filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['elpx', 'zip'])) {
            $this->messenger()->addError('This is not an eXeLearning file.');
            return $this->redirect()->toRoute('admin');
        }

        $editorPath = dirname(__DIR__, 2) . '/dist/static/index.html';
        if (!file_exists($editorPath)) {
            $this->messenger()->addWarning(
                $this->translate('The embedded eXeLearning editor is not installed. Please install it from the module configuration page.') // @translate
            );
            return $this->redirect()->toRoute('admin/default', [
                'controller' => 'module',
                'action' => 'configure',
            ], ['query' => ['id' => 'ExeLearning']]);
        }

        $uri = $this->getRequest()->getUri();
        $port = $uri->getPort();
        $serverUrl = $uri->getScheme() . '://' . $uri->getHost();
        // Include port if it's not the default for the scheme
        if ($port && !(($uri->getScheme() === 'http' && $port == 80) || ($uri->getScheme() === 'https' && $port == 443))) {
            $serverUrl .= ':' . $port;
        }
        $basePath = $this->extractBasePath($uri->getPath());

        $csrf = new \Laminas\Form\Element\Csrf('csrf');
        $csrfToken = $csrf->getValue();

        $config = [
            'mode' => 'OmekaS',
            'mediaId' => (int) $mediaId,
            'elpUrl' => $media->originalUrl(),
            'projectId' => 'omeka-media-' . $mediaId,
            'saveEndpoint' => $serverUrl . $basePath . '/api/exelearning/save/' . $mediaId,
            'editorBaseUrl' => $serverUrl . $basePath . '/modules/ExeLearning/dist/static',
            'csrfToken' => $csrfToken,
            'locale' => substr($this->settings()->get('locale', 'en_US'), 0, 2),
            'userName' => $user->getName(),
            'userId' => $user->getId(),
            'i18n' => [
                'saving' => $this->translate('Saving...'),
                'saved' => $this->translate('Saved successfully'),
                'saveButton' => $this->translate('Save to Omeka'),
                'loading' => $this->translate('Loading project...'),
                'error' => $this->translate('Error'),
                'savingWait' => $this->translate('Please wait while the file is being saved.'),
                'unsavedChanges' => $this->translate('You have unsaved changes. Are you sure you want to close?'),
                'close' => $this->translate('Close'),
            ],
        ];

        $view = new ViewModel([
            'media' => $media,
            'config' => $config,
            'editorBaseUrl' => $config['editorBaseUrl'],
        ]);

        $view->setTemplate('exelearning/editor-bootstrap');
        $view->setTerminal(true);

        return $view;
    }

    /**
     * Derive the Omeka base path from the actual request URI path.
     *
     * Strips everything from the first known Omeka route segment onward.
     * Reliable in PHP-WASM where the full URL path is preserved in the URI.
     *
     * @codeCoverageIgnore
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
     * Index action - redirect to admin.
     *
     * @return \Laminas\Http\Response
     */
    public function indexAction()
    {
        return $this->redirect()->toRoute('admin');
    }

    /**
     * Install or update the static eXeLearning editor.
     *
     * Supports chunked upload (for PHP-WASM), file upload, download_url,
     * or server-side download from GitHub.
     *
     * @return JsonModel
     *
     * @codeCoverageIgnore
     */
    public function installEditorAction()
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->jsonError(405, 'Method not allowed');
        }

        if (!$this->identity()) {
            return $this->jsonError(401, 'Unauthorized');
        }

        // Validate CSRF token (POST body, query string, or header)
        $csrfToken = $request->getPost('csrf');
        if (!$csrfToken && method_exists($request, 'getQuery')) {
            $csrfToken = $request->getQuery('csrf');
        }
        if (!$csrfToken) {
            $header = $request->getHeaders()->get('X-CSRF-Token');
            if ($header && $header !== false) {
                $csrfToken = $header->getFieldValue();
            }
        }
        if ($csrfToken) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'csrf']);
            if (!$csrf->isValid($csrfToken)) {
                return $this->jsonError(403, 'CSRF: Invalid or missing CSRF token');
            }
        }

        $installer = new StaticEditorInstaller();

        // Query params (used by chunked upload)
        $qAction = method_exists($request, 'getQuery')
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
            // Chunked upload
            if ($qAction === 'chunk') {
                return $this->handleChunk($request, $uploadId);
            }
            if ($qAction === 'finalize') {
                return $this->handleFinalize($installer, $uploadId, $qVersion);
            }

            $contentType = $request->getHeaders()->get('Content-Type');
            if ($contentType && strpos($contentType->getFieldValue(), 'application/octet-stream') !== false) {
                // Raw binary body
                $tmpFile = tempnam(sys_get_temp_dir(), 'exelearning-upload-');
                $body = $request->getContent();
                if (empty($body)) {
                    @unlink($tmpFile);
                    return $this->jsonError(400, 'Empty request body');
                }
                if (file_put_contents($tmpFile, $body) === false) {
                    @unlink($tmpFile);
                    return $this->jsonError(500, 'Failed to write request body to temp file');
                }
                try {
                    $result = $installer->installFromFile($tmpFile, $qVersion);
                } finally {
                    @unlink($tmpFile);
                }
            } elseif ($downloadUrl) {
                $version = $request->getPost('version', 'unknown');
                $tmpFile = $installer->downloadAsset($downloadUrl);
                try {
                    $result = $installer->installFromFile($tmpFile, $version);
                } finally {
                    @unlink($tmpFile);
                }
            } elseif ($hasUpload) {
                $version = $request->getPost('version', 'unknown');
                $result = $installer->installFromFile($files['file']['tmp_name'], $version);
            } else {
                $result = $installer->installLatestEditor();
            }

            $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
            $settings->set(StaticEditorInstaller::SETTING_VERSION, $result['version']);
            $settings->set(StaticEditorInstaller::SETTING_INSTALLED_AT, $result['installed_at']);

            return $this->jsonResponse([
                'success' => true,
                'message' => sprintf('eXeLearning editor v%s installed successfully.', $result['version']),
                'version' => $result['version'],
                'installed_at' => $result['installed_at'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError(500, $e->getMessage());
        }
    }

    /**
     * @codeCoverageIgnore
     */
    private function handleChunk($request, string $uploadId): \Laminas\Http\Response
    {
        if (!$uploadId || !preg_match('/^[a-zA-Z0-9]{8,32}$/', $uploadId)) {
            return $this->jsonError(400, 'Invalid upload_id');
        }

        $tmpFile = sys_get_temp_dir() . '/exelearning-chunk-' . $uploadId;
        $body = $request->getContent();
        if (empty($body)) {
            return $this->jsonError(400, 'Empty chunk');
        }

        $written = file_put_contents($tmpFile, $body, FILE_APPEND);
        if ($written === false) {
            return $this->jsonError(500, 'Failed to write chunk');
        }

        return $this->jsonResponse([
            'success' => true,
            'received' => $written,
            'total_size' => file_exists($tmpFile) ? filesize($tmpFile) : 0,
        ]);
    }

    /**
     * @codeCoverageIgnore
     */
    private function handleFinalize(
        StaticEditorInstaller $installer,
        string $uploadId,
        string $version
    ): \Laminas\Http\Response {
        if (!$uploadId || !preg_match('/^[a-zA-Z0-9]{8,32}$/', $uploadId)) {
            return $this->jsonError(400, 'Invalid upload_id');
        }

        $tmpFile = sys_get_temp_dir() . '/exelearning-chunk-' . $uploadId;
        if (!file_exists($tmpFile)) {
            return $this->jsonError(400, 'No chunks received for this upload_id');
        }

        try {
            $result = $installer->installFromFile($tmpFile, $version);

            $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
            $settings->set(StaticEditorInstaller::SETTING_VERSION, $result['version']);
            $settings->set(StaticEditorInstaller::SETTING_INSTALLED_AT, $result['installed_at']);

            return $this->jsonResponse([
                'success' => true,
                'message' => sprintf('eXeLearning editor v%s installed successfully.', $result['version']),
                'version' => $result['version'],
                'installed_at' => $result['installed_at'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError(500, $e->getMessage());
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Return a JSON response directly, bypassing the admin view layer.
     * Admin routes use ViewModel rendering which breaks JsonModel.
     *
     * @codeCoverageIgnore
     */
    private function jsonResponse(array $data, int $statusCode = 200): \Laminas\Http\Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode($data));
        return $response;
    }

    /**
     * @codeCoverageIgnore
     */
    private function jsonError(int $statusCode, string $message): \Laminas\Http\Response
    {
        return $this->jsonResponse(['success' => false, 'message' => $message], $statusCode);
    }
}

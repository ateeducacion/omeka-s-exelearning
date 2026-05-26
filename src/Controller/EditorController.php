<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\StaticEditorInstaller;
use ExeLearning\Service\StylesService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Controller for the eXeLearning editor page.
 */
class EditorController extends AbstractActionController
{
    /** @var ElpFileService */
    protected $elpService;

    /** @var StylesService|null */
    protected $stylesService;

    /**
     * @param ElpFileService    $elpService
     * @param StylesService|null $stylesService Optional — when absent the
     *        editor bootstrap omits the themeRegistryOverride payload.
     */
    public function __construct(ElpFileService $elpService, ?StylesService $stylesService = null)
    {
        $this->elpService = $elpService;
        $this->stylesService = $stylesService;
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

        // Build the approved style registry the editor will consume via
        // window.eXeLearning.config.themeRegistryOverride (see core PR
        // exelearning/exelearning#1722). Absolute URLs so the editor
        // can fetch style assets from the public serve route.
        $themeRegistryOverride = $this->stylesService
            ? $this->stylesService->buildThemeRegistryOverride($serverUrl . $basePath)
            : [
                'disabledBuiltins' => [],
                'uploaded' => [],
                'blockImportInstall' => false,
                'fallbackTheme' => 'base',
            ];

        $view = new ViewModel([
            'media' => $media,
            'config' => $config,
            'editorBaseUrl' => $config['editorBaseUrl'],
            'themeRegistryOverride' => $themeRegistryOverride,
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
     * Public export-only bootstrap. Served anonymously so the multi-format
     * download split-button (rendered on item show pages) can lazy-load the
     * static editor inside a hidden iframe and run
     * `SharedExporters.quickExport()` for a given media.
     *
     * The bootstrap exposes only the protocol needed for export — no save
     * endpoint, no user data, no CSRF token. Authorization mirrors the
     * media's public visibility (Omeka enforces that on originalUrl()).
     *
     * @return ViewModel|\Laminas\Http\Response
     *
     * @codeCoverageIgnore
     */
    public function exportAction()
    {
        $mediaId = (int) $this->params()->fromQuery('media_id', 0);
        if (!$mediaId) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            $response->setContent('Missing media_id');
            return $response;
        }

        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            $response = $this->getResponse();
            $response->setStatusCode(404);
            $response->setContent('Media not found');
            return $response;
        }

        $filename = $media->filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['elpx', 'zip'])) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            $response->setContent('Not an eXeLearning file');
            return $response;
        }

        $editorPath = dirname(__DIR__, 2) . '/dist/static/index.html';
        if (!file_exists($editorPath)) {
            $response = $this->getResponse();
            $response->setStatusCode(503);
            $response->setContent('Static eXeLearning editor not installed.');
            return $response;
        }

        $uri = $this->getRequest()->getUri();
        $port = $uri->getPort();
        $serverUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($port && !(($uri->getScheme() === 'http' && $port == 80) || ($uri->getScheme() === 'https' && $port == 443))) {
            $serverUrl .= ':' . $port;
        }
        $basePath = $this->extractBasePath($uri->getPath());

        $config = [
            'mode' => 'OmekaSExport',
            'mediaId' => $mediaId,
            'elpUrl' => $media->originalUrl(),
            'projectId' => 'omeka-export-' . $mediaId,
            'editorBaseUrl' => $serverUrl . $basePath . '/modules/ExeLearning/dist/static',
            'exportOnly' => true,
            'i18n' => [
                'loading' => $this->translate('Loading project...'),
                'error' => $this->translate('Error'),
            ],
        ];

        $view = new ViewModel([
            'media' => $media,
            'config' => $config,
            'editorBaseUrl' => $config['editorBaseUrl'],
            'themeRegistryOverride' => [
                'disabledBuiltins' => [],
                'uploaded' => [],
                'blockImportInstall' => false,
                'fallbackTheme' => 'base',
            ],
        ]);
        $view->setTemplate('exelearning/editor-bootstrap');
        $view->setTerminal(true);
        return $view;
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
     * @return \Laminas\Http\Response
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

        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $status = StaticEditorInstaller::getStoredInstallStatus($settings);
        if ($status['running']) {
            return $this->jsonError(409, 'An editor installation is already in progress.');
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

            return $this->jsonResponse([
                'success' => true,
                'message' => sprintf('eXeLearning editor v%s installed successfully.', $result['version']),
                'version' => $result['version'],
                'installed_at' => $result['installed_at'],
                'status' => $this->buildInstallStatusPayload($settings),
            ]);
        } catch (\Throwable $e) {
            StaticEditorInstaller::storeInstallStatus($settings, 'error', $e->getMessage(), [
                'started_at' => $startedAt,
                'target_version' => $status['target_version'] ?? '',
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            return $this->jsonError(500, $e->getMessage(), $this->buildInstallStatusPayload($settings));
        }
    }

    /**
     * Return the current editor installation status.
     *
     * @return \Laminas\Http\Response
     *
     * @codeCoverageIgnore
     */
    public function installEditorStatusAction()
    {
        if (!$this->identity()) {
            return $this->jsonError(401, 'Unauthorized');
        }

        $settings = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Settings');
        return $this->jsonResponse([
            'success' => true,
            'status' => $this->buildInstallStatusPayload($settings),
        ]);
    }

    /**
     * Build the install status payload used by the admin UI.
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
    private function jsonError(int $statusCode, string $message, array $extra = []): \Laminas\Http\Response
    {
        return $this->jsonResponse(array_merge(['success' => false, 'message' => $message], $extra), $statusCode);
    }
}

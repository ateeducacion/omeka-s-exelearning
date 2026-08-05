<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\EditorBundle;
use ExeLearning\Service\StylesService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Controller for the eXeLearning editor page.
 */
class EditorController extends AbstractActionController
{
    use CsrfValidationTrait;

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
            $this->messenger()->addError($this->translate('Media not found.')); // @translate
            return $this->redirect()->toRoute('admin');
        }

        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            $this->messenger()->addError($this->translate('You do not have permission to edit media.')); // @translate
            return $this->redirect()->toRoute('admin');
        }

        $filename = $media->filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['elpx', 'zip'])) {
            $this->messenger()->addError($this->translate('This is not an eXeLearning file.')); // @translate
            return $this->redirect()->toRoute('admin');
        }

        if (!EditorBundle::isAvailable()) {
            // The editor ships inside the module package and is never
            // downloaded at runtime (ADR-28-01).
            $this->messenger()->addWarning(
                $this->translate('This installation does not include the embedded editor, so editing eXeLearning content is disabled. Official release packages include it; development checkouts must build it with "make build-editor".') // @translate
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
        $basePath = $this->resolveBasePath($this->getRequest());

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
                'waiting' => $this->translate('Waiting for editor...'),
                'downloading' => $this->translate('Downloading file...'),
                'importing' => $this->translate('Importing content...'),
                'errorLoading' => $this->translate('Error loading project'),
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
            // Normalized HTTP preview transport config (serving contract v2 §1).
            // The dedicated long-lived preview CSRF token rides managementHeaders
            // so publishing survives a whole editing session (see PreviewCsrf).
            'previewSnapshot' => $this->buildPreviewSnapshotConfig($serverUrl . $basePath, PreviewCsrf::mint()),
        ]);

        $view->setTemplate('exelearning/editor-bootstrap');
        $view->setTerminal(true);

        return $view;
    }

    /**
     * Build the `previewSnapshot` block the embedded editor reads from
     * `window.__EXE_EMBEDDING_CONFIG__` to drive the opaque preview. The editor
     * POSTs the whole project as one ZIP to the management URL and loads the
     * capability URL it gets back. All URLs derive from the same
     * `serverUrl + basePath` origin as `saveEndpoint`, so a subdirectory or
     * php-wasm playground install resolves them identically. The CSRF token is
     * sent (via `managementHeaders`) on every management request.
     *
     * deleteUrlTemplate is not optional: the editor's default delete target
     * appends /{previewId} to managementUrl, which happens to be right here, but
     * stating it keeps the wire contract explicit rather than relying on that
     * coincidence.
     *
     * @param string $origin    serverUrl + basePath (no trailing slash).
     * @param string $csrfToken Long-lived preview CSRF token (PreviewCsrf::mint()).
     * @return array
     */
    protected function buildPreviewSnapshotConfig(string $origin, string $csrfToken): array
    {
        $management = $origin . '/api/exelearning/preview-session';
        return [
            'managementUrl' => $management,
            'servingBaseUrl' => $origin . '/exelearning/preview',
            'deleteUrlTemplate' => $management . '/{previewId}',
            'managementHeaders' => ['X-CSRF-Token' => $csrfToken],
        ];
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
        // Strip from the marker that appears EARLIEST in the path, not the
        // first one in this list.
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
     * Resolve the Omeka base path for a request.
     *
     * Prefers the URI marker (reliable under php-wasm where getBasePath() is
     * unreliable). For a route with no known marker — notably the public
     * `/exelearning/export` bootstrap — falls back to the framework's mounted
     * base path so the editor still loads from the right prefix on a
     * subdirectory install.
     *
     * @param object $request
     * @return string
     */
    protected function resolveBasePath($request): string
    {
        $fromUri = $this->extractBasePath($request->getUri()->getPath());
        if ($fromUri !== '') {
            return $fromUri;
        }
        return method_exists($request, 'getBasePath') ? (string) $request->getBasePath() : '';
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

        if (!EditorBundle::isAvailable()) {
            $response = $this->getResponse();
            $response->setStatusCode(503);
            $response->setContent('The bundled eXeLearning editor is not available.');
            return $response;
        }

        $uri = $this->getRequest()->getUri();
        $port = $uri->getPort();
        $serverUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($port && !(($uri->getScheme() === 'http' && $port == 80) || ($uri->getScheme() === 'https' && $port == 443))) {
            $serverUrl .= ':' . $port;
        }
        $basePath = $this->resolveBasePath($this->getRequest());

        $config = [
            'mode' => 'OmekaSExport',
            'mediaId' => $mediaId,
            'elpUrl' => $media->originalUrl(),
            'projectId' => 'omeka-export-' . $mediaId,
            'editorBaseUrl' => $serverUrl . $basePath . '/modules/ExeLearning/dist/static',
            'exportOnly' => true,
            'i18n' => [
                'loading' => $this->translate('Loading project...'),
                'waiting' => $this->translate('Waiting for editor...'),
                'downloading' => $this->translate('Downloading file...'),
                'importing' => $this->translate('Importing content...'),
                'errorLoading' => $this->translate('Error loading project'),
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
}

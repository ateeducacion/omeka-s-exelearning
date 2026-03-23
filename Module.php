<?php
declare(strict_types=1);

namespace ExeLearning;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;
use ExeLearning\Form\ConfigForm;
use ExeLearning\Service\StaticEditorInstaller;

/**
 * Main class for the ExeLearning module.
 *
 * Allows uploading, viewing and editing eXeLearning content (.elpx files) in Omeka S.
 */
class Module extends AbstractModule
{
    /** @var string */
    const NAMESPACE = __NAMESPACE__;

    /**
     * Retrieve the configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Execute logic when the module is installed.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $messenger = new Messenger();
        $message = new Message("ExeLearning module installed.");
        $messenger->addSuccess($message);

        // Register eXeLearning file types
        $this->updateWhitelist($serviceLocator);

        // Create data directory for extracted content
        $this->createDataDirectory();
    }

    /**
     * Register eXeLearning file types in Omeka settings.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    protected function updateWhitelist(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');

        // Register MIME types for ZIP files
        $whitelist = $settings->get('media_type_whitelist', []);
        $whitelist = array_values(array_unique(array_merge(array_values($whitelist), [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ])));
        $settings->set('media_type_whitelist', $whitelist);

        // Register .elpx extension
        $whitelist = $settings->get('extension_whitelist', []);
        $whitelist = array_values(array_unique(array_merge(array_values($whitelist), [
            'elpx',
            'zip',
        ])));
        $settings->set('extension_whitelist', $whitelist);
    }

    /**
     * Create the data directory for extracted eXeLearning content.
     */
    protected function createDataDirectory(): void
    {
        $basePath = $this->getDataPath();
        if (!is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }
    }

    /**
     * Get the path to the data directory.
     *
     * @return string
     */
    public function getDataPath(): string
    {
        return __DIR__ . '/data/exelearning';
    }

    /**
     * Execute logic when the module is uninstalled.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $messenger = new Messenger();
        $message = new Message("ExeLearning module uninstalled.");
        $messenger->addWarning($message);
    }

    /**
     * Attach event listeners.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Listen for media hydration to set the correct renderer
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            [$this, 'handleMediaHydrate']
        );

        // Listen for media creation to process eXeLearning files
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.post',
            [$this, 'handleMediaCreate']
        );

        // Listen for media deletion to clean up extracted content
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.delete.pre',
            [$this, 'handleMediaDelete']
        );

        // Inject iframe viewer in admin media show page
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.after',
            [$this, 'handleAdminMediaShow']
        );

        // Add thumbnail script to admin pages
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'handleViewLayout']
        );

        // Inject iframe viewer in public item show page
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handlePublicItemShow']
        );
    }

    /**
     * Handle public item show view - inject iframe viewer for eXeLearning media.
     *
     * @param Event $event
     */
    public function handlePublicItemShow(Event $event)
    {
        $view = $event->getTarget();
        $item = $view->item;

        if (!$item) {
            return;
        }

        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $elpService = $services->get(Service\ElpFileService::class);

        // Find eXeLearning media in this item
        foreach ($item->media() as $media) {
            if (!$this->isExeLearningFile($media)) {
                continue;
            }

            $hash = $elpService->getMediaHash($media);
            $hasPreview = $elpService->hasPreview($media);

            // Auto-process if not yet extracted
            if (!$hash || !$hasPreview) {
                $logger->info(sprintf('[ExeLearning] Auto-processing media %d on public view', $media->id()));
                try {
                    $result = $elpService->processUploadedFile($media);
                    $hash = $result['hash'];
                    $hasPreview = $result['hasPreview'];
                } catch (\Throwable $e) {
                    $logger->err(sprintf('[ExeLearning] Auto-process failed: %s', $e->getMessage()));
                    continue;
                }
            }

            if (!$hash || !$hasPreview) {
                continue;
            }

            // Pass the relative content path; JS constructs the full URL from
            // window.location so the playground SW scope prefix is always included.
            $contentPath = '/exelearning/content/' . $hash . '/index.html';
            if (!$this->isTeacherModeVisible($media)) {
                $contentPath .= '?teacher_mode_visible=0';
            }

            echo $view->partial('exelearning/public/item-show', [
                'media' => $media,
                'contentPath' => $contentPath,
            ]);
        }
    }

    /**
     * Handle admin media show view - inject iframe viewer for eXeLearning files.
     *
     * @param Event $event
     */
    public function handleAdminMediaShow(Event $event)
    {
        $view = $event->getTarget();
        $media = $view->resource;

        if (!$this->isExeLearningFile($media)) {
            return;
        }

        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $elpService = $services->get(Service\ElpFileService::class);

        $hash = $elpService->getMediaHash($media);
        $hasPreview = $elpService->hasPreview($media);

        // Auto-process if not yet extracted
        if (!$hash || !$hasPreview) {
            $logger->info(sprintf('[ExeLearning] Auto-processing media %d on view', $media->id()));
            try {
                $result = $elpService->processUploadedFile($media);
                $hash = $result['hash'];
                $hasPreview = $result['hasPreview'];
                $logger->info(sprintf('[ExeLearning] Auto-process complete: hash=%s, hasPreview=%s', $hash, $hasPreview ? 'yes' : 'no'));
            } catch (\Throwable $e) {
                $logger->err(sprintf('[ExeLearning] Auto-process failed: %s', $e->getMessage()));
                return;
            }
        }

        if (!$hash || !$hasPreview) {
            return;
        }

        // Pass the relative content path; JS constructs the full URL from
        // window.location so the playground SW scope prefix is always included.
        $contentPath = '/exelearning/content/' . $hash . '/index.html';
        if (!$this->isTeacherModeVisible($media)) {
            $contentPath .= '?teacher_mode_visible=0';
        }

        echo $view->partial('exelearning/admin/media-show', [
            'media' => $media,
            'contentPath' => $contentPath,
        ]);
    }

    /**
     * Handle view layout - add thumbnail replacement script to admin pages.
     *
     * @param Event $event
     */
    public function handleViewLayout(Event $event)
    {
        $view = $event->getTarget();

        // Only add to admin pages
        $routeMatch = $this->getServiceLocator()->get('Application')->getMvcEvent()->getRouteMatch();
        if (!$routeMatch) {
            return;
        }

        $routeName = $routeMatch->getMatchedRouteName();
        if (strpos($routeName, 'admin') !== 0) {
            return;
        }

        // Add the thumbnail URL as a data attribute and load the script
        $basePath = $view->basePath();
        $thumbnailUrl = $basePath . '/modules/ExeLearning/asset/thumbnails/elpx.png';
        $scriptUrl = $basePath . '/modules/ExeLearning/asset/js/exelearning-thumbnail.js';

        // Get item IDs that contain eXeLearning media
        $exeItemIds = $this->getExeLearningItemIds();

        $view->headScript()->appendFile($scriptUrl);
        $view->headScript()->appendScript(
            'document.documentElement.setAttribute("data-exelearning-thumbnail", "' . $thumbnailUrl . '");' .
            'window.exelearningItemIds = ' . json_encode($exeItemIds) . ';'
        );

        // Robust injection of Teacher Mode setting into admin media edit form.
        $label = $view->escapeJs($view->translate('Show Teacher Mode toggler'));
        $visibleLabel = $view->escapeJs($view->translate('Visible in inserted resource'));
        $help = $view->escapeJs($view->translate('If disabled, the Teacher Mode toggler is hidden in the embedded eXeLearning content.'));
        $apiBase = $basePath . '/api/exelearning';
        $view->headScript()->appendScript(<<<JS
(function() {
    function isExeFilename(filename) {
        if (!filename) {
            return false;
        }
        var lower = String(filename).toLowerCase();
        return lower.endsWith('.elpx') || lower.endsWith('.zip');
    }

    function getMediaIdFromPath() {
        var match = window.location.pathname.match(/\\/admin\\/media\\/(\\d+)/);
        return match ? match[1] : null;
    }

    function injectField(checked) {
        if (document.getElementById('exelearning-teacher-mode-field')) {
            return;
        }
        var form = document.querySelector('form#edit-media');
        if (!form) {
            return;
        }

        var target = document.querySelector('#advanced-settings') ||
            document.querySelector('#resource-values') ||
            form;

        var wrapper = document.createElement('div');
        wrapper.className = 'field';
        wrapper.id = 'exelearning-teacher-mode-field';
        wrapper.innerHTML =
            '<div class="field-meta">' +
                '<label for="exelearning-teacher-mode-visible">{$label}</label>' +
            '</div>' +
            '<div class="inputs">' +
                '<input type="hidden" name="exelearning_teacher_mode_visible" value="0">' +
                '<label>' +
                    '<input type="checkbox" id="exelearning-teacher-mode-visible" name="exelearning_teacher_mode_visible" value="1" ' + (checked ? 'checked' : '') + '> {$visibleLabel}' +
                '</label>' +
                '<p class="field-description">{$help}</p>' +
            '</div>';

        target.appendChild(wrapper);
    }

    function init() {
        var form = document.querySelector('form#edit-media');
        if (!form) {
            return;
        }

        var mediaId = getMediaIdFromPath();
        if (!mediaId) {
            return;
        }

        fetch('{$apiBase}/elp-data/' + mediaId, {credentials: 'same-origin'})
            .then(function(resp) {
                if (!resp.ok) {
                    throw new Error('data endpoint unavailable');
                }
                return resp.json();
            })
            .then(function(data) {
                if (!data || !data.success || !isExeFilename(data.filename)) {
                    return;
                }
                injectField(data.teacherModeVisible !== false);
            })
            .catch(function() {
                // Fallback: use current page title as heuristic.
                var title = document.querySelector('h1 .title');
                if (title && isExeFilename(title.textContent || '')) {
                    injectField(true);
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS
        );
    }

    /**
     * Get IDs of items that contain eXeLearning media.
     *
     * @return array
     */
    protected function getExeLearningItemIds(): array
    {
        $services = $this->getServiceLocator();

        try {
            $connection = $services->get('Omeka\Connection');

            // Query for item IDs that have media with .elpx extension
            $sql = "SELECT DISTINCT m.item_id
                    FROM media m
                    WHERE m.source LIKE '%.elpx'
                       OR m.source LIKE '%.elp'";

            $stmt = $connection->query($sql);
            $results = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return array_map('intval', $results);
        } catch (\Throwable $e) {
            $logger = $services->get('Omeka\Logger');
            $logger->err(sprintf('[ExeLearning] Failed to get item IDs: %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Handle media hydration - set the correct renderer for eXeLearning files.
     *
     * @param Event $event
     */
    public function handleMediaHydrate(Event $event)
    {
        $entity = $event->getParam('entity');

        // Get the filename from the entity
        $filename = null;
        if (method_exists($entity, 'getFilename')) {
            $filename = $entity->getFilename();
        } elseif (method_exists($entity, 'getSource')) {
            $filename = $entity->getSource();
        }

        if (!$filename) {
            return;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Set our renderer for eXeLearning files
        if (in_array($extension, ['elpx', 'zip'])) {
            if (method_exists($entity, 'setRenderer')) {
                $entity->setRenderer('exelearning_renderer');
            }

            // Persist custom eXeLearning media settings from admin edit form.
            $request = $event->getParam('request');
            if ($request && method_exists($request, 'getContent')) {
                $content = $request->getContent();
                if (is_array($content) && array_key_exists('exelearning_teacher_mode_visible', $content)) {
                    $rawValue = $content['exelearning_teacher_mode_visible'];
                    if (is_array($rawValue)) {
                        $rawValue = end($rawValue);
                    }
                    $visible = !in_array((string) $rawValue, ['0', 'false', 'no', 'off', ''], true);

                    if (method_exists($entity, 'getData') && method_exists($entity, 'setData')) {
                        $data = $entity->getData() ?? [];
                        $data['exelearning_teacher_mode_visible'] = $visible ? '1' : '0';
                        $entity->setData($data);
                    }
                }
            }
        }
    }

    /**
     * Handle media creation event.
     * Process uploaded eXeLearning files.
     *
     * @param Event $event
     */
    public function handleMediaCreate(Event $event)
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $response = $event->getParam('response');
        $entity = $response->getContent();

        // The api.create.post event provides an Entity, not a Representation.
        // Convert to Representation via API read for consistent method calls.
        $mediaId = $entity->getId();
        $logger->info(sprintf('ExeLearning: handleMediaCreate called for media %d', $mediaId));

        try {
            $media = $services->get('Omeka\ApiManager')
                ->read('media', $mediaId)->getContent();
        } catch (\Throwable $e) {
            $logger->err(sprintf(
                'ExeLearning: Could not load media representation for %d: %s',
                $mediaId,
                $e->getMessage()
            ));
            return;
        }

        $logger->info(sprintf('ExeLearning: Media filename: %s', $media->filename() ?? 'null'));

        // Check if this is an eXeLearning file
        if (!$this->isExeLearningFile($media)) {
            $logger->info('ExeLearning: Not an eXeLearning file, skipping');
            return;
        }

        $logger->info('ExeLearning: Processing eXeLearning file');

        try {
            $elpService = $services->get(Service\ElpFileService::class);
            $result = $elpService->processUploadedFile($media);
            $logger->info(sprintf(
                'ExeLearning: File processed successfully. Hash: %s, HasPreview: %s',
                $result['hash'],
                $result['hasPreview'] ? 'yes' : 'no'
            ));
        } catch (\Throwable $e) {
            $logger->err(sprintf(
                'ExeLearning: Failed to process uploaded file for media %d: %s',
                $mediaId,
                $e->getMessage()
            ));
            $logger->err('ExeLearning: Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Handle media deletion event.
     * Clean up extracted content.
     *
     * @param Event $event
     */
    public function handleMediaDelete(Event $event)
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        try {
            // Get the entity directly from the event, not via API
            $entity = $event->getParam('entity');
            if (!$entity) {
                return;
            }

            $mediaId = $entity->getId();
            $filename = $entity->getFilename();

            if (!$filename) {
                return;
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($extension, ['elpx', 'zip'])) {
                return;
            }

            $logger->info(sprintf('ExeLearning: Cleaning up media %d', $mediaId));

            // Get the hash from entity data
            $data = $entity->getData();
            $hash = $data['exelearning_extracted_hash'] ?? null;

            if ($hash) {
                $basePath = $this->getDataPath();
                $extractPath = $basePath . '/' . $hash;
                $this->deleteDirectory($extractPath);
                $logger->info(sprintf('ExeLearning: Deleted extracted content at %s', $extractPath));
            }
        } catch (\Throwable $e) {
            $logger->err(sprintf(
                'ExeLearning: Failed to cleanup media: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir
     */
    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Check if a media item is an eXeLearning file.
     *
     * @param mixed $media
     * @return bool
     */
    /**
     * Build an absolute content proxy URL for the given hash.
     *
     * Derives the base path from the actual request URI path so that the
     * playground prefix (/playground/{uuid}/php83/) is correctly included
     * even in PHP-WASM environments where $_SERVER['SCRIPT_NAME'] does not
     * contain it (making getBasePath() unreliable).
     */
    protected function buildContentUrl(string $hash): string
    {
        $request = $this->getServiceLocator()->get('Request');
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
     * Strips everything from the first known Omeka route segment onward
     * (/admin/, /s/, /api/). This is reliable in PHP-WASM playgrounds where
     * the full URL path (e.g. /playground/{uuid}/php83/admin/...) is preserved
     * in the request URI even when $_SERVER['SCRIPT_NAME'] is not.
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
     * Check if teacher mode toggler should be visible for a media resource.
     */
    protected function isTeacherModeVisible($media): bool
    {
        $data = $media->mediaData();
        if (!isset($data['exelearning_teacher_mode_visible'])) {
            return true;
        }

        $value = $data['exelearning_teacher_mode_visible'];
        return !in_array((string) $value, ['0', 'false', 'no'], true);
    }

    protected function isExeLearningFile($media): bool
    {
        $filename = $media->filename();
        if (!$filename) {
            return false;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, ['elpx', 'zip']);
    }

    /**
     * Get the configuration form for this module.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $form = new ConfigForm;
        $form->init();

        $form->setData([
            'exelearning_viewer_height' => $settings->get('exelearning_viewer_height', 600),
            'exelearning_show_edit_button' => $settings->get('exelearning_show_edit_button', true) ? '1' : '0',
        ]);

        $formHtml = $renderer->formCollection($form, false);

        return $this->renderEditorStatusSection($renderer, $settings) . $formHtml;
    }

    /**
     * Render the embedded editor status and install section.
     *
     * @param PhpRenderer $renderer
     * @param mixed $settings Omeka settings service
     * @return string
     */
    protected function renderEditorStatusSection(PhpRenderer $renderer, $settings): string
    {
        $isInstalled = StaticEditorInstaller::isEditorInstalled();
        $version = $settings->get(StaticEditorInstaller::SETTING_VERSION, '');
        $installedAt = $settings->get(StaticEditorInstaller::SETTING_INSTALLED_AT, '');

        $translate = function ($text) use ($renderer) {
            return $renderer->translate($text);
        };

        // Get CSRF token from the page form element.
        $csrf = new \Laminas\Form\Element\Csrf('csrf');
        $csrfValue = $csrf->getValue();

        $html = '<fieldset id="exelearning-editor-status">';
        $html .= '<legend>' . $renderer->escapeHtml($translate('Embedded Editor')) . '</legend>'; // @translate

        // Status display
        $html .= '<div class="field"><div class="field-meta">';
        $html .= '<label>' . $renderer->escapeHtml($translate('Status')) . '</label>'; // @translate
        $html .= '</div><div class="inputs">';
        $html .= '<span id="exelearning-status-icon">';
        if ($isInstalled) {
            $html .= '<span style="color: #46b450;">&#10003;</span> ';
            $html .= $renderer->escapeHtml($translate('Installed')); // @translate
            if ($version) {
                $html .= ' &mdash; v' . $renderer->escapeHtml($version);
            }
            if ($installedAt) {
                $html .= ' (' . $renderer->escapeHtml($translate('installed on'));  // @translate
                $html .= ' ' . $renderer->escapeHtml($installedAt) . ')';
            }
        } else {
            $html .= '<span style="color: #dc3232;">&#10007;</span> ';
            $html .= $renderer->escapeHtml($translate('Not installed')); // @translate
        }
        $html .= '</span>';
        $html .= '</div></div>';

        // Install/update button + status area
        $html .= '<div class="field"><div class="field-meta"></div><div class="inputs">';
        if (!$isInstalled) {
            $html .= '<p>';
            $html .= $renderer->escapeHtml($translate( // @translate
                'The embedded eXeLearning editor is not installed.'
                . ' You can download and install the latest version automatically from GitHub.'
            ));
            $html .= '</p>';
        }

        $buttonLabel = $isInstalled
            ? $renderer->escapeHtml($translate('Update to Latest Version')) // @translate
            : $renderer->escapeHtml($translate('Download & Install Editor')); // @translate
        $buttonClass = $isInstalled ? 'button' : 'button active';
        $html .= '<button type="button" id="exelearning-install-btn" class="' . $buttonClass . '">';
        $html .= $buttonLabel;
        $html .= '</button>';

        // Progress area (hidden by default)
        $html .= '<div id="exelearning-install-progress" style="display: none; margin-top: 10px;">';
        $html .= '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">';
        $html .= '<span class="o-icon-transmit" aria-hidden="true"></span>';
        $html .= '<span id="exelearning-install-message"></span>';
        $html .= '</div>';
        $html .= '<div style="background: #e0e0e0; border-radius: 4px; height: 8px; width: 100%; max-width: 400px; overflow: hidden;">';
        $html .= '<div id="exelearning-install-bar" style="background: #087cb8; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 4px;"></div>';
        $html .= '</div>';
        $html .= '</div>';

        // Result area (hidden by default)
        $html .= '<div id="exelearning-install-result" style="display: none; margin-top: 10px;"></div>';

        $html .= '</div></div>';

        $html .= '<div class="field"><div class="field-meta"></div><div class="inputs">';
        $html .= '<p class="explanation">';
        $html .= sprintf(
            $renderer->escapeHtml($translate('Developers can also build the editor from source using %s.')), // @translate
            '<code>make build-editor</code>'
        );
        $html .= '</p>';
        $html .= '</div></div>';

        $html .= '</fieldset>';

        // Configuration for the external installer JS
        $installUrl = $renderer->serverUrl() . $renderer->basePath() . '/admin/exelearning/install-editor';
        $jsConfig = [
            'installUrl' => $installUrl,
            'csrfToken' => $csrfValue,
            'githubApiUrl' => StaticEditorInstaller::GITHUB_API_URL,
            'jsdelivrApiUrl' => StaticEditorInstaller::JSDELIVR_API_URL,
            'assetPrefix' => StaticEditorInstaller::ASSET_PREFIX,
            'strings' => [
                'pleaseWait' => $translate('Please wait...'), // @translate
                'discovering' => $translate('Checking latest version...'), // @translate
                'installing' => $translate('Installing editor...'), // @translate
                'downloading' => $translate('Downloading editor...'), // @translate
                'downloadingProgress' => $translate('Downloading... {downloaded} MB / {total} MB'), // @translate
                'error' => $translate('Installation failed.'), // @translate
                'networkError' => $translate('Network error. Please check your connection and try again.'), // @translate
                'downloadFailed' => $translate('Could not download the editor. All download sources failed.'), // @translate
                'tryAgain' => $translate('Try Again'), // @translate
            ],
        ];

        $html .= '<script>window.exelearningInstaller = ' . json_encode($jsConfig) . ';</script>';
        $html .= '<script src="' . $renderer->escapeHtmlAttr($renderer->assetUrl('js/exelearning-installer.js', 'ExeLearning'))
            . '"></script>';

        return $html;
    }

    /**
     * Handle the configuration form submission.
     *
     * @param AbstractController $controller
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $config = $controller->params()->fromPost();

        $settings->set(
            'exelearning_viewer_height',
            (int) ($config['exelearning_viewer_height'] ?? 600)
        );
        $settings->set(
            'exelearning_show_edit_button',
            isset($config['exelearning_show_edit_button']) && $config['exelearning_show_edit_button'] === '1'
        );
    }
}

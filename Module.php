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
use ExeLearning\Service\DownloadFormats;
use ExeLearning\Service\EditorBundle;

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

        $this->removeEditorInstallerSettings($serviceLocator);
    }

    /**
     * Execute logic when the module is upgraded.
     *
     * @param string $oldVersion
     * @param string $newVersion
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        $this->removeEditorInstallerSettings($serviceLocator);
    }

    /**
     * Drop the settings left behind by the removed runtime editor installer.
     *
     * The embedded editor became a release artifact bundled inside the module
     * package (ADR-0001), so the runtime installer and its bookkeeping are
     * gone. Deleting the keys is idempotent and safe to run on every upgrade.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    protected function removeEditorInstallerSettings(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $legacyKeys = [
            'exelearning_editor_installed_version',
            'exelearning_editor_installed_at',
            'exelearning_editor_install_phase',
            'exelearning_editor_install_message',
            'exelearning_editor_install_target_version',
            'exelearning_editor_install_started_at',
            'exelearning_editor_install_success',
            'exelearning_editor_install_error',
        ];
        foreach ($legacyKeys as $key) {
            $settings->delete($key);
        }
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

        // Expose screenshot URL through the standard Omeka media JSON-LD API.
        $sharedEventManager->attach(
            'Omeka\Api\Representation\MediaRepresentation',
            'rep.resource.json',
            [$this, 'handleMediaJsonLd']
        );
    }

    /**
     * Add eXeLearning-specific fields (notably the bundled screenshot URL)
     * to the JSON-LD output of media representations served by the API.
     *
     * @param Event $event
     */
    public function handleMediaJsonLd(Event $event)
    {
        $media = $event->getTarget();
        if (!$media || !$this->isExeLearningFile($media)) {
            return;
        }

        $services = $this->getServiceLocator();
        $elpService = $services->get(Service\ElpFileService::class);

        $hash = $elpService->getMediaHash($media);
        if (!$hash) {
            return;
        }

        $jsonLd = $event->getParam('jsonLd', []);

        if ($elpService->hasScreenshot($media)) {
            $jsonLd['o-module-exelearning:screenshot'] =
                '/exelearning/content/' . $hash . '/' . Service\ElpFileService::SCREENSHOT_FILENAME;
        }
        if ($elpService->hasPreview($media)) {
            $jsonLd['o-module-exelearning:content'] =
                '/exelearning/content/' . $hash . '/index.html';
        }

        $event->setParam('jsonLd', $jsonLd);
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

            // Auto-process once. Gate on the processed marker (not hasPreview)
            // so a legitimately preview-less package is not re-extracted on
            // every view, which would accumulate orphan extraction directories.
            if (!$elpService->isProcessed($media)) {
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
            $contentPath = $this->buildContentPath($hash, $media);

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

        // Auto-process once (gate on the processed marker, not hasPreview).
        if (!$elpService->isProcessed($media)) {
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
        $contentPath = $this->buildContentPath($hash, $media);

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
        $label = $view->escapeJs($view->translate('Teacher Mode'));
        $visibleLabel = $view->escapeJs($view->translate('Show teacher layer selector'));
        $help = $view->escapeJs($view->translate('If disabled, the teacher layer selector is hidden in the embedded eXeLearning content.'));
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
                injectField(data.teacherModeVisible === true);
            })
            .catch(function() {
                // Fallback: use current page title as heuristic.
                var title = document.querySelector('h1 .title');
                if (title && isExeFilename(title.textContent || '')) {
                    injectField(false);
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
     * Check whether teachers may reveal teacher-only content for this media.
     *
     * eXeLearning exports hide teacher content by default; it is revealed via
     * the ?exe-teacher=1 URL parameter. This per-media setting controls whether
     * the module is allowed to add that parameter for teacher viewers.
     */
    protected function isTeacherModeVisible($media): bool
    {
        $data = $media->mediaData();
        if (!isset($data['exelearning_teacher_mode_visible'])) {
            return false;
        }

        $value = $data['exelearning_teacher_mode_visible'];
        return !in_array((string) $value, ['0', 'false', 'no'], true);
    }

    /**
     * Build the relative content path for a media. The per-media "Show teacher layer
     * selector" setting alone controls it: when on, the package's ?exe-teacher=1
     * parameter is appended so the teacher-layer selector is available to every viewer;
     * otherwise the default (student) view is served with no parameter.
     */
    protected function buildContentPath(string $hash, $media): string
    {
        $contentPath = '/exelearning/content/' . $hash . '/index.html';
        if ($this->isTeacherModeVisible($media)) {
            $contentPath .= '?exe-teacher=1';
        }

        return $contentPath;
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

        $storedFormats = $settings->get('exelearning_download_formats', null);
        if (is_string($storedFormats)) {
            $decoded = json_decode($storedFormats, true);
            $storedFormats = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($storedFormats)) {
            $storedFormats = DownloadFormats::enabledByDefault();
        }

        $form->setData([
            'exelearning_viewer_height' => $settings->get('exelearning_viewer_height', 600),
            'exelearning_download_formats' => DownloadFormats::sanitize($storedFormats),
        ]);

        $formHtml = $renderer->formCollection($form, false);

        return $this->renderEditorStatusSection($renderer)
            . $this->renderStylesSection($renderer)
            . $formHtml;
    }

    /**
     * Render a short section pointing to the dedicated Styles admin page.
     */
    protected function renderStylesSection(PhpRenderer $renderer): string
    {
        $translate = function ($text) use ($renderer) {
            return $renderer->translate($text);
        };
        $stylesUrl = $renderer->url('admin/exelearning-styles');
        $html = '<fieldset id="exelearning-styles-link">';
        $html .= '<legend>' . $renderer->escapeHtml($translate('Styles')) . '</legend>'; // @translate
        $html .= '<div class="field"><div class="field-meta">';
        $html .= '<label>' . $renderer->escapeHtml($translate('Style management')) . '</label>'; // @translate
        $html .= '</div><div class="inputs">';
        $html .= '<a class="button" href="' . $renderer->escapeHtmlAttr($stylesUrl) . '">';
        $html .= $renderer->escapeHtml($translate('Open styles page'));  // @translate
        $html .= '</a>';
        $html .= '<p class="explanation">';
        $html .= $renderer->escapeHtml($translate( // @translate
            'Upload eXeLearning style packages, enable/disable built-in styles, '
            . 'and control the "Block user-imported styles" policy from a dedicated page.'
        ));
        $html .= '</p>';
        $html .= '</div></div></fieldset>';
        return $html;
    }

    /**
     * Warn when the bundled editor is missing.
     *
     * The editor ships inside the module package (ADR-0001), so in a normal
     * installation there is nothing to show or do here; the section only
     * appears when the bundle is absent (e.g. a development checkout that has
     * not run `make build-editor`).
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    protected function renderEditorStatusSection(PhpRenderer $renderer): string
    {
        if (EditorBundle::isAvailable()) {
            return '';
        }

        $translate = function ($text) use ($renderer) {
            return $renderer->translate($text);
        };

        $html = '<fieldset id="exelearning-editor-status">';
        $html .= '<legend>' . $renderer->escapeHtml($translate('Embedded Editor')) . '</legend>'; // @translate
        $html .= '<div class="field"><div class="field-meta"></div><div class="inputs">';
        $html .= '<p><span style="color: #dc3232;">&#10007;</span> ';
        $html .= $renderer->escapeHtml($translate( // @translate
            'This installation does not include the embedded editor, so editing eXeLearning content is disabled.'
            . ' Official release packages include it; development checkouts must build it with "make build-editor".'
        ));
        $html .= '</p>';
        $html .= '</div></div>';
        $html .= '</fieldset>';

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
            'exelearning_download_formats',
            DownloadFormats::sanitize($config['exelearning_download_formats'] ?? [])
        );
    }
}

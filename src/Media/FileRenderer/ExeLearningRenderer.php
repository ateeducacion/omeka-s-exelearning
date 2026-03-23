<?php
declare(strict_types=1);

namespace ExeLearning\Media\FileRenderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\FileRenderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;
use ExeLearning\Service\ElpFileService;

/**
 * Renderer for eXeLearning files.
 *
 * Displays the extracted HTML content in an iframe with an optional edit button.
 */
class ExeLearningRenderer implements RendererInterface
{
    /** @var ElpFileService */
    protected $elpService;

    /** @var \Laminas\Http\Request */
    protected $request;

    /**
     * @param ElpFileService $elpService
     * @param \Laminas\Http\Request $request
     */
    public function __construct(ElpFileService $elpService, \Laminas\Http\Request $request)
    {
        $this->elpService = $elpService;
        $this->request = $request;
    }

    /**
     * Render the eXeLearning media.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
     *
     * @codeCoverageIgnore
     */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        try {
            // Check if this is an eXeLearning file
            if (!$this->isExeLearningFile($media)) {
                return $this->renderFallback($view, $media);
            }

            $hash = $this->elpService->getMediaHash($media);
            $hasPreview = $this->elpService->hasPreview($media);

            if (!$hash || !$hasPreview) {
                return $this->renderFallback($view, $media);
            }
        } catch (\Throwable $e) {
            return $this->renderFallback($view, $media);
        }

        // Get configuration
        $config = $this->getConfig($view);

        // Relative path; JS constructs the full URL from window.location so the
        // playground SW scope prefix is always included (PHP cannot see it).
        $contentPath = '/exelearning/content/' . $hash . '/index.html';
        if (!$this->isTeacherModeVisible($media)) {
            $contentPath .= '?teacher_mode_visible=0';
        }

        // Load assets
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/exelearning.css', 'ExeLearning')
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/exelearning-viewer.js', 'ExeLearning')
        );

        $iframeId = 'exelearning-iframe-' . $media->id();

        // Build HTML
        $html = '<div class="exelearning-viewer" data-media-id="' . $media->id() . '">';

        // Toolbar
        $html .= '<div class="exelearning-toolbar">';
        $html .= '<span class="exelearning-title">' . $view->escapeHtml($media->displayTitle()) . '</span>';
        $html .= '<div class="exelearning-toolbar-actions">';

        // Download button
        $html .= '<a href="' . $view->escapeHtmlAttr($media->originalUrl()) . '" ';
        $html .= 'class="button exelearning-download-btn" download>';
        $html .= '<span class="icon-download"></span> ';
        $html .= $view->translate('Download');
        $html .= '</a>';

        // Fullscreen button
        $html .= '<button type="button" class="button exelearning-fullscreen-btn" ';
        $html .= 'data-target="' . $iframeId . '">';
        $html .= '<span class="icon-fullscreen"></span> ';
        $html .= $view->translate('Fullscreen');
        $html .= '</button>';

        // Edit button (if allowed)
        if ($config['showEditButton'] && $this->canEdit($view, $media)) {
            $editUrl = $view->url('admin/exelearning-editor', [
                'action' => 'edit',
                'id' => $media->id()
            ]);
            $html .= '<a href="' . $view->escapeHtmlAttr($editUrl) . '" ';
            $html .= 'class="button exelearning-edit-btn" target="_blank">';
            $html .= '<span class="icon-edit"></span> ';
            $html .= $view->translate('Edit in eXeLearning');
            $html .= '</a>';
        }

        $html .= '</div>'; // toolbar-actions
        $html .= '</div>'; // toolbar

        // Iframe — src is set by inline JS so the playground SW scope prefix
        // from window.location is correctly prepended to the content path.
        $html .= '<iframe ';
        $html .= 'id="' . $iframeId . '" ';
        $html .= 'data-exe-content-path="' . $view->escapeHtmlAttr($contentPath) . '" ';
        $html .= 'class="exelearning-iframe" ';
        $html .= 'style="width: 100%; height: ' . (int) $config['height'] . 'px; border: none;" ';
        $html .= 'sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" ';
        $html .= 'referrerpolicy="no-referrer" ';
        $html .= 'allowfullscreen>';
        $html .= '</iframe>';

        $html .= '<script>(function(){';
        $html .= 'var h=window.location.href,b=h;';
        $html .= '["/admin/","/s/","/api/"].some(function(m){var i=h.indexOf(m);if(i!==-1){b=h.substring(0,i);return true;}return false;});';
        $html .= 'window.exelearningContentBase=b;';
        $html .= 'var el=document.getElementById("' . $iframeId . '");';
        $html .= 'if(el)el.src=b+el.getAttribute("data-exe-content-path");';
        $html .= '})();</script>';

        $html .= '</div>'; // exelearning-viewer

        return $html;
    }

    /**
     * Render fallback for files without preview.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @return string
     */
    protected function renderFallback(PhpRenderer $view, MediaRepresentation $media): string
    {
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/exelearning.css', 'ExeLearning')
        );

        $fileUrl = $media->originalUrl();
        $fileName = pathinfo($fileUrl, PATHINFO_BASENAME);

        $html = '<div class="exelearning-fallback">';
        $html .= '<div class="exelearning-icon"></div>';
        $html .= '<p class="exelearning-filename">' . $view->escapeHtml($fileName) . '</p>';
        $html .= '<a href="' . $view->escapeHtmlAttr($fileUrl) . '" ';
        $html .= 'class="button exelearning-download-btn" download>';
        $html .= '<span class="icon-download"></span> ';
        $html .= $view->translate('Download eXeLearning file');
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if the current user can edit the media.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @return bool
     */
    protected function canEdit(PhpRenderer $view, MediaRepresentation $media): bool
    {
        try {
            $acl = $view->getHelperPluginManager()->get('acl');
            return $acl->userIsAllowed($media->item(), 'update');
        } catch (\Exception $e) {
            return false;
        }
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
        $uri = $this->request->getUri();
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
     * Determine whether teacher mode toggler should be visible.
     */
    protected function isTeacherModeVisible(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        if (!isset($data['exelearning_teacher_mode_visible'])) {
            return true;
        }

        $value = $data['exelearning_teacher_mode_visible'];
        return !in_array((string) $value, ['0', 'false', 'no'], true);
    }

    protected function isExeLearningFile(MediaRepresentation $media): bool
    {
        $filename = $media->filename();
        if (!$filename) {
            return false;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, ['elpx', 'zip']);
    }

    /**
     * Get viewer configuration.
     *
     * @param PhpRenderer $view
     * @return array
     */
    protected function getConfig(PhpRenderer $view): array
    {
        $defaults = [
            'height' => 600,
            'showEditButton' => true,
        ];

        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            return [
                'height' => $setting('exelearning_viewer_height', $defaults['height']),
                'showEditButton' => $setting('exelearning_show_edit_button', $defaults['showEditButton']),
            ];
        } catch (\Exception $e) {
            return $defaults;
        }
    }
}

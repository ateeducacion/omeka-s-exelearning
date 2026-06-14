<?php
declare(strict_types=1);

namespace ExeLearning\Media\FileRenderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\FileRenderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;
use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\DownloadFormats;
use ExeLearning\Service\IframeSandbox;

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

        // In secure mode the content is opaque, so external embeds are promoted to this
        // page (no-op in legacy, where they already work inline). The embed policy
        // (open default | strict) mirrors mod_exelearning's embedmode setting (DEC-0061).
        IframeSandbox::enqueueEmbedRelay($view, $config['iframe_mode'], $config['embed_mode']);

        // Enqueue the download orchestrator only when the multi-format
        // button will actually be rendered.
        $downloadFormatIds = $this->getEnabledDownloadFormats($view);
        $showDownload = !empty($downloadFormatIds);
        if ($showDownload) {
            DownloadFormats::enqueueDownloadAssets($view);
        }

        $iframeId = 'exelearning-iframe-' . $media->id();

        // Build HTML
        $html = '<div class="exelearning-viewer" data-media-id="' . $media->id() . '">';

        // Toolbar
        $html .= '<div class="exelearning-toolbar">';
        $html .= '<span class="exelearning-title">' . $view->escapeHtml($media->displayTitle()) . '</span>';
        $html .= '<div class="exelearning-toolbar-actions">';

        // Download button — multi-format split-button when enabled, otherwise
        // a plain link to the original .elpx.
        if ($showDownload) {
            $variant = $this->isAdminRequest() ? 'admin' : 'default';
            $html .= DownloadFormats::renderSplitButton($view, $media, $downloadFormatIds, $variant);
        }

        // Fullscreen button
        $html .= '<button type="button" class="button exelearning-fullscreen-btn" ';
        $html .= 'data-target="' . $iframeId . '">';
        $html .= '<span class="icon-fullscreen"></span> ';
        $html .= $view->translate('Fullscreen');
        $html .= '</button>';

        // No edit button here: editing .elpx is an admin-only action, offered
        // by the admin media-show viewer. This renderer (which can appear on
        // public pages) stays view + fullscreen only.

        $html .= '</div>'; // toolbar-actions
        $html .= '</div>'; // toolbar

        // Iframe — src is set by inline JS so the playground SW scope prefix
        // from window.location is correctly prepended to the content path.
        $html .= '<iframe ';
        $html .= 'id="' . $iframeId . '" ';
        $html .= 'data-exe-content-path="' . $view->escapeHtmlAttr($contentPath) . '" ';
        $html .= 'class="exelearning-iframe" ';
        $html .= 'style="width: 100%; height: ' . (int) $config['height'] . 'px; border: none;" ';
        $html .= 'sandbox="' . IframeSandbox::tokens($config['iframe_mode']) . '" ';
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
            'iframe_mode' => IframeSandbox::MODE_SECURE,
            'embed_mode' => IframeSandbox::EMBED_OPEN,
        ];

        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            return [
                'height' => $setting('exelearning_viewer_height', $defaults['height']),
                'iframe_mode' => IframeSandbox::normalizeMode(
                    $setting('exelearning_iframe_mode', $defaults['iframe_mode'])
                ),
                // Raw setting value; IframeSandbox::embedMode() resolves it (open default).
                'embed_mode' => $setting(IframeSandbox::EMBED_OPTION, IframeSandbox::EMBED_OPEN),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Whether the current request targets the Omeka admin UI.
     */
    protected function isAdminRequest(): bool
    {
        try {
            $path = (string) $this->request->getUri()->getPath();
        } catch (\Throwable $e) {
            return false;
        }
        return strpos($path, '/admin/') !== false;
    }

    /**
     * Read the configured set of download formats from module settings.
     *
     * @return string[] Sanitized list, possibly empty when the admin opted to
     *                  hide the download button entirely.
     */
    protected function getEnabledDownloadFormats(PhpRenderer $view): array
    {
        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            $stored = $setting('exelearning_download_formats', null);
            if ($stored === null) {
                return DownloadFormats::enabledByDefault();
            }
            if (is_string($stored)) {
                $decoded = json_decode($stored, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            }
            return DownloadFormats::sanitize($stored);
        } catch (\Exception $e) {
            return DownloadFormats::enabledByDefault();
        }
    }
}

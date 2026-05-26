<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Canonical registry of formats offered by the embed multi-format download
 * split-button. The actual format generation is produced client-side by the
 * eXeLearning `SharedExporters` bundle; this class only describes the
 * options exposed in the UI and the filename suffix convention.
 */
final class DownloadFormats
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'elpx',
                'label' => 'Download .elpx',
                'suffix' => '.elpx',
                'mime' => 'application/zip',
                'client' => false,
            ],
            [
                'id' => 'html5',
                'label' => 'Web',
                'suffix' => '_web.zip',
                'mime' => 'application/zip',
                'client' => true,
            ],
            [
                'id' => 'scorm12',
                'label' => 'SCORM 1.2',
                'suffix' => '_scorm.zip',
                'mime' => 'application/zip',
                'client' => true,
            ],
            [
                'id' => 'ims',
                'label' => 'IMS Package',
                'suffix' => '_ims.zip',
                'mime' => 'application/zip',
                'client' => true,
            ],
            [
                'id' => 'epub3',
                'label' => 'EPUB3',
                'suffix' => '.epub',
                'mime' => 'application/epub+zip',
                'client' => true,
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function defaultIds(): array
    {
        return array_map(static fn ($f) => $f['id'], self::all());
    }

    /**
     * Sanitize a stored or submitted list of format ids.
     *
     * @param mixed $input  Array, comma-separated string, or null/missing.
     * @return string[]     Canonical-ordered subset of {@see self::all()}.
     */
    public static function sanitize($input): array
    {
        if (is_string($input)) {
            $input = array_filter(array_map('trim', explode(',', $input)));
        }
        if (!is_array($input)) {
            return self::defaultIds();
        }

        $valid = self::defaultIds();
        $set = array_map('strval', $input);
        $out = [];
        foreach ($valid as $id) {
            if (in_array($id, $set, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        foreach (self::all() as $fmt) {
            if ($fmt['id'] === $id) {
                return $fmt;
            }
        }
        return null;
    }

    /**
     * Read the enabled formats from Omeka settings, falling back to defaults.
     *
     * @param mixed $settings  An Omeka\Settings\Settings-like object with a
     *                         `get($key, $default)` method, or null.
     * @return string[]
     */
    public static function fromSettings($settings): array
    {
        if (!$settings || !is_object($settings) || !method_exists($settings, 'get')) {
            return self::defaultIds();
        }
        $stored = $settings->get('exelearning_download_formats', null);
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        return $stored === null ? self::defaultIds() : self::sanitize($stored);
    }

    /**
     * Render the multi-format split-button as HTML.
     *
     * Shared between the file renderer and the public/admin item partials so
     * the markup stays consistent. The `<a>`/`<button>` elements expose the
     * data attributes consumed by `asset/js/omeka-exe-download.js`.
     *
     * @param \Laminas\View\Renderer\PhpRenderer            $view
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param string[]                                      $formatIds
     */
    public static function renderSplitButton($view, $media, array $formatIds): string
    {
        $items = [];
        foreach ($formatIds as $id) {
            $fmt = self::get($id);
            if ($fmt) {
                $items[] = $fmt;
            }
        }
        if (empty($items)) {
            return '';
        }

        $primary = array_shift($items);
        $dropdown = $items;

        $sourceName = method_exists($media, 'filename') ? (string) $media->filename() : '';
        $slug = pathinfo($sourceName ?: ('media-' . $media->id()), PATHINFO_FILENAME);
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $slug) ?: ('media-' . $media->id());

        $dataAttrs = sprintf(
            'data-media-id="%d" data-elp-url="%s" data-slug="%s"',
            $media->id(),
            $view->escapeHtmlAttr($media->originalUrl()),
            $view->escapeHtmlAttr($slug)
        );

        $html = '<div class="exelearning-download" ' . $dataAttrs . '>';
        $html .= self::renderItem($view, $primary, true);

        if (!empty($dropdown)) {
            $html .= '<button type="button" class="exelearning-download__toggle" aria-haspopup="true" aria-expanded="false" aria-label="'
                . $view->escapeHtmlAttr($view->translate('More download formats')) . '">';
            $html .= '<span class="exelearning-download__caret">&#9662;</span>';
            $html .= '</button>';
            $html .= '<ul class="exelearning-download__menu" role="menu" hidden>';
            foreach ($dropdown as $fmt) {
                $html .= '<li role="none">' . self::renderItem($view, $fmt, false) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Enqueue the download orchestrator JS and i18n payload exactly once
     * per request.
     *
     * @param \Laminas\View\Renderer\PhpRenderer $view
     */
    public static function enqueueDownloadAssets($view): void
    {
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;
        $view->headScript()->appendFile(
            $view->assetUrl('js/omeka-exe-download.js', 'ExeLearning')
        );
        $i18n = json_encode([
            'preparing' => $view->translate('Preparing download…'),
            'failed' => $view->translate('Download failed. Please try again.'),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $view->headScript()->appendScript('window.OMEKA_EXE_DOWNLOAD_I18N = ' . $i18n . ';');
    }

    /**
     * @param \Laminas\View\Renderer\PhpRenderer $view
     * @param array<string, mixed>               $fmt
     */
    private static function renderItem($view, array $fmt, bool $isPrimary): string
    {
        $classes = $isPrimary ? 'exelearning-download__primary' : 'exelearning-download__item';
        $label = $view->translate((string) $fmt['label']);

        if ($fmt['id'] === 'elpx') {
            return sprintf(
                '<a href="#" class="%s" data-format="%s" data-suffix="%s" download role="%s">'
                    . '<span class="exelearning-download__label">%s</span>'
                . '</a>',
                $view->escapeHtmlAttr($classes),
                $view->escapeHtmlAttr($fmt['id']),
                $view->escapeHtmlAttr($fmt['suffix']),
                $isPrimary ? 'button' : 'menuitem',
                $view->escapeHtml($label)
            );
        }

        return sprintf(
            '<button type="button" class="%s" data-format="%s" data-suffix="%s" data-mime="%s" role="%s">'
                . '<span class="exelearning-download__label">%s</span>'
            . '</button>',
            $view->escapeHtmlAttr($classes),
            $view->escapeHtmlAttr($fmt['id']),
            $view->escapeHtmlAttr($fmt['suffix']),
            $view->escapeHtmlAttr($fmt['mime']),
            $isPrimary ? 'button' : 'menuitem',
            $view->escapeHtml($label)
        );
    }
}

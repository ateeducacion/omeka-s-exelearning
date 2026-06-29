<?php
declare(strict_types=1);

namespace ExeLearning\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use ExeLearning\Service\DownloadFormats;

/**
 * Configuration form for the ExeLearning module.
 */
class ConfigForm extends Form
{
    /**
     * Initialize the form elements.
     */
    public function init(): void
    {
        $this->add([
            'name' => 'exelearning_viewer_height',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Viewer Height (px)', // @translate
                'info' => 'Default height for the eXeLearning content viewer in pixels.', // @translate
            ],
            'attributes' => [
                'required' => false,
                'min' => 200,
                'max' => 1200,
                'value' => 600,
            ],
        ]);

        // The same-origin "legacy" iframe mode was removed: the preview always renders in an
        // opaque-origin sandbox. A dev-only escape hatch (the EXELEARNING_UNSAFE_LEGACY_IFRAME
        // constant/env, never this form) restores same-origin where an opaque subframe cannot
        // be served (the php-wasm Playground).

        $this->add([
            'name' => 'exelearning_embed_mode',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'External embed policy', // @translate
                'info' => 'Strict (recommended): only a maintained provider allowlist (YouTube, Vimeo, Dailymotion, EducaMadrid) with per-provider URL reconstruction; use it where the author is not fully trusted. Open: any cross-origin https iframe is promoted to this page and rendered sandboxed, so it is isolated by the same-origin policy regardless of host. Mirrors mod_exelearning\'s embedmode setting.', // @translate
                'value_options' => [
                    'strict' => 'Strict (provider allowlist)', // @translate
                    'open' => 'Open (any cross-origin https embed)', // @translate
                ],
            ],
            'attributes' => [
                'required' => false,
                'value' => 'strict',
            ],
        ]);

        // Use the bare format label as the option label so the form's
        // multicheckbox view helper translates it against an existing catalog
        // entry (e.g. "IMS Package" -> "Paquete IMS"). The previous
        // sprintf('%s (%s)', label, suffix) produced a composite msgid that no
        // catalog ever contained, so every option rendered untranslated.
        $valueOptions = [];
        foreach (DownloadFormats::all() as $fmt) {
            $valueOptions[$fmt['id']] = $fmt['label'];
        }

        $this->add([
            'name' => 'exelearning_download_formats',
            'type' => Element\MultiCheckbox::class,
            'options' => [
                'label' => 'Download formats', // @translate
                'info' => 'Formats offered by the download split-button on embedded eXeLearning content. Non-source formats are produced client-side by the editor exporters bundle.', // @translate
                'value_options' => $valueOptions,
            ],
            'attributes' => [
                'value' => DownloadFormats::enabledByDefault(),
            ],
        ]);
    }
}

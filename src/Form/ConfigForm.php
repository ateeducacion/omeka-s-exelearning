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

        $this->add([
            'name' => 'exelearning_iframe_mode',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Preview iframe security mode', // @translate
                'info' => 'Secure (recommended): the preview runs in an opaque-origin sandbox, so author HTML/JS cannot read the Omeka page or reach its cookies. Legacy: keeps same-origin (only needed where an opaque iframe cannot be served, e.g. the php-wasm Playground).', // @translate
                'value_options' => [
                    'secure' => 'Secure (opaque-origin sandbox)', // @translate
                    'legacy' => 'Legacy (same-origin)', // @translate
                ],
            ],
            'attributes' => [
                'required' => false,
                'value' => 'secure',
            ],
        ]);

        $this->add([
            'name' => 'exelearning_embed_mode',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'External embed policy', // @translate
                'info' => 'Open (recommended): in secure mode any cross-origin https iframe (YouTube, Vimeo, …) is promoted to this page and rendered sandboxed, so it is isolated by the same-origin policy regardless of host. Strict: only a maintained host allowlist with per-provider URL reconstruction, for deployments where even the author is not trusted. Mirrors mod_exelearning\'s embedmode setting.', // @translate
                'value_options' => [
                    'open' => 'Open (any cross-origin https embed)', // @translate
                    'strict' => 'Strict (host allowlist)', // @translate
                ],
            ],
            'attributes' => [
                'required' => false,
                'value' => 'open',
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

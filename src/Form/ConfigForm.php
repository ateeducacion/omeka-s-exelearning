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
            'name' => 'exelearning_show_edit_button',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Show Edit Button', // @translate
                'info' => 'Display the "Edit in eXeLearning" button for users with edit permissions.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $valueOptions = [];
        foreach (DownloadFormats::all() as $fmt) {
            $valueOptions[$fmt['id']] = sprintf('%s (%s)', $fmt['label'], $fmt['suffix']);
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

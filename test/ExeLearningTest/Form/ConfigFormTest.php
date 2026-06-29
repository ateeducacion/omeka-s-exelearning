<?php

declare(strict_types=1);

namespace ExeLearningTest\Form;

use ExeLearning\Form\ConfigForm;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigForm.
 *
 * @covers \ExeLearning\Form\ConfigForm
 */
class ConfigFormTest extends TestCase
{
    private ConfigForm $form;

    protected function setUp(): void
    {
        $this->form = new ConfigForm();
        $this->form->init();
    }

    // =========================================================================
    // Form initialization tests
    // =========================================================================

    public function testFormCanBeCreated(): void
    {
        $form = new ConfigForm();
        $this->assertInstanceOf(ConfigForm::class, $form);
    }

    public function testFormHasViewerHeightElement(): void
    {
        $this->assertTrue($this->form->has('exelearning_viewer_height'));
    }

    public function testFormHasNoShowEditButtonElement(): void
    {
        // Editing .elpx is admin-only; the public "Show Edit Button" toggle was
        // removed, so the form must not expose it.
        $this->assertFalse($this->form->has('exelearning_show_edit_button'));
    }

    // =========================================================================
    // Viewer height element tests
    // =========================================================================

    public function testViewerHeightElementExists(): void
    {
        $element = $this->form->get('exelearning_viewer_height');
        $this->assertNotNull($element);
    }

    public function testViewerHeightIsNumberElement(): void
    {
        $element = $this->form->get('exelearning_viewer_height');
        $this->assertInstanceOf(\Laminas\Form\Element\Number::class, $element);
    }

    public function testViewerHeightHasLabel(): void
    {
        $element = $this->form->get('exelearning_viewer_height');
        $this->assertNotEmpty($element->getLabel());
        $this->assertStringContainsString('Height', $element->getLabel());
    }

    public function testViewerHeightHasDefaultValue(): void
    {
        $element = $this->form->get('exelearning_viewer_height');
        $this->assertEquals(600, $element->getValue());
    }

    public function testViewerHeightHasMinAttribute(): void
    {
        $element = $this->form->get('exelearning_viewer_height');
        $this->assertEquals(200, $element->getAttribute('min'));
    }

    public function testViewerHeightHasMaxAttribute(): void
    {
        $element = $this->form->get('exelearning_viewer_height');
        $this->assertEquals(1200, $element->getAttribute('max'));
    }

    // =========================================================================
    // Download formats element tests
    // =========================================================================

    public function testFormHasDownloadFormatsElement(): void
    {
        $this->assertTrue($this->form->has('exelearning_download_formats'));
    }

    // =========================================================================
    // Iframe security mode element tests
    // =========================================================================

    public function testFormHasNoIframeModeElement(): void
    {
        // The secure/legacy mode selector was removed: the preview is always opaque-origin,
        // so the form no longer exposes a way to weaken isolation.
        $this->assertFalse($this->form->has('exelearning_iframe_mode'));
    }

    // =========================================================================
    // Additional tests
    // =========================================================================

    public function testInitCanBeCalledMultipleTimes(): void
    {
        $this->form->init();
        $this->form->init();

        // Should still have the elements
        $this->assertTrue($this->form->has('exelearning_viewer_height'));
        $this->assertTrue($this->form->has('exelearning_download_formats'));
    }
}

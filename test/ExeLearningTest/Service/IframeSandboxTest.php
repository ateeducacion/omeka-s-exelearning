<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\IframeSandbox;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IframeSandbox.
 *
 * @covers \ExeLearning\Service\IframeSandbox
 */
class IframeSandboxTest extends TestCase
{
    public function testSecureTokensHaveNoSameOriginNorPopupEscape(): void
    {
        $tokens = IframeSandbox::tokens(IframeSandbox::MODE_SECURE);
        $this->assertStringNotContainsString('allow-same-origin', $tokens);
        // An escaped popup could otherwise reopen the content unsandboxed/same-origin.
        $this->assertStringNotContainsString('allow-popups-to-escape-sandbox', $tokens);
        $this->assertStringContainsString('allow-scripts', $tokens);
        $this->assertStringContainsString('allow-popups', $tokens);
        // allow-forms is required so the form-based iDevices can submit in the sandbox.
        $this->assertStringContainsString('allow-forms', $tokens);
    }

    public function testLegacyTokensKeepSameOriginAndPopupEscape(): void
    {
        $tokens = IframeSandbox::tokens(IframeSandbox::MODE_LEGACY);
        $this->assertStringContainsString('allow-same-origin', $tokens);
        $this->assertStringContainsString('allow-popups-to-escape-sandbox', $tokens);
        $this->assertStringContainsString('allow-scripts', $tokens);
        $this->assertStringContainsString('allow-forms', $tokens);
    }

    public function testNormalizeModeKeepsLegacy(): void
    {
        $this->assertSame(IframeSandbox::MODE_LEGACY, IframeSandbox::normalizeMode('legacy'));
    }

    /**
     * Anything that is not exactly "legacy" must fall back to secure, so an
     * unset or tampered setting never weakens isolation.
     *
     * @dataProvider secureFallbackProvider
     * @param mixed $value
     */
    public function testNormalizeModeFailsSafeToSecure($value): void
    {
        $this->assertSame(IframeSandbox::MODE_SECURE, IframeSandbox::normalizeMode($value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function secureFallbackProvider(): array
    {
        return [
            'secure'  => ['secure'],
            'garbage' => ['something-else'],
            'empty'   => [''],
            'null'    => [null],
            'capital' => ['Legacy'],
            'array'   => [['legacy']],
            'object'  => [new \stdClass()],
            'true'    => [true],
            'false'   => [false],
            'int'     => [0],
        ];
    }

    public function testEmbedWhitelistContainsDefaultVideoHosts(): void
    {
        $hosts = IframeSandbox::embedWhitelist();
        $this->assertContains('www.youtube.com', $hosts);
        $this->assertContains('youtube-nocookie.com', $hosts);
        $this->assertContains('player.vimeo.com', $hosts);
        $this->assertContains('www.dailymotion.com', $hosts);
        $this->assertContains('mediateca.educa.madrid.org', $hosts);
    }

    public function testEmbedWhitelistIsLowercaseAndDeduplicated(): void
    {
        $hosts = IframeSandbox::embedWhitelist();
        $this->assertSame(array_map('strtolower', $hosts), $hosts);
        $this->assertSame(array_values(array_unique($hosts)), $hosts);
    }
}

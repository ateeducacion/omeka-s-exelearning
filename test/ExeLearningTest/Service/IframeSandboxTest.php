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
    public function testSecureTokensHaveNoSameOrigin(): void
    {
        $tokens = IframeSandbox::tokens(IframeSandbox::MODE_SECURE);
        $this->assertStringNotContainsString('allow-same-origin', $tokens);
        $this->assertStringContainsString('allow-scripts', $tokens);
        $this->assertStringContainsString('allow-popups', $tokens);
    }

    public function testLegacyTokensIncludeSameOrigin(): void
    {
        $tokens = IframeSandbox::tokens(IframeSandbox::MODE_LEGACY);
        $this->assertStringContainsString('allow-same-origin', $tokens);
        $this->assertStringContainsString('allow-scripts', $tokens);
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
        ];
    }
}

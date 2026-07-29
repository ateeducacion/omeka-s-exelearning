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

    public function testLegacyOptionIsIgnoredWithoutEscapeHatch(): void
    {
        // The same-origin mode was removed: a 'legacy' setting is ignored (the escape-hatch
        // constant is off by default), so the tokens stay opaque (no allow-same-origin).
        $this->assertFalse(IframeSandbox::isUnsafeLegacy());
        $tokens = IframeSandbox::tokens(IframeSandbox::MODE_LEGACY);
        $this->assertStringNotContainsString('allow-same-origin', $tokens);
        $this->assertStringContainsString('allow-scripts', $tokens);
    }

    public function testNormalizeModeIsAlwaysSecureWithoutEscapeHatch(): void
    {
        $this->assertSame(IframeSandbox::MODE_SECURE, IframeSandbox::normalizeMode('legacy'));
    }

    public function testCspProfileDefaultsToStrict(): void
    {
        $this->assertSame(IframeSandbox::CSP_STRICT, IframeSandbox::cspProfile());
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

    public function testEmbedModeDefaultsToStrict(): void
    {
        // 'open' is an explicit opt-in; an unset value defaults to strict (DEC-0061).
        $this->assertSame(IframeSandbox::EMBED_OPEN, IframeSandbox::embedMode('open'));
        $this->assertSame(IframeSandbox::EMBED_STRICT, IframeSandbox::embedMode(null));
        $this->assertSame(IframeSandbox::EMBED_STRICT, IframeSandbox::embedMode());
    }

    public function testEmbedModeKeepsStrict(): void
    {
        $this->assertSame(IframeSandbox::EMBED_STRICT, IframeSandbox::embedMode('strict'));
    }

    /**
     * Every value other than an explicit 'open' (including unset/tampered settings) resolves
     * to the more restrictive 'strict' policy, so the embed gate never silently weakens.
     *
     * @dataProvider strictFallbackProvider
     * @param mixed $value
     */
    public function testEmbedModeFailsSafeToStrict($value): void
    {
        $this->assertSame(IframeSandbox::EMBED_STRICT, IframeSandbox::embedMode($value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function strictFallbackProvider(): array
    {
        return [
            'strict'  => ['strict'],
            'garbage' => ['something-else'],
            'capital' => ['Open'],
            'array'   => [['open']],
            'object'  => [new \stdClass()],
            'true'    => [true],
            'int'     => [1],
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

    /**
     * The external-media migration: this module vendors the BYTES eXeLearning core
     * publishes rather than a copy of the logic, so what is asserted here is that the
     * bundle is what gets enqueued -- not one of the three files it replaced.
     */
    public function testEnqueueEmbedRelayLoadsTheCanonicalBundle(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();

        \ExeLearning\Service\IframeSandbox::enqueueEmbedRelay($view, 'secure');

        $files = $view->headScript()->files;
        $this->assertCount(1, $files, 'one artifact, not three separate files');
        $this->assertStringContainsString(
            'exe_external_media/exe-external-media-host.min.js',
            $files[0]
        );
    }

    /**
     * Superseded files must not be enqueued. Stated as its own assertion because "the
     * bundle is present" would stay true if an old file were loaded alongside it, and a
     * page carrying both would run two relays.
     */
    public function testEnqueueEmbedRelayLoadsNoSupersededFile(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();

        \ExeLearning\Service\IframeSandbox::enqueueEmbedRelay($view, 'secure');

        $joined = implode(' ', $view->headScript()->files);
        $this->assertStringNotContainsString('exe-embed-relay.js', $joined);
        $this->assertStringNotContainsString('exe-media-policy.js', $joined);
        $this->assertStringNotContainsString('exe-media-host.js', $joined);
    }

    /**
     * The canonical bundle does NOT auto-start from a global: the policy it applies is the
     * embedding page's decision, and a host that guessed would have to guess permissively.
     * The old relay auto-started from window.ExeEmbedRelayConfig; that global is gone with
     * the file that read it, so an explicit init is now load-bearing rather than optional.
     */
    public function testEnqueueEmbedRelayInitialisesTheHostExplicitly(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();

        \ExeLearning\Service\IframeSandbox::enqueueEmbedRelay($view, 'secure');

        $scripts = implode("\n", $view->headScript()->scripts);
        $this->assertStringContainsString('exeEmbedRelay.init(', $scripts);
        $this->assertStringNotContainsString('ExeEmbedRelayConfig', $scripts);
    }

    /** The whitelist the host is given must be the one the module resolved. */
    public function testEnqueueEmbedRelayPassesTheResolvedPolicy(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();

        \ExeLearning\Service\IframeSandbox::enqueueEmbedRelay($view, 'secure');

        $scripts = implode("\n", $view->headScript()->scripts);
        $this->assertStringContainsString('"mode":"strict"', $scripts);
        $this->assertStringContainsString('youtube.com', $scripts);
    }

    /**
     * Asking for legacy does NOT get you legacy. Without the escape hatch this module
     * normalises every mode to secure (see testNormalizeModeIsAlwaysSecureWithoutEscapeHatch),
     * so the bundle is enqueued regardless -- content is always served opaque and its embeds
     * always promoted to the parent.
     *
     * Pinned here because it is the fail-closed direction and it is easy to break by
     * "tidying" the mode check: an early return for legacy would silently reintroduce
     * same-origin rendering for anyone who passed that string.
     */
    public function testEnqueueEmbedRelayStillLoadsWhenLegacyIsRequested(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();

        \ExeLearning\Service\IframeSandbox::enqueueEmbedRelay($view, 'legacy');

        $this->assertCount(1, $view->headScript()->files);
        $this->assertStringContainsString(
            'exe-external-media-host.min.js',
            $view->headScript()->files[0]
        );
    }
}

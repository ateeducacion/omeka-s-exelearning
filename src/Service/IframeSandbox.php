<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Single source of truth for the eXeLearning preview iframe sandbox tokens.
 *
 * The preview shows arbitrary author HTML/JS from an .elpx. In `secure` mode the
 * iframe omits `allow-same-origin`, so the content runs in an opaque origin and
 * cannot read the Omeka page's cookies/DOM or reach `window.parent`. In `legacy`
 * mode `allow-same-origin` is restored, which is only needed where an opaque
 * iframe cannot be served (e.g. the php-wasm Playground, whose service worker
 * only intercepts same-origin documents). Default is `secure`.
 */
final class IframeSandbox
{
    public const MODE_SECURE = 'secure';
    public const MODE_LEGACY = 'legacy';

    /**
     * Open embeds (default): promote any cross-origin https iframe (DEC-0061). The
     * promoted player is sandboxed + cross-origin, so SOP isolates it from the Omeka
     * page; the host is irrelevant to escape, so no host allowlist is required.
     */
    public const EMBED_OPEN = 'open';

    /**
     * Strict embeds: only the maintained host allowlist with per-provider
     * canonical-URL reconstruction, for high-security deployments.
     */
    public const EMBED_STRICT = 'strict';

    /**
     * Settings key that stores the external-embed policy (open|strict).
     *
     * The value is resolved by self::embedMode(); an unset key resolves to 'strict'.
     * Mirrors mod_exelearning's canonical "embedmode" setting (DEC-0061); the Omeka
     * settings key differs only in name.
     */
    public const EMBED_OPTION = 'exelearning_embed_mode';

    /**
     * Secure tokens: opaque origin (no allow-same-origin) and no
     * allow-popups-to-escape-sandbox, so a popup the content opens cannot land in
     * an unsandboxed, same-origin window that would run author JS as Omeka. allow-forms
     * is required so the form-based eXeLearning iDevices can submit inside the sandbox;
     * it is orthogonal to allow-same-origin and does not weaken isolation. Aligned with
     * mod_exelearning's canonical token set (DEC-0059/DEC-0062).
     */
    private const SECURE_TOKENS = 'allow-scripts allow-popups allow-forms';

    /** Legacy tokens: the previous same-origin behaviour, only via the dev-only escape hatch. */
    private const LEGACY_TOKENS = 'allow-same-origin allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox';

    /** Strict CSP profile (default): no bare https: token-exfiltration channels. */
    public const CSP_STRICT = 'strict';

    /** Compatible CSP profile: allows https: img/media/script for external author assets. */
    public const CSP_COMPATIBLE = 'compatible';

    /**
     * Default host whitelist for external video embeds promoted to the parent page.
     *
     * In secure mode the content is opaque, so cross-origin players (YouTube/Vimeo)
     * load blank. Iframes whose src host is on this list are replaced by a placeholder
     * in the content and rendered as a real player by the host page (see the embed JS).
     * PDFs are handled separately (by .pdf extension) and need no host entry.
     */
    public const DEFAULT_EMBED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'vimeo.com',
        'www.dailymotion.com',
        'dailymotion.com',
        'geo.dailymotion.com',
        'mediateca.educa.madrid.org',
    ];

    /**
     * Normalize an arbitrary setting value to a known mode (fail-safe to secure).
     *
     * Uses a strict comparison so non-string values (arrays, objects, booleans)
     * never warn/throw and simply fall back to the secure mode.
     *
     * @param mixed $value
     * @return string self::MODE_SECURE or self::MODE_LEGACY
     */
    public static function normalizeMode($value): string
    {
        // The same-origin ConfigForm option was removed: production is always secure. The
        // dev-only EXELEARNING_UNSAFE_LEGACY_IFRAME escape hatch (constant or env, never the
        // ConfigForm) is the only way to restore same-origin, for environments that cannot
        // serve an opaque subframe (the php-wasm Playground). The setting value is ignored.
        return self::isUnsafeLegacy() ? self::MODE_LEGACY : self::MODE_SECURE;
    }

    /**
     * Whether the dev-only unsafe legacy (same-origin) escape hatch is enabled. Set via the
     * EXELEARNING_UNSAFE_LEGACY_IFRAME constant or environment variable; never exposed in the
     * ConfigForm. Intended only for environments whose service worker cannot serve an opaque
     * subframe. Defaults off.
     *
     * @return bool
     */
    public static function isUnsafeLegacy(): bool
    {
        if (defined('EXELEARNING_UNSAFE_LEGACY_IFRAME') && constant('EXELEARNING_UNSAFE_LEGACY_IFRAME') === true) {
            return true;
        }
        $env = getenv('EXELEARNING_UNSAFE_LEGACY_IFRAME');
        return $env === '1' || $env === 'true';
    }

    /**
     * Resolve the content CSP profile. 'strict' (default) blocks bare https: exfiltration
     * channels; 'compatible' re-opens img/media/script to https: for content that loads
     * external author assets (third-party images, a MathJax CDN) and is documented weaker.
     * Opt in via the EXELEARNING_CSP_PROFILE env/constant; any other value fails safe to strict.
     *
     * @return string self::CSP_STRICT or self::CSP_COMPATIBLE
     */
    public static function cspProfile(): string
    {
        $value = false;
        if (defined('EXELEARNING_CSP_PROFILE')) {
            $value = constant('EXELEARNING_CSP_PROFILE');
        } elseif (getenv('EXELEARNING_CSP_PROFILE') !== false) {
            $value = getenv('EXELEARNING_CSP_PROFILE');
        }
        return $value === self::CSP_COMPATIBLE ? self::CSP_COMPATIBLE : self::CSP_STRICT;
    }

    /**
     * Resolve the external-embed policy (DEC-0061). Default 'strict' restricts promotion to
     * the maintained provider allowlist with canonical URL reconstruction; 'open' is an
     * explicit opt-in that promotes any cross-origin https iframe (the player is sandboxed +
     * cross-origin, so SOP isolates it from the Omeka page). Any unset or unrecognised value
     * fails safe to 'strict'.
     *
     * Mirrors mod_exelearning's canonical embedmode resolver; uses a strict comparison so
     * non-string values (arrays, objects, booleans) never warn/throw.
     *
     * @param mixed $value
     * @return string self::EMBED_OPEN or self::EMBED_STRICT
     */
    public static function embedMode($value = null): string
    {
        return $value === self::EMBED_OPEN ? self::EMBED_OPEN : self::EMBED_STRICT;
    }

    /**
     * Sandbox attribute value for the preview iframe in the given mode.
     *
     * @param mixed $mode
     * @return string
     */
    public static function tokens($mode): string
    {
        return self::normalizeMode($mode) === self::MODE_LEGACY ? self::LEGACY_TOKENS : self::SECURE_TOKENS;
    }

    /**
     * Normalized host whitelist for external video embeds (lowercase, de-duplicated).
     *
     * @return string[]
     */
    public static function embedWhitelist(): array
    {
        $clean = [];
        foreach (self::DEFAULT_EMBED_HOSTS as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $clean[$host] = true;
            }
        }
        return array_keys($clean);
    }

    /**
     * Enqueue the parent-page embed relay (secure mode only).
     *
     * The relay finds content iframes by message source, so a single enqueue per
     * page covers every embed instance. No-op in legacy mode (content is same-origin
     * there, so external players already render inline). Laminas headScript de-dupes
     * the file, so callers can invoke this from several view contexts safely.
     *
     * @param \Laminas\View\Renderer\PhpRenderer $view
     * @param mixed $mode      The iframe sandbox mode (secure|legacy).
     * @param mixed $embedMode The external-embed policy setting value (open|strict);
     *                         an unset/null value resolves to the 'open' default.
     */
    public static function enqueueEmbedRelay(\Laminas\View\Renderer\PhpRenderer $view, $mode, $embedMode = null): void
    {
        if (self::normalizeMode($mode) !== self::MODE_SECURE) {
            return;
        }
        $config = json_encode([
            'mode' => self::embedMode($embedMode),
            'whitelist' => self::embedWhitelist(),
        ]);
        $view->headScript()->appendScript('window.ExeEmbedRelayConfig=' . $config . ';');
        $view->headScript()->appendFile($view->assetUrl('js/exe-embed-relay.js', 'ExeLearning'));
    }

    /**
     * Enqueue the parent-side external-media host for the interactive-video iDevice
     * (secure mode only). The opaque content cannot run a nested YouTube/Vimeo player,
     * so eXeLearning's interactive-video iDevice drives playback through
     * window.exeMediaBridge (the child runtime baked into the package by eXeLearning);
     * this host completes that capability handshake (window identity + per-view nonce +
     * a transferred MessageChannel port) and plays the real provider video in an
     * accessible modal, controlled by RAW postMessage (no YouTube IFrame API / Vimeo SDK
     * loaded on this page). Separate message namespace ('exe-media') from the embed relay
     * ('exe-embed'), so both coexist. No-op in legacy mode. Mirrors mod_exelearning's
     * view.php wiring (DEC-0067).
     *
     * @param \Laminas\View\Renderer\PhpRenderer $view
     * @param mixed $mode The iframe sandbox mode (secure|legacy).
     */
    public static function enqueueMediaHost(\Laminas\View\Renderer\PhpRenderer $view, $mode): void
    {
        if (self::normalizeMode($mode) !== self::MODE_SECURE) {
            return;
        }
        // Policy must load before the host (the host reads window.exeMediaPolicy at eval).
        $view->headScript()->appendFile($view->assetUrl('js/exe-media-policy.js', 'ExeLearning'));
        $view->headScript()->appendFile($view->assetUrl('js/exe-media-host.js', 'ExeLearning'));
        // Attach the host to every content iframe on the page (promoted players excluded).
        // attach() is harmless on non-eXe iframes (no hello arrives), so scanning mirrors
        // the relay's source-discovery without needing a fixed iframe id.
        $view->headScript()->appendScript(
            '(function(){function a(){if(!window.exeMediaHost)return;'
            . 'var f=document.getElementsByTagName("iframe");for(var i=0;i<f.length;i++){'
            . 'if(f[i].getAttribute("data-exe-embed-player"))continue;'
            . 'if(f[i].getAttribute("data-exe-media-attached"))continue;'
            . 'f[i].setAttribute("data-exe-media-attached","1");window.exeMediaHost.attach(f[i],{});}}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",a);}else{a();}})();'
        );
    }
}

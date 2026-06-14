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
     * The value is resolved by self::embedMode(); an unset key resolves to 'open'.
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

    /** Legacy tokens: the previous same-origin behaviour, kept as an explicit opt-out. */
    private const LEGACY_TOKENS = 'allow-same-origin allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox';

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
        return $value === self::MODE_LEGACY ? self::MODE_LEGACY : self::MODE_SECURE;
    }

    /**
     * Resolve the external-embed policy (DEC-0061). Default 'open' promotes any
     * cross-origin https iframe (the player is sandboxed + cross-origin, so SOP
     * isolates it from the Omeka page); 'strict' restricts to the maintained host
     * allowlist. An unrecognised (tampered) value fails to 'strict' (toward the more
     * restrictive), while an unset value keeps the intended 'open' default.
     *
     * Mirrors mod_exelearning's canonical embedmode resolver; uses a strict
     * comparison so non-string values (arrays, objects, booleans) never warn/throw.
     *
     * @param mixed $value
     * @return string self::EMBED_OPEN or self::EMBED_STRICT
     */
    public static function embedMode($value = null): string
    {
        if ($value === false || $value === null || $value === '') {
            return self::EMBED_OPEN;
        }
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
}

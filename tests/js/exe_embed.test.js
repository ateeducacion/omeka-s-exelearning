// Unit tests for the secure-mode external-embed RELAY (DEC-0061). The relay (parent) is
// the authoritative gate: in 'open' mode the structural invariant (https + cross-origin
// to the host), in 'strict' mode the maintained host allowlist. This mirrors the RELAY
// describe-blocks of the canonical mod_exelearning suite (tests/js/exe_embed.test.js);
// the shim describe-blocks are dropped here (the in-iframe shim is covered by the
// Playwright e2e under tests/e2e/). The relay is an auto-running IIFE that also exposes
// its API on module.exports for Node/Vitest. Globals come from vitest.config.mjs.
const relay = require('../../asset/js/exe-embed-relay.js');

const HOSTS = [
    'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com',
    'youtube-nocookie.com', 'player.vimeo.com', 'vimeo.com',
    'www.dailymotion.com', 'dailymotion.com', 'geo.dailymotion.com',
    'mediateca.educa.madrid.org',
];
const STRICT = { strict: true, whitelist: relay.buildWhitelist(HOSTS) };
const ORIGIN = window.location.origin;       // happy-dom default (the "host" origin here).
const CONTENT_SRC = ORIGIN + '/content/3/index.html';

describe('exe_embed_relay validate() — open mode (default): structural invariant', () => {
    it('accepts any cross-origin https video iframe verbatim (no host list, no reconstruction)', () => {
        expect(relay.validate('https://www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC))
            .toEqual({ url: 'https://www.youtube.com/embed/aqz-KE-bpKQ', kind: 'video' });
        expect(relay.validate('https://some-new-provider.example/player/42', CONTENT_SRC))
            .toEqual({ url: 'https://some-new-provider.example/player/42', kind: 'video' });
    });

    it('rejects same-origin (the host itself)', () => {
        expect(relay.validate(ORIGIN + '/page?id=2', CONTENT_SRC)).toBeNull();
    });

    it('rejects non-https', () => {
        expect(relay.validate('http://www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC)).toBeNull();
    });

    it('rejects userinfo (https://evil.com@youtube.com/...)', () => {
        expect(relay.validate('https://evil.com@www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC)).toBeNull();
    });

    it('rejects IP-literal and loopback/local hosts', () => {
        expect(relay.validate('https://1.2.3.4/player', CONTENT_SRC)).toBeNull();
        expect(relay.validate('https://[2001:db8::1]/player', CONTENT_SRC)).toBeNull();
        expect(relay.validate('https://localhost/player', CONTENT_SRC)).toBeNull();
        expect(relay.validate('https://intranet.local/player', CONTENT_SRC)).toBeNull();
    });

    it('rejects non-http(s) schemes (data:/javascript:/blob:)', () => {
        expect(relay.validate('data:text/html,<h1>x</h1>', CONTENT_SRC)).toBeNull();
        expect(relay.validate('javascript:alert(1)', CONTENT_SRC)).toBeNull();
        expect(relay.validate('blob:https://x.test/uuid', CONTENT_SRC)).toBeNull();
    });

    it('rejects a relative URL (the shim must report absolute)', () => {
        expect(relay.validate('files/local.pdf', CONTENT_SRC)).toBeNull();
        expect(relay.validate('/admin/secret', CONTENT_SRC)).toBeNull();
    });
});

describe('exe_embed_relay validate() — PDFs (always allowed by structure)', () => {
    it('accepts any cross-origin https PDF (no sameorigin flag)', () => {
        expect(relay.validate('https://example.com/docs/report.pdf', CONTENT_SRC))
            .toEqual({ url: 'https://example.com/docs/report.pdf', kind: 'pdf' });
    });

    it('accepts a same-origin PDF under the content directory (flagged sameorigin)', () => {
        const pdf = ORIGIN + '/content/3/files/local.pdf';
        expect(relay.validate(pdf, CONTENT_SRC)).toEqual({ url: pdf, kind: 'pdf', sameorigin: true });
    });

    it('rejects a same-origin PDF outside the package (e.g. an admin route)', () => {
        expect(relay.validate(ORIGIN + '/admin/secret.pdf', CONTENT_SRC)).toBeNull();
    });

    it('rejects an http PDF', () => {
        expect(relay.validate('http://example.com/x.pdf', CONTENT_SRC)).toBeNull();
    });
});

describe('exe_embed_relay validate() — strict mode (opt-in allowlist)', () => {
    it('rebuilds the canonical youtube-nocookie URL from a youtube.com embed', () => {
        expect(relay.validate('https://www.youtube.com/embed/aqz-KE-bpKQ', CONTENT_SRC, STRICT))
            .toEqual({ url: 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ', kind: 'video' });
    });

    it('rebuilds the canonical Vimeo / Dailymotion / EducaMadrid URLs', () => {
        expect(relay.validate('https://player.vimeo.com/video/76979871', CONTENT_SRC, STRICT).url)
            .toBe('https://player.vimeo.com/video/76979871');
        expect(relay.validate('https://www.dailymotion.com/embed/video/x8abc12', CONTENT_SRC, STRICT).url)
            .toBe('https://www.dailymotion.com/embed/video/x8abc12');
        expect(relay.validate('https://mediateca.educa.madrid.org/video/u555bvi3bk5wsabh', CONTENT_SRC, STRICT).url)
            .toBe('https://mediateca.educa.madrid.org/video/u555bvi3bk5wsabh/fs');
    });

    it('rejects a non-whitelisted cross-origin https host (unlike open mode)', () => {
        expect(relay.validate('https://some-new-provider.example/player/42', CONTENT_SRC, STRICT)).toBeNull();
        expect(relay.validate('https://example.com/', CONTENT_SRC, STRICT)).toBeNull();
    });

    it('rejects look-alike hosts and malformed ids', () => {
        expect(relay.validate('https://www.youtube.com.evil.com/embed/aqz-KE-bpKQ', CONTENT_SRC, STRICT)).toBeNull();
        expect(relay.validate('https://www.youtube.com/embed/', CONTENT_SRC, STRICT)).toBeNull();
        expect(relay.validate('https://player.vimeo.com/video/not-a-number', CONTENT_SRC, STRICT)).toBeNull();
    });

    it('still accepts cross-origin PDFs in strict mode', () => {
        expect(relay.validate('https://example.com/x.pdf', CONTENT_SRC, STRICT))
            .toEqual({ url: 'https://example.com/x.pdf', kind: 'pdf' });
    });
});

describe('exe_embed_relay structural helpers', () => {
    it('isIpOrLocalHost flags IP literals and loopback/local names', () => {
        ['1.2.3.4', '255.0.0.1', '[::1]', '[2001:db8::1]', 'localhost', 'x.localhost', 'host.local', ''].forEach(
            (h) => expect(relay.isIpOrLocalHost(h)).toBe(true)
        );
        ['youtube.com', 'player.vimeo.com', 'example.org'].forEach(
            (h) => expect(relay.isIpOrLocalHost(h)).toBe(false)
        );
    });

    it('isRelatedToLms flags the host, its subdomains and superdomains (dotted boundary)', () => {
        expect(relay.isRelatedToLms('lms.example.org', 'lms.example.org')).toBe(true);   // equal
        expect(relay.isRelatedToLms('cdn.lms.example.org', 'lms.example.org')).toBe(true); // subdomain
        expect(relay.isRelatedToLms('example.org', 'lms.example.org')).toBe(true);        // superdomain
        expect(relay.isRelatedToLms('evil-lms.example.org', 'lms.example.org')).toBe(false); // look-alike
        expect(relay.isRelatedToLms('youtube.com', 'lms.example.org')).toBe(false);
    });

    it('isRelatedToLms normalises the trailing-dot FQDN-root form (no host. bypass)', () => {
        // 'lms.example.org.' resolves to the same vhost but compares unequal as a raw
        // string; without normalisation it would slip past the related-to-host gate and
        // be promoted as a cross-origin player with allow-same-origin.
        expect(relay.isRelatedToLms('lms.example.org.', 'lms.example.org')).toBe(true);   // dotted host
        expect(relay.isRelatedToLms('lms.example.org', 'lms.example.org.')).toBe(true);   // dotted lmsHost
        expect(relay.isRelatedToLms('cdn.lms.example.org.', 'lms.example.org')).toBe(true); // dotted subdomain
        expect(relay.normalizeHost('LMS.Example.ORG.')).toBe('lms.example.org');
    });
});

describe('exe_embed_relay makePlayer() — sandboxed players', () => {
    it('video player is sandboxed with allow-same-origin but NOT top-navigation/modals', () => {
        const frame = relay.makePlayer({ url: 'https://www.youtube.com/embed/abc123', kind: 'video' });
        const sb = frame.getAttribute('sandbox');
        expect(sb).toContain('allow-scripts');
        expect(sb).toContain('allow-same-origin');   // cross-origin src keeps its own origin; renders.
        expect(sb).not.toContain('allow-top-navigation');
        expect(sb).not.toContain('allow-modals');
        expect(frame.getAttribute('data-exe-embed-player')).toBe('1'); // excluded from message auth
        expect(frame.getAttribute('allow')).toContain('autoplay');
        expect(frame.getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
    });

    it('cross-origin PDF player is sandboxed allow-same-origin (no scripts/top-nav)', () => {
        const frame = relay.makePlayer({ url: 'https://files.test/manual.pdf', kind: 'pdf' });
        const sb = frame.getAttribute('sandbox');
        expect(sb).toBe('allow-same-origin'); // cannot top-navigate the host tab to phishing
        expect(sb).not.toContain('allow-scripts');
        expect(sb).not.toContain('allow-top-navigation');
        expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
    });

    it('same-origin package PDF player is unsandboxed (the browser PDF viewer needs it)', () => {
        const frame = relay.makePlayer({ url: 'https://files.test/manual.pdf', kind: 'pdf', sameorigin: true });
        expect(frame.hasAttribute('sandbox')).toBe(false);
        expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
    });
});

describe('exe_embed_relay createRelay() overlays players from messages', () => {
    let iframe;
    beforeEach(() => {
        document.body.innerHTML = '';
        iframe = document.createElement('iframe');
        document.body.appendChild(iframe);
    });

    it('creates an inline overlay player for a valid embed and removes it when no longer reported', () => {
        const r = relay.createRelay({ mode: 'open' });
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'e1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
            },
        });
        const players = document.querySelectorAll('.exe-embed-overlay iframe');
        expect(players.length).toBe(1);
        expect(players[0].src).toMatch(/www\.youtube\.com\/embed\/abc123$/);   // verbatim in open mode

        r.onMessage({ source: iframe.contentWindow, data: { type: 'exe-embed', action: 'sync', embeds: [] } });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });

    it('replaces the player when a reused embed id navigates to a different URL (no lingering video)', () => {
        const r = relay.createRelay({ mode: 'open' });
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'exe-embed-1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
            },
        });
        expect(document.querySelector('.exe-embed-overlay iframe').src).toMatch(/www\.youtube\.com\/embed\/abc123$/);

        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'exe-embed-1', url: 'https://player.vimeo.com/video/12345', x: 0, y: 0, w: 425, h: 350 }],
            },
        });
        const players = document.querySelectorAll('.exe-embed-overlay iframe');
        expect(players.length).toBe(1);
        expect(players[0].src).toMatch(/player\.vimeo\.com\/video\/12345$/);
        expect(players[0].src).not.toMatch(/youtube/);
    });

    it('never treats a promoted player as a content source (forged-message defence)', () => {
        const r = relay.createRelay({ mode: 'open' });
        // A sandboxed player with allow-same-origin must not be able to impersonate the
        // content iframe and inject embeds: tag an iframe like a player and verify a
        // message from it is ignored.
        const player = document.createElement('iframe');
        player.setAttribute('data-exe-embed-player', '1');
        document.body.appendChild(player);
        r.onMessage({
            source: player.contentWindow,
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'x', url: 'https://evil.example/phish', x: 0, y: 0, w: 100, h: 100 }],
            },
        });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });

    it('ignores a message whose source is not a known content iframe', () => {
        const r = relay.createRelay({ mode: 'open' });
        r.onMessage({
            source: {},
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'x', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 1, h: 1 }],
            },
        });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });

    it('ignores non-embed messages', () => {
        const r = relay.createRelay({ mode: 'open' });
        r.onMessage({ source: iframe.contentWindow, data: { type: 'scorm', action: 'track', cmi: {} } });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(0);
    });

    it('checkDrift() re-pins an overlay whose content iframe moved without any event', () => {
        const r = relay.createRelay({ mode: 'open' });
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'e1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
            },
        });
        const overlay = document.querySelector('.exe-embed-overlay');
        // Nothing moved yet: the drift check must be a no-op.
        expect(r.checkDrift()).toBe(0);
        // The host toggles a sidebar: the iframe box shifts with no scroll/resize event.
        iframe.getBoundingClientRect = () => ({ left: 120, top: 30, width: 500, height: 320, right: 620, bottom: 350 });
        expect(r.checkDrift()).toBe(1);
        expect(overlay.style.left).toBe('120px');
        expect(overlay.style.top).toBe('30px');
        expect(overlay.style.width).toBe('500px');
        // Settled: a second pass changes nothing.
        expect(r.checkDrift()).toBe(0);
    });

    it('dispose() tears down overlays like clear() and can be called before init()', () => {
        const r = relay.createRelay({ mode: 'open' });
        r.onMessage({
            source: iframe.contentWindow,
            data: {
                type: 'exe-embed', action: 'sync',
                embeds: [{ id: 'e1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
            },
        });
        expect(document.querySelectorAll('.exe-embed-overlay iframe').length).toBe(1);

        r.dispose();
        expect(document.querySelectorAll('.exe-embed-overlay').length).toBe(0);
        // Idempotent: a second dispose() (or one before init) must not throw.
        expect(() => r.dispose()).not.toThrow();
    });
});

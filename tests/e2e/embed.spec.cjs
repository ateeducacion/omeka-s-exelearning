// Cross-browser (Firefox) e2e for the secure-mode external embeds (DEC-0061): the
// in-iframe shim + parent relay promote every cross-origin / PDF iframe to a sandboxed
// inline player on the parent page (open mode, the default). Mirrors the canonical
// mod_exelearning e2e.
const { test, expect } = require('@playwright/test');
test('promotes every cross-origin/PDF iframe to a sandboxed inline player (open mode, Firefox)', async ({ page }) => {
    await page.goto('/tests/e2e/embed/parent.html');
    const players = page.locator('.exe-embed-overlay iframe');
    await expect.poll(() => players.count(), { timeout: 15000 }).toBe(3);
    const srcs = await players.evaluateAll((els) => els.map((e) => e.src));
    // Open mode: cross-origin https iframes are promoted VERBATIM (no host list).
    expect(srcs.some((s) => /^https:\/\/www\.youtube-nocookie\.com\/embed\/aqz-KE-bpKQ\b/.test(s))).toBe(true);
    // An arbitrary cross-origin provider is promoted too (the structural invariant).
    expect(srcs.some((s) => /^https:\/\/example\.com\//.test(s))).toBe(true);
    // The relative local PDF is reported absolute and rendered.
    expect(srcs.some((s) => /\/local\.pdf$/.test(s))).toBe(true);
    // The promoted video players are sandboxed (allow-same-origin to render, no top-navigation).
    const sandboxes = await players.evaluateAll((els) => els.map((e) => e.getAttribute('sandbox') || ''));
    expect(sandboxes.some((s) => s.includes('allow-same-origin') && !s.includes('allow-top-navigation'))).toBe(true);
});

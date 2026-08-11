import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        globals: true,
        environment: 'happy-dom',
        // Unit tests for the external-embed relay live under tests/js/. The Playwright
        // e2e (tests/e2e/embed.spec.cjs) has its own runner (playwright-embed.config.cjs,
        // testDir 'tests/e2e'), so the two globs never collide.
        include: ['tests/js/**/*.test.js'],
    },
});

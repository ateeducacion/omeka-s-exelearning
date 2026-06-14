const { defineConfig, devices } = require('@playwright/test');
const PORT = 8128;
module.exports = defineConfig({
    testDir: 'tests/e2e',
    testMatch: 'embed.spec.cjs',
    timeout: 30000,
    fullyParallel: false,
    use: { baseURL: 'http://localhost:' + PORT },
    webServer: { command: 'python3 -m http.server ' + PORT, port: PORT, reuseExistingServer: false, timeout: 30000 },
    projects: [{ name: 'firefox', use: { ...devices['Desktop Firefox'] } }],
});

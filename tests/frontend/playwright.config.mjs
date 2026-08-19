import { defineConfig } from '@playwright/test';

const PORT = 8790;

export default defineConfig({
    testDir: '.',
    testMatch: 'smoke.spec.mjs',
    timeout: 30_000,
    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
    },
    webServer: {
        command: `node ${import.meta.dirname}/fixture-server.mjs`,
        port: PORT,
        reuseExistingServer: true,
        env: { PORT: String(PORT) },
    },
});

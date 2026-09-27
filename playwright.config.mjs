import { defineConfig, devices } from '@playwright/test';

/**
 * Base URLs are set by tests/docker/compose.yml per service:
 *   e2e       -> TRACKWP_E2E_BASE_URL=http://wp,    TRACKWP_CACHE_BASE_URL=http://cache
 *   e2e-wp62  -> TRACKWP_E2E_BASE_URL=http://wp62
 * Local (non-Docker) runs can override these to point at any reachable WP.
 */
const baseURL = process.env.TRACKWP_E2E_BASE_URL || 'http://localhost:8080';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30000,
    expect: { timeout: 5000 },
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        video: 'off',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});

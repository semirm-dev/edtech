import { defineConfig, devices } from '@playwright/test';

/**
 * Course Discovery E2E config.
 *
 * These specs cover the browser behaviour PHPUnit cannot reach: filtering
 * with JavaScript disabled, and keyboard-only combobox operation. They run
 * against a real, already-running, seeded WordPress site -- there is no dev
 * server to boot here, just a baseURL.
 *
 * baseURL defaults to the DDEV site. Point it at any other environment
 * with CD_E2E_URL, e.g.:
 *
 *   CD_E2E_URL=http://localhost:8080 npx playwright test
 */
const baseURL = process.env.CD_E2E_URL || 'https://edtech.ddev.site';

export default defineConfig({
	testDir: './tests',
	fullyParallel: true,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: [['list'], ['html', { open: 'never' }]],

	use: {
		baseURL,
		// DDEV's local site uses a self-signed cert.
		ignoreHTTPSErrors: true,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			// JavaScript enabled -- the keyboard combobox only exists
			// once course-discovery.js has run.
			name: 'js-enabled',
			testMatch: ['keyboard.spec.ts', 'enhancement.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
		{
			// JavaScript disabled -- proves the plain <form method="get"> baseline
			// (what FormRenderer/ResultsRenderer render) works with no enhancement
			// layer at all.
			name: 'js-disabled',
			testMatch: ['no-js.spec.ts', 'pagination.spec.ts'],
			use: { ...devices['Desktop Chrome'], javaScriptEnabled: false },
		},
	],
});

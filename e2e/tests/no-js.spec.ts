import { test, expect } from '@playwright/test';

/**
 * With JavaScript disabled (see the 'js-disabled' project in
 * playwright.config.ts), course-discovery.js never runs. This proves the
 * plain <form method="get"> baseline that FormRenderer/ResultsRenderer emit
 * works on its own, with no JavaScript involved at all.
 *
 * Assertions are RELATIVE to the seed data, not hardcoded counts: pick a
 * provider tied to only one of the two seeded courses, and assert that
 * filtering by it narrows the result set (the matching course stays, the
 * other disappears) rather than asserting an exact total.
 */

test.describe('filtering without JavaScript', () => {
	test('submitting a provider filter narrows results via a real page load', async ({ page }) => {
		await page.goto('/find-courses/');

		const form = page.locator('form.cd-search-form');
		const results = page.locator('.cd-results');

		await expect(form).toBeVisible();
		await expect(results).toBeVisible();

		// Baseline: both seeded courses are visible with no filters applied.
		await expect(results.getByText('Graphic Design Foundation')).toBeVisible();
		await expect(results.getByText('Data Science Essentials')).toBeVisible();

		// "University of Sunderland" only provides the Graphic Design course
		// in the seed data (see cd_course_providers meta on that course) --
		// "De Montfort University" provides both, so it would not narrow
		// anything and would make a weak assertion.
		const providerCheckbox = form.getByLabel('University of Sunderland');

		// Post IDs shift on every bin/seed.sh reseed, so the value this
		// checkbox carries is read from the DOM rather than hardcoded.
		const providerId = await providerCheckbox.getAttribute('value');
		expect(providerId).toBeTruthy();

		await providerCheckbox.check();

		// No JS: this is a real GET form submission, a full page navigation.
		await form.getByRole('button', { name: 'Search' }).click();
		await page.waitForURL((url) => url.searchParams.has('provider[]'));

		// The submitted GET carries the checked provider as a query param.
		const url = new URL(page.url());
		expect(url.searchParams.getAll('provider[]')).toContain(providerId);

		// Result set narrowed relatively: the course on that provider is
		// still present, the course NOT on that provider is now gone.
		await expect(page.locator('.cd-results').getByText('Graphic Design Foundation')).toBeVisible();
		await expect(page.locator('.cd-results').getByText('Data Science Essentials')).toHaveCount(0);
	});
});

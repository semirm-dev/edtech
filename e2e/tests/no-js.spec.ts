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

	test('changing sort and submitting applies it without JavaScript', async ({ page }) => {
		await page.goto('/find-courses/');

		const form = page.locator('form.cd-search-form');
		const sort = page.locator('#cd-sort');
		const titles = page.locator('.cd-result-title');

		// A real <select> in the form, not a JS widget.
		await expect(sort).toBeVisible();
		await expect(sort).toHaveValue('soonest');

		const soonestOrder = await titles.allInnerTexts();
		expect(soonestOrder.length).toBeGreaterThan(1);

		await sort.selectOption('price_asc');

		// No JS: nothing has submitted yet, so the sort is not applied.
		expect(new URL(page.url()).searchParams.has('sort')).toBe(false);

		await form.getByRole('button', { name: 'Search' }).click();
		await page.waitForURL((url) => url.searchParams.get('sort') === 'price_asc');

		// The applied sort round-trips into the control, so the page states
		// how it is currently ordered rather than resetting to the default.
		await expect(page.locator('#cd-sort')).toHaveValue('price_asc');

		// And the results really are in that order. Without this, a renderer
		// that echoed ?sort= back into the control while ignoring it in the
		// query would pass every other assertion in this test.
		const priceOrder = await titles.allInnerTexts();
		expect(priceOrder.length).toBeGreaterThan(1);
		expect(priceOrder).not.toEqual(soonestOrder);
	});

	/**
	 * Chips are plain <a> links to a URL with one value dropped, so removing a
	 * filter is a page load like everything else here -- which is why this
	 * belongs with the no-JS specs rather than the enhancement ones.
	 */
	test('an applied filter shows a chip whose link removes just that filter', async ({ page }) => {
		await page.goto('/find-courses/');

		const form = page.locator('form.cd-search-form');
		const results = page.locator('.cd-results');
		const summary = page.locator('.cd-filters-summary');

		// Nothing applied: no chip block at all, and the panel header is bare.
		await expect(page.locator('.cd-active-filters')).toHaveCount(0);
		await expect(summary).toHaveText('Filters');
		await expect(results.getByText('Data Science Essentials')).toBeVisible();

		// Same provider as the test above: it provides only the Graphic Design
		// course in the seed, so applying it genuinely narrows the results.
		await form.getByLabel('University of Sunderland').check();
		await form.getByRole('button', { name: 'Search' }).click();
		await page.waitForURL((url) => url.searchParams.has('provider[]'));

		const chip = page.locator('.cd-active-filters .cd-chip');

		await expect(chip).toHaveCount(1);
		await expect(chip).toContainText('University of Sunderland');
		await expect(chip).toHaveAttribute('aria-label', 'Remove filter: University of Sunderland');

		// The panel header and the chips are driven by one count, so they agree.
		await expect(summary).toHaveText('Filters (1)');
		await expect(results.getByText('Data Science Essentials')).toHaveCount(0);

		await chip.click();
		await page.waitForURL((url) => !url.searchParams.has('provider[]'));

		// Back to where we started: the filter is gone from the URL, the block
		// and the count are gone with it, and the result set has widened again.
		await expect(page.locator('.cd-active-filters')).toHaveCount(0);
		await expect(page.locator('.cd-filters-summary')).toHaveText('Filters');
		await expect(page.locator('.cd-results').getByText('Data Science Essentials')).toBeVisible();
		await expect(page.locator('.cd-results').getByText('Graphic Design Foundation')).toBeVisible();
	});
});

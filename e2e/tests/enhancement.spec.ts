import { test, expect } from '@playwright/test';

/**
 * The two behaviours course-discovery.js adds beyond the combobox upgrade:
 * submitting on sort change, and collapsing the filter panel on a narrow
 * viewport. Both need JavaScript ON, so this spec belongs to the
 * 'js-enabled' project -- see playwright.config.ts. Neither is reachable
 * from PHPUnit, which is why they live here.
 */

test.describe('sort auto-submits when JavaScript is on', () => {
	test('changing sort reloads with the new order applied', async ({ page }) => {
		await page.goto('/find-courses/');

		const sort = page.locator('#cd-sort');
		await expect(sort).toHaveValue('soonest');

		const titles = page.locator('.cd-result-title');
		const soonestOrder = await titles.allInnerTexts();
		expect(soonestOrder.length).toBeGreaterThan(1);

		// No Search click: the change event alone must submit the form.
		await Promise.all([
			page.waitForURL((url) => url.searchParams.get('sort') === 'price_asc'),
			sort.selectOption('price_asc'),
		]);

		await expect(page.locator('#cd-sort')).toHaveValue('price_asc');

		// The results are actually ordered differently, not merely labelled so:
		// a renderer that echoed ?sort= back into the control while ignoring it
		// in the query would satisfy every other assertion here.
		const priceOrder = await titles.allInnerTexts();
		expect(priceOrder.length).toBeGreaterThan(1);
		expect(priceOrder).not.toEqual(soonestOrder);
	});

	test('toggling a combobox option does not submit the form', async ({ page }) => {
		await page.goto('/find-courses/');

		const urlBefore = page.url();
		const trigger = page.locator('#cd-filter-location');

		// A navigation replaces the document and everything written on it, so
		// this marker still being there after the toggle is what proves none
		// happened. `expect(page.url())` cannot prove it: it is a synchronous
		// snapshot taken before a just-triggered navigation could have
		// committed, so it would read the old URL either way.
		await page.evaluate(() => {
			document.documentElement.dataset.cdSurvivedToggle = 'yes';
		});

		await trigger.click();
		await page.locator('#cd-filter-location-listbox [role="option"]').first().click();

		// Long enough for a submission triggered by the toggle to have landed.
		await page.waitForTimeout(1000);

		expect(await page.evaluate(() => document.documentElement.dataset.cdSurvivedToggle)).toBe('yes');
		expect(page.url()).toBe(urlBefore);

		// Still the same document, with the combobox still open: the bubbling
		// 'change' that toggleOption() dispatches reached no form-level
		// listener. Scoping the sort listener to the select is what prevents it.
		await expect(page.locator('#cd-filter-location-listbox')).toBeVisible();
	});
});

test.describe('filter panel on a narrow viewport', () => {
	test('collapses so results are on screen first', async ({ page }) => {
		await page.setViewportSize({ width: 480, height: 900 });
		await page.goto('/find-courses/');

		const filters = page.locator('.cd-filters');

		await expect(filters).toBeVisible();
		await expect(filters).not.toHaveAttribute('open', '');

		// The summary is a real disclosure control: it still opens.
		await page.locator('.cd-filters-summary').click();
		await expect(filters).toHaveAttribute('open', '');
	});
});

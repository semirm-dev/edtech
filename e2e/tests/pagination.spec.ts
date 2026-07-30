import { test, expect } from '@playwright/test';

/**
 * Pagination can only be proven over real HTTP. Rendering the shortcode in
 * PHPUnit skips WordPress's `redirect_canonical()`, which is exactly where
 * this used to break: `paged` is a reserved WordPress query var, so a link
 * to `?paged=2` was 301'd to the pretty `/page/2/` -- dropping the parameter
 * and silently re-rendering page 1 with a "page 2" URL in the address bar.
 * Hence SearchCriteria::PARAM_PAGE is `cd_paged`, and hence this spec.
 *
 * Runs with JavaScript disabled: pagination is plain <a> links, and it must
 * stay that way. Assertions are RELATIVE to the seed data -- more courses
 * than fit on one page, page 2 showing a different set -- rather than
 * hardcoded titles or counts.
 */

test.describe('paginating results', () => {
	test('following a page link loads a different page of results', async ({ page }) => {
		await page.goto('/find-courses/');

		const results = page.locator('.cd-results');
		const titles = results.locator('.cd-result-title');

		await expect(results).toBeVisible();

		const firstPageTitles = await titles.allInnerTexts();
		expect(firstPageTitles.length).toBeGreaterThan(0);

		// The seed holds more courses than one page, so the nav exists at all.
		// Addressed by accessible name ("Page 2"), not link text, so a
		// two-digit page number can never match by substring.
		const pageTwoLink = page.getByRole('link', { name: 'Page 2', exact: true });
		await expect(pageTwoLink).toBeVisible();

		await pageTwoLink.click();

		// No 301 back to a canonical WordPress URL: the parameter we sent is
		// the parameter still in the address bar after the navigation.
		expect(new URL(page.url()).searchParams.get('cd_paged')).toBe('2');

		const secondPageTitles = await titles.allInnerTexts();
		expect(secondPageTitles.length).toBeGreaterThan(0);

		// The decisive assertion: page 2 is a genuinely different result set,
		// not page 1 re-rendered under a different URL.
		expect(secondPageTitles).not.toEqual(firstPageTitles);

		for (const title of secondPageTitles) {
			expect(firstPageTitles).not.toContain(title);
		}

		// Page 2 is marked current, and page 1 is still reachable.
		await expect(page.locator('.cd-pagination a[aria-current="page"]')).toHaveText('2');
		await expect(page.getByRole('link', { name: 'Page 1', exact: true })).toBeVisible();
	});

	test('an out-of-range page number degrades instead of erroring', async ({ page }) => {
		// Pagination::clamp() only bounds against MAX_PAGE, so a page past the
		// end still reaches ResultsRenderer -- which must say so rather than
		// print a positive count over an empty list.
		await page.goto('/find-courses/?cd_paged=9999');

		await expect(page.locator('.cd-results')).toBeVisible();
		await expect(page.locator('.cd-result-title')).toHaveCount(0);
		await expect(page.locator('.cd-results')).toContainText('out of range');
	});

	test('prev and next appear only where there is somewhere to go', async ({ page }) => {
		await page.goto('/find-courses/');

		// Page 1: nowhere back to.
		await expect(page.getByRole('link', { name: 'Previous page' })).toHaveCount(0);

		const next = page.getByRole('link', { name: 'Next page' });
		await expect(next).toBeVisible();
		await next.click();

		await expect(page.getByRole('link', { name: 'Previous page' })).toBeVisible();
		await expect(page.locator('.cd-pagination a[aria-current="page"]')).toHaveText('2');

		// The seed is two pages, so page 2 is the last one.
		await expect(page.getByRole('link', { name: 'Next page' })).toHaveCount(0);
	});
});

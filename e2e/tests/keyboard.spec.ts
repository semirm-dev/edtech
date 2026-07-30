import { test, expect, type Page } from '@playwright/test';

/**
 * The ARIA combobox that course-discovery.js layers over each
 * <select multiple> (see enhanceCombobox() in assets/course-discovery.js)
 * must be fully operable without a mouse: Tab to reach it, arrow keys to
 * move through options, Enter/Space to toggle a selection, Escape to close
 * and return focus. No mouse events are used anywhere in this file.
 */

const TRIGGER_ID = 'cd-filter-location';
const LISTBOX_ID = 'cd-filter-location-listbox';

/** Presses Tab repeatedly until the given element id has focus, or fails. */
async function tabTo(page: Page, targetId: string, maxTabs = 50): Promise<void> {
	for (let i = 0; i < maxTabs; i++) {
		const activeId = await page.evaluate(() => document.activeElement?.id ?? '');
		if (activeId === targetId) {
			return;
		}
		await page.keyboard.press('Tab');
	}
	throw new Error(`Could not reach #${targetId} by pressing Tab (gave up after ${maxTabs} presses)`);
}

test.describe('location combobox, keyboard only', () => {
	test('open, select, and close using only the keyboard', async ({ page }) => {
		await page.goto('/find-courses/');

		const trigger = page.locator(`#${TRIGGER_ID}`);
		const listbox = page.locator(`#${LISTBOX_ID}`);
		const nativeSelect = page.locator('#cd-filter-location-native');

		// JS has upgraded the plain <select multiple> into a combobox trigger.
		await expect(trigger).toHaveAttribute('role', 'combobox');
		await expect(trigger).toHaveAttribute('aria-expanded', 'false');

		// Reach the trigger by Tab alone -- proves it is in the tab order.
		await tabTo(page, TRIGGER_ID);
		await expect(trigger).toBeFocused();

		// Down (closed) opens the popup and activates an option.
		await page.keyboard.press('ArrowDown');
		await expect(trigger).toHaveAttribute('aria-expanded', 'true');
		await expect(listbox).toBeVisible();

		// Arrow to the next option.
		await page.keyboard.press('ArrowDown');
		const activeDescendant = await trigger.getAttribute('aria-activedescendant');
		expect(activeDescendant).toBeTruthy();
		const activeOption = page.locator(`#${activeDescendant}`);
		await expect(activeOption).toHaveAttribute('aria-selected', 'false');
		const activeOptionText = (await activeOption.textContent())?.trim();

		// Enter toggles the active option on.
		await page.keyboard.press('Enter');
		await expect(activeOption).toHaveAttribute('aria-selected', 'true');

		// The hidden native <select> (the source of truth submitted with the
		// form) is kept in sync with the same choice.
		const selectedTexts = await nativeSelect.evaluate((el: HTMLSelectElement) =>
			Array.from(el.selectedOptions).map((o) => o.textContent?.trim())
		);
		expect(selectedTexts).toContain(activeOptionText);

		// Escape closes the popup and returns focus to the trigger.
		await page.keyboard.press('Escape');
		await expect(trigger).toHaveAttribute('aria-expanded', 'false');
		await expect(listbox).toBeHidden();
		await expect(trigger).toBeFocused();
	});
});

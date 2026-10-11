import { Locator, Page, expect } from '@playwright/test';
import { gotoAudit } from './auth';
import { uid } from './db';

/**
 * Driving Users & Groups -> Access Audit the way an administrator does.
 */

/** A member's row in the audit table. */
export const row = (page: Page, username: string): Locator =>
	page.locator(`#access_audit_row_${uid(username)}`);

export const fixButton = (page: Page, username: string): Locator =>
	row(page, username).locator('button.access-audit-fix');

/** Open the audit, every member listed or only those needing attention. */
export async function openAudit(page: Page, { all = false } = {}): Promise<void> {
	await gotoAudit(page, all ? '&show=all' : '');
	await expect(page.locator('#access_audit_count_checked')).toBeVisible();
}

/** The summary's counts. */
export async function counts(page: Page): Promise<Record<string, number>> {
	const result: Record<string, number> = {};
	for (const key of ['checked', 'ok', 'fix', 'unchecked', 'banned', 'shared', 'found']) {
		result[key] = Number(await page.locator(`#access_audit_count_${key}`).innerText());
	}
	return result;
}

/** Click a member's Fix button and wait for the audit to come back. */
export async function fix(page: Page, username: string): Promise<void> {
	await fixButton(page, username).click();
	await page.waitForURL(/module=user-access_audit(?!.*action=)/);
}

/** The page's flash message after a redirect. */
export const flash = (page: Page): Locator => page.locator('#flash_message');

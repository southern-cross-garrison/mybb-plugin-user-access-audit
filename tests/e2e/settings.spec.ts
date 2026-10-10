import { test, expect } from '../helpers/fixtures';
import { gotoAuditSettings, loginToAdminCp } from '../helpers/auth';
import { adminLog, execute, fixtures, getSetting, lookups, T } from '../helpers/db';
import { openAudit } from '../helpers/audit';
import { runPhp } from '../helpers/container';
import {
	OTHER_UNIT,
	OUR_UNIT,
	OUTPOST,
	legionApiRequests,
	stubFixtureMembers,
	stubUnits,
	unitsDown,
} from '../helpers/legion-api';

/**
 * Users & Groups -> Access Audit -> Settings: the unit, picked from the 501st API's list;
 * the Legion ID profile field; and the two groups the audit manages.
 */
const save = (page: import('@playwright/test').Page) =>
	page.locator('#access_audit_save_settings').click();

test.beforeEach(async ({ page }) => {
	await loginToAdminCp(page);
});

test("offers the 501st API's units, garrisons and outposts, by name", async ({ page }) => {
	await gotoAuditSettings(page);

	await expect(page.locator('#unit option')).toHaveText([
		'Choose...',
		'Midsouth Garrison',
		'Saudi Arabian Outpost (Outpost)',
		'Southern Cross Garrison',
	]);
	await expect(page.locator('#unit')).toHaveValue(String(OUR_UNIT.id));
	expect(legionApiRequests()).toEqual(['/garrisons']);
});

test("offers the board's profile fields and groups", async ({ page }) => {
	await gotoAuditSettings(page);

	await expect(page.locator('#legion_id_field option')).toHaveText([
		'Choose...',
		// MyBB's own.
		'Location',
		'Bio',
		'Gender',
		'Legion ID',
		'Costumes',
	]);
	await expect(page.locator('#legion_id_field')).toHaveValue(
		String(fixtures().profileFields.legion_id)
	);
	await expect(page.locator('#unit_group')).toHaveValue(String(fixtures().groups.unit));
	await expect(page.locator('#legion_group')).toHaveValue(String(fixtures().groups.legion));
	await expect(page.locator('#unit_group option', { hasText: 'Command Staff' })).toHaveCount(1);
});

test('saves the unit, its name, the field and the groups', async ({ page }) => {
	await gotoAuditSettings(page);

	await page.locator('#unit').selectOption(String(OTHER_UNIT.id));
	await page.locator('#legion_id_field').selectOption(String(fixtures().profileFields.costumes));
	await page.locator('#unit_group').selectOption(String(fixtures().groups.command));
	await page.locator('#legion_group').selectOption(String(fixtures().groups.unit));
	await save(page);

	await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
	expect(await getSetting('access_audit_unit')).toBe(String(OTHER_UNIT.id));
	expect(await getSetting('access_audit_unit_name')).toBe(OTHER_UNIT.name);
	expect(await getSetting('access_audit_legion_id_field')).toBe(
		String(fixtures().profileFields.costumes)
	);
	expect(await getSetting('access_audit_unit_group')).toBe(String(fixtures().groups.command));
	expect(await getSetting('access_audit_legion_group')).toBe(String(fixtures().groups.unit));

	const [entry] = await adminLog();
	expect([entry.module, entry.action]).toEqual(['user-access_audit', 'settings']);

	// The audit now goes by them.
	await openAudit(page);
	await expect(page.locator('#content')).toContainText(
		'Active members of Midsouth Garrison belong in both Command Staff and Garrison Members.'
	);
});

test("trims the API's stray spaces from a unit's name", async ({ page }) => {
	await gotoAuditSettings(page);
	await page.locator('#unit').selectOption(String(OUTPOST.id));
	await save(page);

	expect(await getSetting('access_audit_unit_name')).toBe(OUTPOST.name);
});

test('will not save the same group for both', async ({ page }) => {
	await gotoAuditSettings(page);
	await page.locator('#legion_group').selectOption(String(fixtures().groups.unit));
	await save(page);

	await expect(page.locator('.error')).toContainText(
		'The unit members group and the 501st members group must be different groups.'
	);
	expect(await getSetting('access_audit_legion_group')).toBe(String(fixtures().groups.legion));
	// What was chosen is still chosen.
	await expect(page.locator('#legion_group')).toHaveValue(String(fixtures().groups.unit));
});

test('will not save without every choice made', async ({ page }) => {
	await gotoAuditSettings(page);
	for (const select of ['#unit', '#legion_id_field', '#unit_group', '#legion_group']) {
		await page.locator(select).selectOption('0');
	}
	await page.locator('#api_url').fill('not a url');
	await save(page);

	await expect(page.locator('.error li')).toHaveText([
		"Enter the 501st Legion API's address, starting with http:// or https://.",
		"Choose this board's unit.",
		"Choose the profile field that holds members' Legion IDs.",
		'Choose the unit members group.',
		'Choose the 501st members group.',
	]);
	expect(await getSetting('access_audit_unit')).toBe(String(OUR_UNIT.id));
});

test('will not save a unit the API does not list', async ({ page }) => {
	await gotoAuditSettings(page);
	await page.locator('#unit').evaluate((select: HTMLSelectElement) => {
		select.add(new Option('Made Up Garrison', '9999'));
		select.value = '9999';
	});
	await save(page);

	await expect(page.locator('.error')).toContainText("Choose this board's unit.");
	expect(await getSetting('access_audit_unit')).toBe(String(OUR_UNIT.id));
});

test.describe('while the API is down', () => {
	test('says so, and still offers the unit already chosen', async ({ page }) => {
		unitsDown();
		await gotoAuditSettings(page);

		await expect(page.locator('#access_audit_units_unavailable')).toBeVisible();
		await expect(page.locator('#unit option')).toHaveText(['Choose...', OUR_UNIT.name]);
		await expect(page.locator('#unit')).toHaveValue(String(OUR_UNIT.id));
	});

	test('still saves everything else', async ({ page }) => {
		unitsDown();
		await gotoAuditSettings(page);
		await page.locator('#legion_id_field').selectOption(String(fixtures().profileFields.costumes));
		await save(page);

		await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
		expect(await getSetting('access_audit_legion_id_field')).toBe(
			String(fixtures().profileFields.costumes)
		);
		expect(await getSetting('access_audit_unit')).toBe(String(OUR_UNIT.id));
		expect(await getSetting('access_audit_unit_name')).toBe(OUR_UNIT.name);
	});

	test('cannot change the unit', async ({ page }) => {
		await gotoAuditSettings(page);
		unitsDown();
		await page.locator('#unit').selectOption(String(OTHER_UNIT.id));
		await save(page);

		await expect(page.locator('.error')).toContainText(
			'The list of units could not be fetched from the 501st Legion API, so the unit cannot be changed right now.'
		);
		expect(await getSetting('access_audit_unit')).toBe(String(OUR_UNIT.id));
	});

	test('treats a list with nothing usable in it as no list', async ({ page }) => {
		stubUnits({ body: { garrisons: [{ name: 'No id' }, 'nonsense'] } });
		await gotoAuditSettings(page);

		await expect(page.locator('#access_audit_units_unavailable')).toBeVisible();
	});
});

test('forgets answers from the old API when the address changes', async ({ page }) => {
	stubFixtureMembers();
	await openAudit(page);
	expect((await lookups()).length).toBeGreaterThan(0);

	await gotoAuditSettings(page);
	await page.locator('#api_url').fill('http://127.0.0.1/e2e-legion-api.php');
	await save(page);

	await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
	expect(await lookups()).toEqual([]);
});

test('keeps answers when the address stays the same', async ({ page }) => {
	stubFixtureMembers();
	await openAudit(page);
	const kept = (await lookups()).length;

	await gotoAuditSettings(page);
	await save(page);

	expect(await lookups()).toHaveLength(kept);
});

test('counts a deleted profile field or group as not chosen', async ({ page }) => {
	// Deleted out from under the settings, the way deleting it in the Admin CP would.
	await execute(
		`UPDATE ${T('settings')} SET value = '999' WHERE name = 'access_audit_legion_id_field'`
	);
	await execute(`UPDATE ${T('settings')} SET value = '998' WHERE name = 'access_audit_unit_group'`);
	await runPhp('rebuild_settings();');

	await page.goto('/admin/index.php?module=user-access_audit');
	await expect(page.locator('#access_audit_not_configured li')).toHaveText([
		'Legion ID profile field',
		'Unit members group',
	]);
});

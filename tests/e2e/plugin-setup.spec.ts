import fs from 'node:fs';
import path from 'node:path';
import { Page } from '@playwright/test';
import { test, expect } from '../helpers/fixtures';
import { gotoAudit, loginToAdminCp } from '../helpers/auth';
import { REPO_ROOT } from '../helpers/config';
import { runPhp } from '../helpers/container';
import { fixtures, getGroups, query, T } from '../helpers/db';
import { RowDataPacket } from 'mysql2/promise';

/**
 * Installing, activating, deactivating and uninstalling, through Configuration -> Plugins.
 */
const SETTINGS = [
	'access_audit_api_url',
	'access_audit_unit',
	'access_audit_unit_name',
	'access_audit_legion_id_field',
	'access_audit_unit_group',
	'access_audit_legion_group',
];

const pluginRow = (page: Page) =>
	page.locator('#content tr', { has: page.locator('strong', { hasText: /^Access Audit$/ }) });

async function clickPluginLink(page: Page, name: string): Promise<void> {
	await page.goto('/admin/index.php?module=config-plugins');
	await pluginRow(page).getByRole('link', { name, exact: true }).click();
	await expect(page.locator('#flash_message')).toBeVisible();
}

async function settingNames(): Promise<string[]> {
	const rows = await query<RowDataPacket>(
		`SELECT name FROM ${T('settings')} WHERE name LIKE 'access\\_audit\\_%' ORDER BY disporder`
	);
	return rows.map((row) => String(row.name));
}

async function tableExists(name: string): Promise<boolean> {
	return (await query<RowDataPacket>(`SHOW TABLES LIKE '${T(name)}'`)).length > 0;
}

/** Put the plugin back as provisioned, whatever a test left it as. */
async function reinstall(): Promise<void> {
	await runPhp(`
require_once MYBB_ROOT.'inc/plugins/access_audit.php';
if(!access_audit_is_installed()) { access_audit_install(); }
access_audit_activate();
$plugins_cache = $cache->read('plugins');
$plugins_cache['active']['access_audit'] = 'access_audit';
$cache->update('plugins', $plugins_cache);
echo "reinstalled";
`);
}

test.beforeEach(async ({ page }) => {
	await loginToAdminCp(page);
});

test('is installed with its table and settings', async ({ page }) => {
	expect(await tableExists('access_audit_lookups')).toBe(true);
	expect(await settingNames()).toEqual(SETTINGS);

	await page.goto('/admin/index.php?module=config-settings');
	await expect(page.locator('#content')).toContainText('Access Audit');
});

test('is under Users & Groups', async ({ page }) => {
	await page.goto('/admin/index.php?module=user-users');
	await page.locator('#left_menu').getByRole('link', { name: 'Access Audit' }).click();

	await expect(page).toHaveURL(/module=user-access_audit/);
	await expect(page.locator('#access_audit_count_checked')).toBeVisible();
});

test('goes from the Admin CP when deactivated, keeping its settings', async ({ page }) => {
	try {
		await clickPluginLink(page, 'Deactivate');

		await page.goto('/admin/index.php?module=user-users');
		await expect(
			page.locator('#left_menu').getByRole('link', { name: 'Access Audit' })
		).toHaveCount(0);
		await gotoAudit(page);
		await expect(page.locator('#access_audit_count_checked')).toHaveCount(0);
		expect(await settingNames()).toEqual(SETTINGS);

		await clickPluginLink(page, 'Activate');
		await gotoAudit(page);
		await expect(page.locator('#access_audit_count_checked')).toBeVisible();
	} finally {
		await reinstall();
	}
});

test("leaves nothing behind when uninstalled, and members' groups as they are", async ({
	page,
}) => {
	const before = await getGroups('unitmember');
	try {
		await clickPluginLink(page, 'Deactivate');
		await clickPluginLink(page, 'Uninstall');

		expect(await tableExists('access_audit_lookups')).toBe(false);
		expect(await tableExists('access_audit_schema_upgrades')).toBe(false);
		expect(await settingNames()).toEqual([]);
		expect(
			await query<RowDataPacket>(
				`SELECT gid FROM ${T('settinggroups')} WHERE name = 'access_audit'`
			)
		).toEqual([]);
		expect(await getGroups('unitmember')).toEqual(before);
		expect(before.additionalgroups).toEqual([fixtures().groups.unit]);

		await clickPluginLink(page, 'Install & Activate');
		expect(await settingNames()).toEqual(SETTINGS);
		// A fresh install starts at the real API, with nothing chosen.
		const values = await query<RowDataPacket>(
			`SELECT name, value FROM ${T('settings')} WHERE name IN ('access_audit_api_url', 'access_audit_unit')`
		);
		expect(Object.fromEntries(values.map((row) => [row.name, row.value]))).toEqual({
			access_audit_api_url: 'https://api.501st.com',
			access_audit_unit: '',
		});
	} finally {
		await reinstall();
	}
});

test('ships copies of the license and notice that match the repository', () => {
	for (const file of ['LICENSE', 'NOTICE']) {
		expect(
			fs.readFileSync(path.join(REPO_ROOT, 'plugin/inc/plugins/access_audit', file), 'utf8')
		).toBe(fs.readFileSync(path.join(REPO_ROOT, file), 'utf8'));
	}
});

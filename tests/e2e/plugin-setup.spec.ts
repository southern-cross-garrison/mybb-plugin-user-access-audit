import fs from 'node:fs';
import path from 'node:path';
import { Page } from '@playwright/test';
import { test, expect } from '../helpers/fixtures';
import { gotoAudit, loginToAdminCp } from '../helpers/auth';
import { REPO_ROOT } from '../helpers/config';
import { runPhp } from '../helpers/container';
import { execute, fixtures, getGroups, getSetting, lookups, query, T } from '../helpers/db';
import { setSettings } from '../helpers/settings';
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
	'access_audit_reserve_group',
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

async function columnNames(table: string): Promise<string[]> {
	return (await query<RowDataPacket>(`SHOW COLUMNS FROM ${T(table)}`)).map((row) =>
		String(row.Field)
	);
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

test.describe('the schema', () => {
	const DROP_PROFILE_URL = '0001_drop_lookups_profile_url';

	test('is brought up to date by install', async () => {
		expect(await columnNames('access_audit_lookups')).not.toContain('profile_url');
		const applied = await query<RowDataPacket>(
			`SELECT name FROM ${T('access_audit_schema_upgrades')}`
		);
		expect(applied.map((row) => row.name)).toContain(DROP_PROFILE_URL);
	});

	test('is brought up to date on a board that has an older one, by reactivating', async ({
		page,
	}) => {
		// The board as an earlier release left it, with an answer kept.
		await execute(
			`ALTER TABLE ${T('access_audit_lookups')} ADD profile_url varchar(255) NOT NULL DEFAULT ''`
		);
		await execute(`DELETE FROM ${T('access_audit_schema_upgrades')} WHERE name = ?`, [
			DROP_PROFILE_URL,
		]);
		await execute(
			`INSERT INTO ${T('access_audit_lookups')} (legion_id, member_status, full_name, profile_url, fetched_at)
			 VALUES (10001, 'Active', 'Fixture Trooper', 'https://www.501st.com/member/10001', UNIX_TIMESTAMP())`
		);
		try {
			await clickPluginLink(page, 'Deactivate');
			await clickPluginLink(page, 'Activate');

			expect(await columnNames('access_audit_lookups')).not.toContain('profile_url');
			const kept = await lookups();
			expect(kept.map((row) => [row.legion_id, row.full_name])).toEqual([
				[10001, 'Fixture Trooper'],
			]);
		} finally {
			// Run again, as after a failure part way: it has to be safe either way.
			await execute(`DELETE FROM ${T('access_audit_schema_upgrades')} WHERE name = ?`, [
				DROP_PROFILE_URL,
			]);
			await reinstall();
		}
		expect(await columnNames('access_audit_lookups')).not.toContain('profile_url');
	});
});

test('adds a setting new since an earlier release by reactivating, and the audit asks for it', async ({
	page,
}) => {
	// The board as a release from before the reserve members group left it.
	await execute(`DELETE FROM ${T('settings')} WHERE name = 'access_audit_reserve_group'`);
	await runPhp('rebuild_settings();');
	try {
		await clickPluginLink(page, 'Deactivate');
		await clickPluginLink(page, 'Activate');

		expect(await settingNames()).toEqual(SETTINGS);
		expect(await getSetting('access_audit_reserve_group')).toBe('');
		// The others are kept as they were.
		expect(await getSetting('access_audit_unit_group')).toBe(String(fixtures().groups.unit));

		await gotoAudit(page);
		await expect(page.locator('#access_audit_not_configured li')).toHaveText([
			'Reserve members group',
		]);
	} finally {
		await reinstall();
		await setSettings({ access_audit_reserve_group: String(fixtures().groups.reserve) });
	}
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
		expect(before.additionalgroups).toEqual(
			[fixtures().groups.unit, fixtures().groups.legion].sort((a, b) => a - b)
		);

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

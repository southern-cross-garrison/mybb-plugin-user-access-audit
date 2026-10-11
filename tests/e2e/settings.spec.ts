import { test, expect } from '../helpers/fixtures';
import { gotoAuditSettings, loginToAdminCp } from '../helpers/auth';
import { adminLog, execute, fixtures, getSetting, lookups, T } from '../helpers/db';
import { openAudit } from '../helpers/audit';
import { runPhp } from '../helpers/container';
import {
	OTHER_UNIT,
	OUR_UNIT,
	OUTPOST,
	OTHER_SQUAD,
	SQUAD,
	legionApiMember,
	legionApiRequests,
	squadsDown,
	stubLegionApi,
	stubFixtureMembers,
	stubSquads,
	stubUnits,
	unitsDown,
	unstubLegionApi,
} from '../helpers/legion-api';

/**
 * Users & Groups -> Access Audit -> Settings: the unit, picked from the 501st API's list;
 * the Legion ID profile field; the three groups the audit manages; and the squads, each
 * picked from the API's list with a group of its own.
 */
const save = (page: import('@playwright/test').Page) =>
	page.locator('#access_audit_save_settings').click();

/**
 * Take a squad row off. The squads offered are the unit's, so changing the unit means
 * taking off the provisioned squad, which is this unit's.
 */
const clearSquad = async (page: import('@playwright/test').Page, row = 0) => {
	await page.locator(`#squad_${row}`).selectOption('0');
	await page.locator(`#squad_group_${row}`).selectOption('0');
};

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
	const requests = legionApiRequests();
	expect(requests.slice(0, 2)).toEqual(['/garrisons', '/squads']);
	// Then the squad officers' records, which say which unit each squad is in.
	expect(requests.slice(2).sort()).toEqual([
		'/legionId/40001',
		'/legionId/40002',
		'/legionId/40003',
		'/legionId/40004',
	]);
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
	await expect(page.locator('#reserve_group')).toHaveValue(String(fixtures().groups.reserve));
	await expect(page.locator('#legion_group')).toHaveValue(String(fixtures().groups.legion));
	await expect(page.locator('#unit_group option', { hasText: 'Command Staff' })).toHaveCount(1);
});

test('saves the unit, its name, the field and the groups', async ({ page }) => {
	await gotoAuditSettings(page);

	await page.locator('#unit').selectOption(String(OTHER_UNIT.id));
	await clearSquad(page);
	await page.locator('#legion_id_field').selectOption(String(fixtures().profileFields.costumes));
	await page.locator('#unit_group').selectOption(String(fixtures().groups.command));
	await page.locator('#reserve_group').selectOption(String(fixtures().groups.legion));
	await page.locator('#legion_group').selectOption(String(fixtures().groups.unit));
	await save(page);

	await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
	expect(await getSetting('access_audit_unit')).toBe(String(OTHER_UNIT.id));
	expect(await getSetting('access_audit_unit_name')).toBe(OTHER_UNIT.name);
	expect(await getSetting('access_audit_legion_id_field')).toBe(
		String(fixtures().profileFields.costumes)
	);
	expect(await getSetting('access_audit_unit_group')).toBe(String(fixtures().groups.command));
	expect(await getSetting('access_audit_reserve_group')).toBe(String(fixtures().groups.legion));
	expect(await getSetting('access_audit_legion_group')).toBe(String(fixtures().groups.unit));

	const [entry] = await adminLog();
	expect([entry.module, entry.action]).toEqual(['user-access_audit', 'settings']);

	// The audit now goes by them.
	await openAudit(page);
	await expect(page.locator('#content')).toContainText(
		'Active members of Midsouth Garrison belong in both Command Staff and Garrison Members, and its Reserve members in both 501st Members and Garrison Members.'
	);
});

test("trims the API's stray spaces from a unit's name", async ({ page }) => {
	await gotoAuditSettings(page);
	await page.locator('#unit').selectOption(String(OUTPOST.id));
	await clearSquad(page);
	await save(page);

	expect(await getSetting('access_audit_unit_name')).toBe(OUTPOST.name);
});

for (const [first, second] of [
	['unit_group', 'legion_group'],
	['unit_group', 'reserve_group'],
	['reserve_group', 'legion_group'],
]) {
	test(`will not save the same group as ${first} and ${second}`, async ({ page }) => {
		await gotoAuditSettings(page);
		const chosen = await page.locator(`#${first}`).inputValue();
		await page.locator(`#${second}`).selectOption(chosen);
		await save(page);

		await expect(page.locator('.error')).toContainText(
			'The unit members, reserve members and 501st members groups must be three different groups.'
		);
		expect(await getSetting(`access_audit_${second}`)).not.toBe(chosen);
		// What was chosen is still chosen.
		await expect(page.locator(`#${second}`)).toHaveValue(chosen);
	});
}

test('will not save without every choice made', async ({ page }) => {
	await gotoAuditSettings(page);
	for (const select of [
		'#unit',
		'#legion_id_field',
		'#unit_group',
		'#reserve_group',
		'#legion_group',
	]) {
		await page.locator(select).selectOption('0');
	}
	await page.locator('#api_url').fill('not a url');
	await save(page);

	await expect(page.locator('.error li')).toHaveText([
		"Enter the 501st Legion API's address, starting with http:// or https://.",
		"Choose this board's unit.",
		"Choose the profile field that holds members' Legion IDs.",
		'Choose the unit members group.',
		'Choose the reserve members group.',
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
	const members = (await lookups()).map((row) => Number(row.legion_id));
	expect(members.length).toBeGreaterThan(0);

	await gotoAuditSettings(page);
	await page.locator('#api_url').fill('http://127.0.0.1/e2e-legion-api.php');
	await save(page);

	await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
	// The page it comes back to has asked the new API about the squad officers since.
	const kept = (await lookups()).map((row) => Number(row.legion_id));
	expect(kept.filter((legionId) => members.includes(legionId))).toEqual([]);
});

test('keeps answers when the address stays the same', async ({ page }) => {
	stubFixtureMembers();
	await openAudit(page);
	const members = (await lookups()).map((row) => Number(row.legion_id));

	await gotoAuditSettings(page);
	await save(page);

	expect((await lookups()).map((row) => Number(row.legion_id))).toEqual(
		expect.arrayContaining(members)
	);
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

test.describe('squads', () => {
	const squads = async () => JSON.parse((await getSetting('access_audit_squads')) || '[]');

	test("offers the unit's own squads by name, with the board's chosen and spare rows", async ({
		page,
	}) => {
		await gotoAuditSettings(page);

		// Not Snowy Range Squad, whose officer is in another unit.
		await expect(page.locator('#squad_0 option')).toHaveText([
			'Choose...',
			'Hunter Valley Squad',
			'Illawarra Squad',
		]);
		await expect(page.locator('#squad_0')).toHaveValue(String(SQUAD.id));
		await expect(page.locator('#squad_group_0')).toHaveValue(String(fixtures().groups.squad));
		await expect(page.locator('tr.access-audit-squad-row')).toHaveCount(4);
		for (const spare of [1, 2, 3]) {
			await expect(page.locator(`#squad_${spare}`)).toHaveValue('0');
			await expect(page.locator(`#squad_group_${spare}`)).toHaveValue('0');
		}
	});

	test('adds a squad, keeping its name, and the audit goes by it', async ({ page }) => {
		await gotoAuditSettings(page);
		await page.locator('#squad_1').selectOption(String(OTHER_SQUAD.id));
		await page.locator('#squad_group_1').selectOption(String(fixtures().groups.otherSquad));
		await save(page);

		await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
		expect(await squads()).toEqual([
			{ squad: SQUAD.id, name: SQUAD.name, group: fixtures().groups.squad },
			// Trimmed of the API's stray space.
			{ squad: OTHER_SQUAD.id, name: OTHER_SQUAD.name, group: fixtures().groups.otherSquad },
		]);
		await expect(page.locator('tr.access-audit-squad-row')).toHaveCount(5);

		await openAudit(page);
		await expect(page.locator('#access_audit_rules')).toContainText(
			'Active members of Illawarra Squad belong in Illawarra Squad Members as well.'
		);
	});

	test('takes a squad off when it is set back to Choose...', async ({ page }) => {
		await gotoAuditSettings(page);
		await page.locator('#squad_0').selectOption('0');
		await page.locator('#squad_group_0').selectOption('0');
		await save(page);

		await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
		expect(await getSetting('access_audit_squads')).toBe('');
	});

	test('moves a squad to another group', async ({ page }) => {
		await gotoAuditSettings(page);
		await page.locator('#squad_group_0').selectOption(String(fixtures().groups.otherSquad));
		await save(page);

		expect(await squads()).toEqual([
			{ squad: SQUAD.id, name: SQUAD.name, group: fixtures().groups.otherSquad },
		]);
	});

	const refusals: [string, string, (page: import('@playwright/test').Page) => Promise<void>][] = [
		[
			'a squad without a group',
			'Choose both a squad and its group, or neither.',
			async (page) => {
				await page.locator('#squad_1').selectOption(String(OTHER_SQUAD.id));
			},
		],
		[
			'a group without a squad',
			'Choose both a squad and its group, or neither.',
			async (page) => {
				await page.locator('#squad_group_1').selectOption(String(fixtures().groups.otherSquad));
			},
		],
		[
			'the same squad twice',
			'Each squad can only be chosen once.',
			async (page) => {
				await page.locator('#squad_1').selectOption(String(SQUAD.id));
				await page.locator('#squad_group_1').selectOption(String(fixtures().groups.otherSquad));
			},
		],
		[
			"one of the other three groups as a squad's",
			"Each squad needs a group of its own, not another squad's or one of the unit members, reserve members and 501st members groups.",
			async (page) => {
				await page.locator('#squad_group_0').selectOption(String(fixtures().groups.legion));
			},
		],
		[
			'two squads in one group',
			"Each squad needs a group of its own, not another squad's or one of the unit members, reserve members and 501st members groups.",
			async (page) => {
				await page.locator('#squad_1').selectOption(String(OTHER_SQUAD.id));
				await page.locator('#squad_group_1').selectOption(String(fixtures().groups.squad));
			},
		],
		[
			"another unit's squad",
			"Choose squads of this board's unit. If the unit has just been changed, take its old squads off first.",
			async (page) => {
				await page.locator('#squad_1').evaluate((select: HTMLSelectElement) => {
					select.add(new Option('Snowy Range Squad', '102'));
					select.value = '102';
				});
				await page.locator('#squad_group_1').selectOption(String(fixtures().groups.otherSquad));
			},
		],
	];
	for (const [what, message, choose] of refusals) {
		test(`will not save ${what}`, async ({ page }) => {
			const before = await getSetting('access_audit_squads');
			await gotoAuditSettings(page);
			await choose(page);
			await save(page);

			await expect(page.locator('.error')).toContainText(message);
			expect(await getSetting('access_audit_squads')).toBe(before);
		});
	}

	test('keeps what was chosen in each row when the form comes back with an error', async ({
		page,
	}) => {
		await gotoAuditSettings(page);
		await page.locator('#squad_2').selectOption(String(OTHER_SQUAD.id));
		await save(page);

		await expect(page.locator('.error')).toContainText(
			'Choose both a squad and its group, or neither.'
		);
		// The spare row left empty is gone, and the half-chosen one is kept.
		await expect(page.locator('#squad_0')).toHaveValue(String(SQUAD.id));
		await expect(page.locator('#squad_1')).toHaveValue(String(OTHER_SQUAD.id));
		await expect(page.locator('#squad_group_1')).toHaveValue('0');
	});

	test('passes over an officer whose record puts them in another squad', async ({ page }) => {
		stubLegionApi(40001, legionApiMember(40001, { squad: OTHER_SQUAD }));
		await gotoAuditSettings(page);

		// Hunter Valley Squad's only officer cannot place it.
		await expect(page.locator('#squad_1 option')).toHaveText(['Choose...', 'Illawarra Squad']);
	});

	test("offers a new unit's squads once it is saved, and will not keep the old unit's", async ({
		page,
	}) => {
		await gotoAuditSettings(page);
		await page.locator('#unit').selectOption(String(OTHER_UNIT.id));
		await save(page);

		await expect(page.locator('.error')).toContainText(
			"Choose squads of this board's unit. If the unit has just been changed, take its old squads off first."
		);
		expect(await getSetting('access_audit_unit')).toBe(String(OUR_UNIT.id));

		await clearSquad(page);
		await save(page);

		await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
		await expect(page.locator('#squad_0 option')).toHaveText(['Choose...', 'Snowy Range Squad']);
	});

	test('says when the unit has no squads', async ({ page }) => {
		await gotoAuditSettings(page);
		await page.locator('#unit').selectOption(String(OUTPOST.id));
		await clearSquad(page);
		await save(page);

		await expect(page.locator('#access_audit_squads_none')).toHaveText(
			"The 501st's records show no squads in this unit."
		);
		await expect(page.locator('#squad_0 option')).toHaveText(['Choose...']);
	});

	test.describe('while the API is down', () => {
		test('says so when it cannot answer for an officer, rather than leave their squad out', async ({
			page,
		}) => {
			unstubLegionApi(40003);
			await gotoAuditSettings(page);

			await expect(page.locator('#access_audit_squads_unavailable')).toBeVisible();
			await expect(page.locator('#squad_0 option')).toHaveText(['Choose...', SQUAD.name]);
		});

		test('says so, and still offers and saves the squads already chosen', async ({ page }) => {
			squadsDown();
			await gotoAuditSettings(page);

			await expect(page.locator('#access_audit_squads_unavailable')).toBeVisible();
			await expect(page.locator('#squad_0 option')).toHaveText(['Choose...', SQUAD.name]);
			await page.locator('#squad_group_0').selectOption(String(fixtures().groups.otherSquad));
			await save(page);

			await expect(page.locator('#flash_message')).toHaveText('The settings have been saved.');
			expect(await squads()).toEqual([
				{ squad: SQUAD.id, name: SQUAD.name, group: fixtures().groups.otherSquad },
			]);
		});

		test('cannot add a squad', async ({ page }) => {
			await gotoAuditSettings(page);
			squadsDown();
			await page.locator('#squad_1').selectOption(String(OTHER_SQUAD.id));
			await page.locator('#squad_group_1').selectOption(String(fixtures().groups.otherSquad));
			await save(page);

			await expect(page.locator('.error')).toContainText(
				'The list of squads could not be fetched from the 501st Legion API, so squads cannot be added right now.'
			);
			expect(await squads()).toHaveLength(1);
		});

		test('treats a list with nothing usable in it as no list', async ({ page }) => {
			stubSquads({ body: { squad: [{ name: 'No id' }, 'nonsense'] } });
			await gotoAuditSettings(page);

			await expect(page.locator('#access_audit_squads_unavailable')).toBeVisible();
		});
	});
});

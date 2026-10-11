import { Page } from '@playwright/test';
import { test, expect } from '../helpers/fixtures';
import { gotoAudit, gotoAuditSettings, loginToAdminCp } from '../helpers/auth';
import { fix, fixButton, flash, openAudit, row } from '../helpers/audit';
import { runScheduledTask } from '../helpers/container';
import {
	T,
	adminLog,
	ageEmailLookups,
	execute,
	fixtures,
	forgetEmailLookups,
	getGroups,
	getLegionId,
	query,
	queryOne,
	setGroups,
	setLegionId,
	uid,
} from '../helpers/db';
import {
	CLASSIFIED,
	emailMatch,
	emailRequests,
	emailsDown,
	legionApiMember,
	stubEmail,
	stubEmails,
	stubFixtureMembers,
	stubLegionApi,
} from '../helpers/legion-api';
import { withSettings } from '../helpers/settings';
import { RowDataPacket } from 'mysql2/promise';

/**
 * Users & Groups -> Access Audit -> Legion IDs, and the scheduled task behind it: members
 * with no Legion ID on their profile looked up by email address, a batch at a time for the
 * API's limit on those lookups, and the Legion IDs found saved to their profiles.
 *
 * Every test starts with every member looked up an hour ago and matching nobody (see
 * resetBoardState()), so the ones here about looking members up forget that first. With
 * nothing looked up, three members are due: guestmember, admin and staffadmin. The banned
 * member is never looked up, and everybody else has a Legion ID.
 */
const GUEST_EMAIL = 'guestmember@example.test';
const DAY = 24 * 60 * 60;

const groups = () => fixtures().groups;

async function openLegionIds(page: Page): Promise<void> {
	await gotoAudit(page, '&action=legion_ids');
	await expect(page.locator('#access_audit_lookup_members')).toBeVisible();
}

/** Press Look Up a Batch Now, and wait for the tab to come back. */
async function lookUpBatch(page: Page): Promise<void> {
	await openLegionIds(page);
	await page.locator('#access_audit_lookup_now').click();
	await expect(flash(page)).toBeVisible();
}

/** Spend this many of the API's lookups, as answers kept in the last 15 minutes. */
async function spendAllowance(lookups: number): Promise<void> {
	const spent = Array.from(
		{ length: lookups },
		(_, i) => `(SHA1('spent-${i}@example.test'), 0, UNIX_TIMESTAMP())`
	);
	await execute(
		`INSERT INTO ${T('access_audit_email_lookups')} (email_hash, legion_id, fetched_at) VALUES ${spent.join(', ')}`
	);
}

test.beforeEach(async ({ page }) => {
	stubFixtureMembers();
	await loginToAdminCp(page);
});

test('saves the Legion ID found for a member, and the audit then judges them by it', async ({
	page,
}) => {
	await forgetEmailLookups();
	stubEmail(GUEST_EMAIL, emailMatch(20003));
	stubLegionApi(20003, legionApiMember(20003, { fullName: 'Guest Member' }));

	await openLegionIds(page);
	await expect(page.locator('#access_audit_lookup_members')).toHaveText('3');
	await expect(page.locator('#access_audit_lookup_never')).toHaveText('3');
	await expect(page.locator('#access_audit_lookup_allowance')).toHaveText('50 of 50');
	await expect(page.locator('#access_audit_found_empty')).toBeVisible();

	await page.locator('#access_audit_lookup_now').click();
	await expect(flash(page)).toHaveText(
		'Looked up 3 email addresses. Legion IDs saved: 1; no match: 2; not answered: 0. Still to look up: 0.'
	);
	expect(await getLegionId('guestmember')).toBe('20003');
	// Saving a Legion ID changes nobody's groups.
	expect(await getGroups('guestmember')).toEqual({
		usergroup: 2,
		additionalgroups: [],
		displaygroup: 0,
	});

	await expect(page.locator('#access_audit_lookup_never')).toHaveText('0');
	await expect(page.locator('#access_audit_lookup_no_match')).toHaveText('2');
	await expect(page.locator('#access_audit_lookup_found')).toHaveText('1');
	await expect(page.locator('#access_audit_lookup_allowance')).toHaveText('47 of 50');
	await expect(page.locator('#access_audit_lookup_next')).toHaveText(
		'Everybody has been looked up.'
	);
	const found = page.locator(`#access_audit_found_${uid('guestmember')}`);
	await expect(found.locator('.access-audit-member')).toHaveText('guestmember');
	await expect(found.locator('.access-audit-legion-id a')).toHaveAttribute(
		'href',
		'https://501st.com/member/20003'
	);
	const [entry] = await adminLog();
	expect(entry.action).toBe('lookup');

	await openAudit(page);
	await expect(row(page, 'guestmember').locator('.access-audit-record')).toContainText(
		'Active - Southern Cross Garrison'
	);
	await expect(row(page, 'guestmember').locator('.access-audit-change')).toHaveText(
		'Add to Garrison Members; Add to 501st Members'
	);
	await fix(page, 'guestmember');
	expect((await getGroups('guestmember')).additionalgroups).toEqual(
		[groups().unit, groups().legion].sort((a, b) => a - b)
	);
});

test('fills a Legion ID field with no number in it, and never one with a number', async ({
	page,
}) => {
	await forgetEmailLookups();
	await setLegionId('trooper2', 'pending');
	await setLegionId('banned', '');
	stubEmail('trooper2@example.test', emailMatch(20002));
	stubEmail('unitmember@example.test', emailMatch(99999));
	await lookUpBatch(page);

	expect(await getLegionId('trooper2')).toBe('20002');
	expect(await getLegionId('unitmember')).toBe('TK-10001');
	expect(emailRequests()).toContain('/email/trooper2@example.test');
	expect(emailRequests()).not.toContain('/email/unitmember@example.test');
	expect(emailRequests()).not.toContain('/email/banned@example.test');
});

test('flags a Legion ID saved for one member that another has on their profile', async ({
	page,
}) => {
	await forgetEmailLookups();
	// unitmember is TK-10001.
	stubEmail(GUEST_EMAIL, emailMatch(10001));
	await lookUpBatch(page);

	await openAudit(page);
	await expect(row(page, 'guestmember').locator('.access-audit-shared a')).toHaveText([
		'unitmember',
	]);
	await expect(row(page, 'unitmember').locator('.access-audit-shared a')).toHaveText([
		'guestmember',
	]);
});

test('saves a Legion ID for a member with no profile fields row at all', async ({ page }) => {
	await forgetEmailLookups();
	await execute(`DELETE FROM ${T('userfields')} WHERE ufid = ?`, [uid('guestmember')]);
	stubEmail(GUEST_EMAIL, emailMatch(20003));
	stubLegionApi(20003, CLASSIFIED);
	await lookUpBatch(page);

	expect(await getLegionId('guestmember')).toBe('20003');
});

test('does not save again, or ask about, a Legion ID somebody has taken off the profile', async ({
	page,
}) => {
	await forgetEmailLookups();
	stubEmail(GUEST_EMAIL, emailMatch(20003));
	await lookUpBatch(page);
	expect(await getLegionId('guestmember')).toBe('20003');

	await setLegionId('guestmember', '');
	await ageEmailLookups(8 * DAY);
	await lookUpBatch(page);

	expect(emailRequests().filter((request) => request === `/email/${GUEST_EMAIL}`)).toHaveLength(1);
	expect(await getLegionId('guestmember')).toBe('');
	await expect(page.locator('#access_audit_lookup_unsaved')).toHaveCount(0);

	// Taken off on purpose, so the audit judges them as having none rather than waiting.
	await openAudit(page, { all: true });
	await expect(row(page, 'guestmember').locator('.access-audit-record')).toHaveText(
		'No Legion ID on their profile'
	);
});

test('saves a Legion ID found by an earlier lookup without asking again', async ({ page }) => {
	await forgetEmailLookups();
	// As a release that left saving it to the audit's Fix kept it.
	await execute(
		`INSERT INTO ${T('access_audit_email_lookups')} (email_hash, legion_id, fetched_at) VALUES (SHA1(?), 20003, UNIX_TIMESTAMP() - 3600)`,
		[GUEST_EMAIL]
	);
	await spendAllowance(50);

	// The audit's waiting members are the tab's not looked up yet and found, not saved.
	await openAudit(page);
	await expect(page.locator('#access_audit_count_waiting')).toHaveText('3');
	await openLegionIds(page);
	await expect(page.locator('#access_audit_lookup_never')).toHaveText('2');
	await expect(page.locator('#access_audit_lookup_unsaved')).toHaveText('1');

	await page.locator('#access_audit_lookup_now').click();
	await expect(flash(page)).toHaveText(
		"Saved 1 Legion IDs found by earlier lookups. Nothing looked up: the 501st Legion API's limit has no room until the last batch is 15 minutes old. 2 still to look up."
	);
	expect(emailRequests()).toEqual([]);
	expect(await getLegionId('guestmember')).toBe('20003');
	await expect(page.locator('#access_audit_lookup_unsaved')).toHaveCount(0);
	await expect(page.locator(`#access_audit_found_${uid('guestmember')}`)).toBeVisible();
});

test("keeps to the API's allowance of lookups", async ({ page }) => {
	await forgetEmailLookups();
	await spendAllowance(49);

	await lookUpBatch(page);
	await expect(flash(page)).toHaveText(
		'Looked up 1 email addresses. Legion IDs saved: 0; no match: 1; not answered: 0. Still to look up: 2.'
	);
	expect(emailRequests()).toHaveLength(1);
	await expect(page.locator('#access_audit_lookup_allowance')).toHaveText('0 of 50');
	await expect(page.locator('#access_audit_lookup_next')).toContainText(
		'1 more batches, about 15 minutes.'
	);

	await page.locator('#access_audit_lookup_now').click();
	await expect(flash(page)).toHaveText(
		"Nothing looked up: the 501st Legion API's limit has no room until the last batch is 15 minutes old. 2 still to look up."
	);
	expect(emailRequests()).toHaveLength(1);

	// The window has passed.
	await ageEmailLookups(901);
	await lookUpBatch(page);
	expect(emailRequests()).toHaveLength(3);
	await expect(page.locator('#access_audit_lookup_never')).toHaveText('0');
});

test('stops, and keeps nothing, when the API turns lookups away', async ({ page }) => {
	await forgetEmailLookups();
	stubEmails({
		status: 429,
		body: {
			error: 'Too many lookups from your network. Try again in 15 minutes.',
			code: 'RATE_LIMITED',
		},
	});
	await lookUpBatch(page);

	await expect(flash(page)).toContainText(
		'The 501st Legion API turned lookups away for asking too often'
	);
	expect(await query(`SELECT * FROM ${T('access_audit_email_lookups')}`)).toEqual([]);
	await expect(page.locator('#access_audit_lookup_never')).toHaveText('3');
});

const unusable: Array<[string, Parameters<typeof stubEmail>[1] | null]> = [
	['is down', null],
	['answers with an error', { status: 500, body: { message: 'Internal Server Error' } }],
	['answers with something that is not JSON', { raw: '<html>Bad Gateway</html>' }],
	['answers with something that is not a Legion ID', { body: { legionId: '<b>1</b>' } }],
];

for (const [answer, response] of unusable) {
	test(`leaves a member waiting when the API ${answer}`, async ({ page }) => {
		await forgetEmailLookups();
		if (response) {
			stubEmail(GUEST_EMAIL, response);
		} else {
			emailsDown();
		}
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await lookUpBatch(page);

		expect(await getLegionId('guestmember')).toBe('');
		await openAudit(page);
		await expect(row(page, 'guestmember').locator('.access-audit-record')).toHaveText(
			'No Legion ID on their profile, and not looked up by email address yet'
		);
		await expect(fixButton(page, 'guestmember')).toHaveCount(0);
		expect((await getGroups('guestmember')).additionalgroups).toEqual([groups().unit]);
	});
}

test('looks an address nobody has up again after a week', async ({ page }) => {
	await openLegionIds(page);
	await expect(page.locator('#access_audit_lookup_never')).toHaveText('0');
	await expect(page.locator('#access_audit_lookup_recheck')).toHaveCount(0);
	await expect(page.locator('#access_audit_lookup_no_match')).toHaveText('3');

	await ageEmailLookups(7 * DAY);
	await openLegionIds(page);
	await expect(page.locator('#access_audit_lookup_recheck')).toHaveText('3');
	await expect(page.locator('#access_audit_lookup_no_match')).toHaveText('0');
	// Still judged by the answer they have until they are looked up again.
	await openAudit(page);
	await expect(page.locator('#access_audit_count_waiting')).toHaveCount(0);
});

test('runs as a scheduled task, logging what it did', async () => {
	await forgetEmailLookups();
	stubEmail(GUEST_EMAIL, emailMatch(20003));

	const output = await runScheduledTask('access_audit');

	expect(output).toContain('ENABLED:1');
	expect(output).toContain(
		'LOG:Looked up 3 email addresses. Legion IDs saved: 1; no match: 2; not answered: 0. Still to look up: 0.'
	);
	expect(await getLegionId('guestmember')).toBe('20003');
});

test('does nothing until the audit is set up', async ({ page }) => {
	await forgetEmailLookups();
	const restore = await withSettings({ access_audit_unit: '' });
	try {
		await runScheduledTask('access_audit');
		expect(emailRequests()).toEqual([]);

		await gotoAudit(page, '&action=legion_ids');
		await expect(page.locator('#access_audit_not_configured')).toContainText(
			'once the audit has been set up'
		);
	} finally {
		await restore();
	}
});

test('starts with the next page view once the settings are saved', async ({ page }) => {
	await execute(
		`UPDATE ${T('tasks')} SET nextrun = UNIX_TIMESTAMP() + 3600 WHERE file = 'access_audit'`
	);
	await gotoAuditSettings(page);
	await page.locator('#access_audit_save_settings').click();
	await expect(flash(page)).toBeVisible();

	const task = await queryOne<RowDataPacket>(
		`SELECT nextrun <= UNIX_TIMESTAMP() AS due FROM ${T('tasks')} WHERE file = 'access_audit'`
	);
	expect(Number(task!.due)).toBe(1);
});

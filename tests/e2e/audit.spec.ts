import { test, expect } from '../helpers/fixtures';
import { loginToAdminCp } from '../helpers/auth';
import { withSettings } from '../helpers/settings';
import { BASE_URL } from '../helpers/config';
import { counts, fix, fixButton, flash, openAudit, row } from '../helpers/audit';
import {
	BANNED,
	REGISTERED,
	T,
	adminLog,
	execute,
	fixtures,
	forgetEmailLookups,
	getGroups,
	query,
	setGroups,
	setLegionId,
	uid,
} from '../helpers/db';
import {
	CLASSIFIED,
	OTHER_UNIT,
	OUTPOST,
	SQUAD,
	UNAUDITED_SQUAD,
	emailRequests,
	legionApiMember,
	memberRequests,
	stubFixtureMembers,
	stubLegionApi,
	unstubLegionApi,
} from '../helpers/legion-api';

/**
 * The audit: every member judged by their Legion ID's 501st record, and their membership
 * of the audited groups fixed one row at a time. As provisioned, the board audits one
 * squad, the Hunter Valley Squad, whose group is Hunter Valley Squad Members; nobody is in
 * either until a test says so.
 *
 * Provisioned members and the records stubFixtureMembers() gives them:
 *   unitmember   TK-10001  Active, this unit       Garrison Members, 501st Members
 *   legionmember TK-10002  Active, another unit    501st Members
 *   commander    TK-10003  Active, this unit       Command Staff, Garrison Members, 501st Members
 *   guestmember  -                                 none
 *   trooper1     TK-20001  Classified Record       none
 *   trooper2     TK-20002  Classified Record       none
 *   banned       TK-30001  Active, this unit       Banned, Garrison Members
 *   admin, staffadmin  -                           Administrators
 */
const groups = () => fixtures().groups;

test.beforeEach(async ({ page }) => {
	stubFixtureMembers();
	await loginToAdminCp(page);
});

test.describe('the audit', () => {
	test('finds nothing to change when every member is in the right groups', async ({ page }) => {
		await openAudit(page);

		expect(await counts(page)).toEqual({
			checked: 9,
			ok: 8,
			fix: 0,
			unchecked: 0,
			banned: 1,
			shared: 0,
		});
		await expect(page.locator('#access_audit_empty')).toHaveText(
			"Every member's groups are correct."
		);
		await expect(page.locator('button.access-audit-fix')).toHaveCount(0);
		await expect(page.locator('#access_audit_count_waiting')).toHaveCount(0);
		// Only the members with a Legion ID are looked up, and the banned one is not. Email
		// addresses never are: that is the background lookups' job.
		expect(emailRequests()).toEqual([]);
		expect(memberRequests().sort()).toEqual(
			[
				'/legionId/10001',
				'/legionId/10002',
				'/legionId/10003',
				'/legionId/20001',
				'/legionId/20002',
			].sort()
		);
	});

	test('says which unit and groups it is checking against', async ({ page }) => {
		await openAudit(page);

		await expect(page.locator('#content')).toContainText(
			'Active members of Southern Cross Garrison belong in both Garrison Members and 501st Members, and its Reserve members in both Garrison Reserve and 501st Members. Its Retired members belong in Garrison Retired only. Active and Reserve members of any other unit belong in 501st Members only.'
		);
	});

	test('lists every member, correct or not, when asked', async ({ page }) => {
		await openAudit(page, { all: true });

		await expect(page.locator('tr.access-audit-row')).toHaveCount(9);
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');
		await expect(row(page, 'guestmember').locator('.access-audit-record')).toHaveText(
			'No Legion ID on their profile'
		);
		await expect(row(page, 'banned').locator('.access-audit-record')).toContainText('Banned');
		await expect(fixButton(page, 'banned')).toHaveCount(0);

		await page.locator('#access_audit_toggle').click();
		await expect(page.locator('tr.access-audit-row')).toHaveCount(0);
	});

	test('has no way to fix everybody at once', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await setGroups('trooper1', { additionalgroups: [groups().legion] });
		await openAudit(page);

		await expect(page.locator('button.access-audit-fix')).toHaveCount(2);
		await expect(page.getByRole('button', { name: /resolve all/i })).toHaveCount(0);
		await expect(page.locator('.form_button_wrapper button')).toHaveText(['Check Again']);
	});
});

test.describe('who belongs where', () => {
	test('a member with no Legion ID belongs in neither group', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit, groups().legion] });
		await openAudit(page);

		const guest = row(page, 'guestmember');
		await expect(guest.locator('.access-audit-legion-id')).toHaveText('None');
		await expect(guest.locator('.access-audit-groups')).toHaveText('Garrison Members501st Members');
		await expect(guest.locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members; Remove from 501st Members'
		);

		await fix(page, 'guestmember');

		expect(await getGroups('guestmember')).toEqual({
			usergroup: REGISTERED,
			additionalgroups: [],
			displaygroup: 0,
		});
		await expect(flash(page)).toHaveText(
			'guestmember has been fixed: Remove from Garrison Members; Remove from 501st Members.'
		);
		await expect(row(page, 'guestmember')).toHaveCount(0);
	});

	test('a member of this unit gone to the reserve moves to the reserve members group', async ({
		page,
	}) => {
		stubLegionApi(10001, legionApiMember(10001, { status: 'Reserve', fullName: 'Unit Member' }));
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-status')).toHaveText('Reserve');
		await expect(member.locator('.access-audit-unit')).toHaveText('Southern Cross Garrison');
		// Still a 501st member, so that group stays.
		await expect(member.locator('.access-audit-change')).toHaveText(
			'Add to Garrison Reserve; Remove from Garrison Members'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: REGISTERED,
			additionalgroups: [groups().legion, groups().reserve].sort((a, b) => a - b),
			displaygroup: 0,
		});
	});

	test('a Reserve member of this unit in neither group joins the reserve and 501st members groups', async ({
		page,
	}) => {
		await setGroups('unitmember');
		stubLegionApi(10001, legionApiMember(10001, { status: 'Reserve' }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Reserve; Add to 501st Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().reserve, groups().legion].sort((a, b) => a - b)
		);
	});

	test('a Reserve member of this unit in the right groups needs nothing', async ({ page }) => {
		await setGroups('unitmember', { additionalgroups: [groups().reserve, groups().legion] });
		stubLegionApi(10001, legionApiMember(10001, { status: 'Reserve' }));
		await openAudit(page, { all: true });

		await expect(row(page, 'unitmember').locator('.access-audit-groups')).toHaveText(
			'Garrison Reserve501st Members'
		);
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');
	});

	test('a member of this unit back from the reserve leaves the reserve members group', async ({
		page,
	}) => {
		await setGroups('unitmember', { additionalgroups: [groups().reserve, groups().legion] });
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members; Remove from Garrison Reserve'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().unit, groups().legion].sort((a, b) => a - b)
		);
	});

	test('a Reserve member of another unit belongs in the 501st members group only', async ({
		page,
	}) => {
		stubLegionApi(10002, legionApiMember(10002, { status: 'Reserve', unit: OTHER_UNIT }));
		await openAudit(page, { all: true });

		// As legionmember is provisioned: nothing to change.
		await expect(row(page, 'legionmember').locator('.access-audit-change')).toHaveText('Nothing');

		// The reserve members group is this unit's own reserve, not anybody's.
		await setGroups('legionmember', { additionalgroups: [groups().reserve] });
		await openAudit(page);
		await expect(row(page, 'legionmember').locator('.access-audit-change')).toHaveText(
			'Add to 501st Members; Remove from Garrison Reserve'
		);
		await fix(page, 'legionmember');
		expect((await getGroups('legionmember')).additionalgroups).toEqual([groups().legion]);
	});

	test('a member neither Active, Reserve nor Retired belongs in none of the groups', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().reserve, groups().retired, groups().legion],
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Inactive' }));
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-status')).toHaveText('Inactive');
		await expect(member.locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members; Remove from Garrison Reserve; Remove from Garrison Retired; Remove from 501st Members'
		);

		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual([]);
	});

	test('a member of this unit who has retired belongs in the retired members group alone', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().legion, groups().command],
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Retired', fullName: 'Unit Member' }));
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-status')).toHaveText('Retired');
		await expect(member.locator('.access-audit-unit')).toHaveText('Southern Cross Garrison');
		// No longer a 501st member, so that group goes too.
		await expect(member.locator('.access-audit-change')).toHaveText(
			'Add to Garrison Retired; Remove from Garrison Members; Remove from 501st Members'
		);

		await fix(page, 'unitmember');
		// Command Staff is none of the audit's business.
		expect(await getGroups('unitmember')).toEqual({
			usergroup: REGISTERED,
			additionalgroups: [groups().command, groups().retired].sort((a, b) => a - b),
			displaygroup: 0,
		});
	});

	test('a Retired member of this unit leaves the reserve members group', async ({ page }) => {
		await setGroups('unitmember', { additionalgroups: [groups().reserve, groups().legion] });
		stubLegionApi(10001, legionApiMember(10001, { status: 'Retired' }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Retired; Remove from Garrison Reserve; Remove from 501st Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual([groups().retired]);
	});

	test('a Retired member of this unit in the retired members group needs nothing', async ({
		page,
	}) => {
		await setGroups('unitmember', { additionalgroups: [groups().retired] });
		stubLegionApi(10001, legionApiMember(10001, { status: 'Retired' }));
		await openAudit(page, { all: true });

		await expect(row(page, 'unitmember').locator('.access-audit-groups')).toHaveText(
			'Garrison Retired'
		);
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');
	});

	test('a member of this unit back from retirement leaves the retired members group', async ({
		page,
	}) => {
		await setGroups('unitmember', { additionalgroups: [groups().retired] });
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members; Add to 501st Members; Remove from Garrison Retired'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().unit, groups().legion].sort((a, b) => a - b)
		);
	});

	test('a Retired member of another unit belongs in none of the groups', async ({ page }) => {
		// The retired members group is this unit's own retired, not anybody's.
		await setGroups('legionmember', { additionalgroups: [groups().retired, groups().legion] });
		stubLegionApi(10002, legionApiMember(10002, { status: 'Retired', unit: OTHER_UNIT }));
		await openAudit(page);

		await expect(row(page, 'legionmember').locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Retired; Remove from 501st Members'
		);
		await fix(page, 'legionmember');
		expect((await getGroups('legionmember')).additionalgroups).toEqual([]);
	});

	test('a member with no public record in the retired members group is left in it alone', async ({
		page,
	}) => {
		// Retired members' records are often private, and a private record cannot be told
		// from a retired one. Not active either way, so the other groups go.
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().retired, groups().legion, groups().squad],
		});
		stubLegionApi(10001, CLASSIFIED);
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-record')).toHaveText(
			'No public record - private, retired, or not a real Legion ID' +
				"Left in Garrison Retired: a retired member's record can be private, so whether they have retired cannot be told."
		);
		await expect(member.locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members; Remove from 501st Members; Remove from Hunter Valley Squad Members'
		);

		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual([groups().retired]);
	});

	test('a member with no public record in only the retired members group needs nothing', async ({
		page,
	}) => {
		await setGroups('unitmember', { additionalgroups: [groups().retired] });
		stubLegionApi(10001, CLASSIFIED);
		await openAudit(page);

		await expect(row(page, 'unitmember')).toHaveCount(0);
		await openAudit(page, { all: true });
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');
		await expect(row(page, 'unitmember').locator('.access-audit-classified-retired')).toHaveCount(
			1
		);
	});

	test('a member with no public record keeps the retired members group as primary', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			usergroup: groups().retired,
			additionalgroups: [groups().legion],
			displaygroup: groups().retired,
		});
		stubLegionApi(10001, CLASSIFIED);
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from 501st Members'
		);
		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().retired,
			additionalgroups: [],
			displaygroup: groups().retired,
		});
	});

	test('a member in none of the groups says so', async ({ page }) => {
		await openAudit(page, { all: true });

		await expect(row(page, 'guestmember').locator('.access-audit-groups')).toHaveText(
			'None of them'
		);
	});

	test('a member with no public record belongs in neither group', async ({ page }) => {
		await setGroups('trooper1', { additionalgroups: [groups().legion] });
		await openAudit(page);

		const trooper = row(page, 'trooper1');
		await expect(trooper.locator('.access-audit-record')).toHaveText(
			'No public record - private, retired, or not a real Legion ID'
		);
		// Never put in the retired members group: that takes a public Retired record.
		await expect(trooper.locator('.access-audit-change')).toHaveText('Remove from 501st Members');

		await fix(page, 'trooper1');
		expect((await getGroups('trooper1')).additionalgroups).toEqual([]);
	});

	test('an active member of another unit moves to the 501st members group', async ({ page }) => {
		stubLegionApi(10001, legionApiMember(10001, { unit: OTHER_UNIT, fullName: 'Moved Away' }));
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-record')).toContainText(
			'Active - Midsouth Garrison'
		);
		await expect(member.locator('.access-audit-record')).toContainText('Moved Away');
		// Still a 501st member, so only the unit's group goes.
		await expect(member.locator('.access-audit-change')).toHaveText('Remove from Garrison Members');

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: REGISTERED,
			additionalgroups: [groups().legion],
			displaygroup: 0,
		});
	});

	test('an active member of this unit in neither group joins both', async ({ page }) => {
		await setGroups('unitmember');
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members; Add to 501st Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().unit, groups().legion].sort((a, b) => a - b)
		);
	});

	test('an active member of this unit in only its group joins the 501st members group', async ({
		page,
	}) => {
		await setGroups('unitmember', { additionalgroups: [groups().unit] });
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to 501st Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().unit, groups().legion].sort((a, b) => a - b)
		);
	});

	test('an active member of this unit in only the 501st members group joins the unit members group', async ({
		page,
	}) => {
		await setGroups('unitmember', { additionalgroups: [groups().legion] });
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().unit, groups().legion].sort((a, b) => a - b)
		);
	});

	test('an active member of this unit counts a primary group as being in it', async ({ page }) => {
		await setGroups('unitmember', {
			usergroup: groups().unit,
			additionalgroups: [groups().legion],
		});
		await openAudit(page, { all: true });

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');
	});

	test('a member of an outpost counts as another unit', async ({ page }) => {
		stubLegionApi(10002, legionApiMember(10002, { unit: OUTPOST }));
		await openAudit(page, { all: true });

		await expect(row(page, 'legionmember').locator('.access-audit-change')).toHaveText('Nothing');
	});

	test('reads Legion IDs however members typed them', async ({ page }) => {
		await setLegionId('trooper1', 'tk 0020001');
		await setLegionId('trooper2', ' TD-20002 ');
		stubLegionApi(20001, legionApiMember(20001));
		stubLegionApi(20002, legionApiMember(20002, { unit: OTHER_UNIT }));
		await openAudit(page);

		await expect(row(page, 'trooper1').locator('.access-audit-legion-id')).toHaveText('tk 0020001');
		await expect(row(page, 'trooper1').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members; Add to 501st Members'
		);
		await expect(row(page, 'trooper2').locator('.access-audit-change')).toHaveText(
			'Add to 501st Members'
		);
		expect(memberRequests()).toContain('/legionId/20001');
		expect(memberRequests()).toContain('/legionId/20002');
	});

	test('a Legion ID with no number in it is no Legion ID', async ({ page }) => {
		await setLegionId('unitmember', 'pending');
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-record')).toHaveText(
			'No Legion ID on their profile'
		);
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members; Remove from 501st Members'
		);
	});
});

test.describe('squads', () => {
	test('an Active member of an audited squad joins its group as well', async ({ page }) => {
		stubLegionApi(10001, legionApiMember(10001, { squad: SQUAD }));
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-squad')).toHaveText(SQUAD.name);
		await expect(member.locator('.access-audit-change')).toHaveText(
			'Add to Hunter Valley Squad Members'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: REGISTERED,
			additionalgroups: [groups().unit, groups().legion, groups().squad].sort((a, b) => a - b),
			displaygroup: 0,
		});
	});

	test('an Active member of another unit in an audited squad joins its group too', async ({
		page,
	}) => {
		stubLegionApi(10002, legionApiMember(10002, { unit: OTHER_UNIT, squad: SQUAD }));
		await openAudit(page);

		await expect(row(page, 'legionmember').locator('.access-audit-change')).toHaveText(
			'Add to Hunter Valley Squad Members'
		);
	});

	test('a member in the right squad group needs nothing, and it is shown with the others', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().legion, groups().squad],
		});
		stubLegionApi(10001, legionApiMember(10001, { squad: SQUAD }));
		await openAudit(page, { all: true });

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-groups')).toHaveText(
			'Garrison Members501st MembersHunter Valley Squad Members'
		);
		await expect(member.locator('.access-audit-change')).toHaveText('Nothing');
	});

	test('a member who has left the squad leaves its group', async ({ page }) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().legion, groups().squad],
		});
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from Hunter Valley Squad Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual(
			[groups().unit, groups().legion].sort((a, b) => a - b)
		);
	});

	test('a Reserve member of the squad does not belong in its group', async ({ page }) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().reserve, groups().legion, groups().squad],
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Reserve', squad: SQUAD }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from Hunter Valley Squad Members'
		);
	});

	test('a member of the squad who is no longer a 501st member leaves every group', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().legion, groups().squad],
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Inactive', squad: SQUAD }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members; Remove from 501st Members; Remove from Hunter Valley Squad Members'
		);
	});

	test('a Retired member of the squad leaves its group for the retired members group', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			additionalgroups: [groups().unit, groups().legion, groups().squad],
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Retired', squad: SQUAD }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Retired; Remove from Garrison Members; Remove from 501st Members; Remove from Hunter Valley Squad Members'
		);
		await fix(page, 'unitmember');
		expect((await getGroups('unitmember')).additionalgroups).toEqual([groups().retired]);
	});

	test('a member of a squad the board does not audit gets no squad group', async ({ page }) => {
		stubLegionApi(10001, legionApiMember(10001, { squad: UNAUDITED_SQUAD }));
		await openAudit(page, { all: true });

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-squad')).toHaveText(UNAUDITED_SQUAD.name);
		await expect(member.locator('.access-audit-change')).toHaveText('Nothing');
	});

	test('a squad group taken away as primary becomes the group they belong in', async ({ page }) => {
		await setGroups('unitmember', {
			usergroup: groups().squad,
			additionalgroups: [groups().unit, groups().legion],
		});
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from Hunter Valley Squad Members; Primary group becomes Garrison Members'
		);
		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().unit,
			additionalgroups: [groups().legion],
			displaygroup: 0,
		});
	});

	test('are named in the summary, each with its group', async ({ page }) => {
		const restore = await withSettings({
			access_audit_squads: JSON.stringify([
				{ squad: SQUAD.id, name: SQUAD.name, group: groups().squad },
				{ squad: 302, name: 'Illawarra Squad', group: groups().otherSquad },
			]),
		});
		try {
			await openAudit(page);
			await expect(page.locator('#access_audit_rules')).toContainText(
				'Active members of Hunter Valley Squad belong in Hunter Valley Squad Members as well. Active members of Illawarra Squad belong in Illawarra Squad Members as well.'
			);
		} finally {
			await restore();
		}
	});

	test('none chosen leaves a squad group alone, as any other group', async ({ page }) => {
		const restore = await withSettings({ access_audit_squads: '' });
		try {
			await setGroups('guestmember', { additionalgroups: [groups().squad] });
			stubLegionApi(10001, legionApiMember(10001, { squad: SQUAD }));
			await openAudit(page, { all: true });

			await expect(page.locator('#access_audit_rules')).not.toContainText('Squad');
			await expect(row(page, 'guestmember').locator('.access-audit-change')).toHaveText('Nothing');
			await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');
		} finally {
			await restore();
		}
	});

	test("a squad's name from the API is shown as text", async ({ page }) => {
		const record = legionApiMember(10001, { squad: SQUAD }) as { body: Record<string, unknown> };
		stubLegionApi(10001, {
			body: { ...record.body, squadName: '<img id="squad" src=x>' },
		});
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-squad')).toHaveText(
			'<img id="squad" src=x>'
		);
		await expect(page.locator('#squad')).toHaveCount(0);
	});
});

test.describe('the Legion ID', () => {
	test('links to 501st.com by its number, shown as the member typed it', async ({ page }) => {
		await setLegionId('trooper1', 'tk 0020001');
		await setLegionId('trooper2', 'pending');
		await openAudit(page, { all: true });

		const typed = row(page, 'unitmember').locator('.access-audit-legion-id a');
		await expect(typed).toHaveText('TK-10001');
		await expect(typed).toHaveAttribute('href', 'https://501st.com/member/10001');
		await expect(typed).toHaveAttribute('target', '_blank');
		await expect(typed).toHaveAttribute('rel', 'noopener noreferrer');

		await expect(row(page, 'trooper1').locator('.access-audit-legion-id a')).toHaveAttribute(
			'href',
			'https://501st.com/member/20001'
		);
		// Whatever the API said: no public record, and banned, are linked too.
		await expect(row(page, 'trooper1').locator('.access-audit-legion-id a')).toHaveText(
			'tk 0020001'
		);
		await expect(row(page, 'banned').locator('.access-audit-legion-id a')).toHaveAttribute(
			'href',
			'https://501st.com/member/30001'
		);

		// No number, no link.
		await expect(row(page, 'trooper2').locator('.access-audit-legion-id')).toHaveText('pending');
		await expect(row(page, 'trooper2').locator('.access-audit-legion-id a')).toHaveCount(0);
		await expect(row(page, 'guestmember').locator('.access-audit-legion-id')).toHaveText('None');
		await expect(row(page, 'guestmember').locator('.access-audit-legion-id a')).toHaveCount(0);
	});
});

test.describe('a member with no Legion ID on their profile', () => {
	test('is not checked until they have been looked up by email address', async ({ page }) => {
		await forgetEmailLookups();
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page);

		const guest = row(page, 'guestmember');
		await expect(guest.locator('.access-audit-record')).toHaveText(
			'No Legion ID on their profile, and not looked up by email address yet'
		);
		await expect(guest.locator('.access-audit-change')).toHaveText('Unknown until the API answers');
		await expect(fixButton(page, 'guestmember')).toHaveCount(0);
		// guestmember, admin and staffadmin; the banned member is never looked up.
		expect(Number(await page.locator('#access_audit_count_waiting').innerText())).toBe(3);
		expect((await counts(page)).unchecked).toBe(3);
		await expect(page.locator('#access_audit_rules a', { hasText: 'Legion IDs' })).toHaveAttribute(
			'href',
			/action=legion_ids/
		);
		expect(emailRequests()).toEqual([]);

		const key = await page.locator('input[name="my_post_key"]').getAttribute('value');
		const response = await page.request.post(
			'/admin/index.php?module=user-access_audit&action=fix',
			{ form: { my_post_key: key!, uid: String(uid('guestmember')) } }
		);
		expect(await response.text()).toContain(
			'guestmember could not be checked: they have no Legion ID on their profile and have not been looked up by email address yet.'
		);
		expect((await getGroups('guestmember')).additionalgroups).toEqual([groups().unit]);
	});

	test('is not checked while a Legion ID found for them is yet to be saved', async ({ page }) => {
		await execute(
			`UPDATE ${T('access_audit_email_lookups')} SET legion_id = 20003 WHERE email_hash = SHA1('guestmember@example.test')`
		);
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page);

		await expect(row(page, 'guestmember').locator('.access-audit-record')).toHaveText(
			'No Legion ID on their profile, and not looked up by email address yet'
		);
		await expect(fixButton(page, 'guestmember')).toHaveCount(0);
	});

	test('keeps what the email lookups found through Check Again', async ({ page }) => {
		await openAudit(page);
		await page.locator('#access_audit_refresh').click();
		await expect(page.locator('#access_audit_count_checked')).toBeVisible();

		await expect(page.locator('#access_audit_count_waiting')).toHaveCount(0);
		expect(await query(`SELECT * FROM ${T('access_audit_email_lookups')}`)).not.toHaveLength(0);
	});
});

test.describe('the post count', () => {
	test("links to a search of the member's posts, when they have any", async ({ page }) => {
		const setPosts = (postnum: number) =>
			execute(`UPDATE ${T('users')} SET postnum = ? WHERE uid = ?`, [postnum, uid('trooper1')]);
		// The per-test reset puts groups back, not post counts.
		await setPosts(1234);
		try {
			await openAudit(page, { all: true });

			const posts = row(page, 'trooper1').locator('.access-audit-posts a');
			await expect(posts).toHaveText('1,234');
			await expect(posts).toHaveAttribute(
				'href',
				`${BASE_URL}/search.php?action=finduser&uid=${uid('trooper1')}`
			);
			await expect(posts).toHaveAttribute('target', '_blank');

			// A search for no posts finds nothing, so there is nothing to link.
			await expect(row(page, 'trooper2').locator('.access-audit-posts')).toHaveText('0');
			await expect(row(page, 'trooper2').locator('.access-audit-posts a')).toHaveCount(0);
		} finally {
			await setPosts(0);
		}
	});
});

test.describe('the 501st record', () => {
	test('shows the name with no link of its own: the Legion ID is the way to 501st.com', async ({
		page,
	}) => {
		// Whatever profile address the API gives is kept, but not linked.
		const record = legionApiMember(10001, { status: 'Reserve' }) as {
			body: Record<string, unknown>;
		};
		stubLegionApi(10001, { body: { ...record.body, profileUrl: 'javascript:alert(1)' } });
		await openAudit(page);

		const member = row(page, 'unitmember');
		await expect(member.locator('.access-audit-record')).toContainText('Reserve');
		await expect(member.locator('.access-audit-record')).toContainText('Fixture Trooper');
		await expect(member.locator('.access-audit-record a')).toHaveCount(0);
		await expect(member.locator('a[href*="501st.com"]')).toHaveCount(1);
		await expect(member.locator('.access-audit-legion-id a')).toHaveAttribute(
			'href',
			'https://501st.com/member/10001'
		);
		await expect(page.locator('a[href^="javascript"]')).toHaveCount(0);
	});
});

test.describe('groups the audit does not manage', () => {
	test('are left alone', async ({ page }) => {
		stubLegionApi(10003, legionApiMember(10003, { unit: OTHER_UNIT }));
		await openAudit(page);

		// Their command staff group is no business of the audit's.
		await expect(row(page, 'commander').locator('.access-audit-groups')).toHaveText(
			'Garrison Members501st Members'
		);
		await expect(row(page, 'commander').locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members'
		);

		await fix(page, 'commander');
		expect((await getGroups('commander')).additionalgroups).toEqual(
			[groups().legion, groups().command].sort((a, b) => a - b)
		);
	});

	test('keep a member with no Legion ID in them', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().command, groups().unit] });
		await openAudit(page);

		await fix(page, 'guestmember');
		expect((await getGroups('guestmember')).additionalgroups).toEqual([groups().command]);
	});

	test('include a primary group, which stays primary', async ({ page }) => {
		await setGroups('trooper1', { usergroup: groups().command, additionalgroups: [groups().unit] });
		await openAudit(page);

		await fix(page, 'trooper1');
		expect(await getGroups('trooper1')).toEqual({
			usergroup: groups().command,
			additionalgroups: [],
			displaygroup: 0,
		});
	});
});

test.describe('a primary group taken away', () => {
	test('becomes the 501st members group for a member gone to another unit', async ({ page }) => {
		await setGroups('unitmember', {
			usergroup: groups().unit,
			additionalgroups: [groups().command],
			displaygroup: groups().unit,
		});
		stubLegionApi(10001, legionApiMember(10001, { unit: OTHER_UNIT }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-groups')).toHaveText(
			'Garrison Members (primary)'
		);
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to 501st Members; Remove from Garrison Members; Primary group becomes 501st Members'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().legion,
			additionalgroups: [groups().command],
			// Back to showing the primary group.
			displaygroup: 0,
		});
	});

	test('becomes the reserve members group for a member of this unit gone to the reserve', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			usergroup: groups().unit,
			additionalgroups: [groups().legion],
			displaygroup: groups().unit,
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Reserve' }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Reserve; Remove from Garrison Members; Primary group becomes Garrison Reserve'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().reserve,
			additionalgroups: [groups().legion],
			displaygroup: 0,
		});
	});

	test('becomes the retired members group for a member of this unit who has retired', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			usergroup: groups().unit,
			additionalgroups: [groups().legion, groups().squad],
			displaygroup: groups().unit,
		});
		stubLegionApi(10001, legionApiMember(10001, { status: 'Retired', squad: SQUAD }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Retired; Remove from Garrison Members; Remove from 501st Members; Remove from Hunter Valley Squad Members; Primary group becomes Garrison Retired'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().retired,
			additionalgroups: [],
			displaygroup: 0,
		});
	});

	test('becomes the unit members group for a member of this unit back from the reserve', async ({
		page,
	}) => {
		await setGroups('unitmember', {
			usergroup: groups().reserve,
			additionalgroups: [groups().legion],
		});
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members; Remove from Garrison Reserve; Primary group becomes Garrison Members'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().unit,
			additionalgroups: [groups().legion],
			displaygroup: 0,
		});
	});

	test('becomes the 501st members group when they were in it already', async ({ page }) => {
		await setGroups('unitmember', {
			usergroup: groups().unit,
			additionalgroups: [groups().legion],
		});
		stubLegionApi(10001, legionApiMember(10001, { unit: OTHER_UNIT }));
		await openAudit(page);

		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText(
			'Remove from Garrison Members; Primary group becomes 501st Members'
		);

		await fix(page, 'unitmember');
		expect(await getGroups('unitmember')).toEqual({
			usergroup: groups().legion,
			additionalgroups: [],
			displaygroup: 0,
		});
	});

	test('becomes Registered for a member who belongs in neither', async ({ page }) => {
		await setGroups('trooper1', { usergroup: groups().legion, additionalgroups: [REGISTERED] });
		await openAudit(page);

		await expect(row(page, 'trooper1').locator('.access-audit-change')).toHaveText(
			'Remove from 501st Members; Primary group becomes Registered'
		);

		await fix(page, 'trooper1');
		expect(await getGroups('trooper1')).toEqual({
			usergroup: REGISTERED,
			additionalgroups: [],
			displaygroup: 0,
		});
	});

	test('keeps a display group the audit does not manage', async ({ page }) => {
		await setGroups('guestmember', {
			additionalgroups: [groups().unit, groups().command],
			displaygroup: groups().command,
		});
		await openAudit(page);

		await fix(page, 'guestmember');
		expect((await getGroups('guestmember')).displaygroup).toBe(groups().command);
	});
});

test.describe('when the API cannot answer', () => {
	const unusable: Array<[string, Parameters<typeof stubLegionApi>[1] | null]> = [
		['is down', null],
		['answers with an error', { status: 500, body: { message: 'Internal Server Error' } }],
		['answers with something that is not JSON', { raw: '<html>Bad Gateway</html>' }],
	];

	for (const [answer, response] of unusable) {
		test(`leaves a member's groups alone when it ${answer}`, async ({ page }) => {
			if (response) {
				stubLegionApi(10001, response);
			} else {
				unstubLegionApi(10001);
			}
			await openAudit(page);

			const member = row(page, 'unitmember');
			await expect(member.locator('.access-audit-record')).toHaveText(
				'Could not reach the 501st Legion API'
			);
			await expect(member.locator('.access-audit-change')).toHaveText(
				'Unknown until the API answers'
			);
			await expect(fixButton(page, 'unitmember')).toHaveCount(0);
			expect((await counts(page)).unchecked).toBe(1);
		});
	}

	test('will not fix a member it cannot check, even when asked to directly', async ({ page }) => {
		await setGroups('unitmember', { additionalgroups: [groups().unit] });
		await openAudit(page);
		await expect(fixButton(page, 'unitmember')).toHaveCount(1);

		unstubLegionApi(10001);
		// Kept answers would otherwise still be used.
		await page.locator('#access_audit_refresh').click();
		await openAudit(page);
		await expect(fixButton(page, 'unitmember')).toHaveCount(0);

		const key = await page.locator('input[name="my_post_key"]').getAttribute('value');
		await page.request.post('/admin/index.php?module=user-access_audit&action=fix', {
			form: { my_post_key: key!, uid: String(uid('unitmember')) },
		});

		expect((await getGroups('unitmember')).additionalgroups).toEqual([groups().unit]);
	});

	test('asks again next time about a member it could not check', async ({ page }) => {
		unstubLegionApi(10001);
		await openAudit(page);
		await expect(row(page, 'unitmember')).toBeVisible();

		stubLegionApi(10001, legionApiMember(10001));
		await openAudit(page);
		await expect(row(page, 'unitmember')).toHaveCount(0);
		expect(memberRequests().filter((request) => request === '/legionId/10001')).toHaveLength(2);
	});
});

test.describe('answers kept from the API', () => {
	test('are reused for a while, so fixing a member does not ask about everybody again', async ({
		page,
	}) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page);
		const asked = memberRequests().length;

		await fix(page, 'guestmember');
		await openAudit(page);

		expect(memberRequests()).toHaveLength(asked);
		await expect(page.locator('#access_audit_checked_at')).toBeVisible();
	});

	test('are forgotten by Check Again', async ({ page }) => {
		await openAudit(page);
		const asked = memberRequests().length;

		stubLegionApi(10001, legionApiMember(10001, { status: 'Reserve' }));
		await openAudit(page);
		await expect(row(page, 'unitmember')).toHaveCount(0);

		await page.locator('#access_audit_refresh').click();
		await expect(row(page, 'unitmember').locator('.access-audit-status')).toHaveText('Reserve');
		expect(memberRequests()).toHaveLength(asked * 2);
	});
});

test.describe('fixing a member', () => {
	test('works out the change again rather than trusting the page', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page);
		await expect(fixButton(page, 'guestmember')).toBeVisible();

		// Somebody else sorts them out in the meantime.
		await setGroups('guestmember', { additionalgroups: [groups().command] });
		await fix(page, 'guestmember');

		await expect(flash(page)).toHaveText("guestmember's groups are already correct.");
		expect((await getGroups('guestmember')).additionalgroups).toEqual([groups().command]);
		expect(await adminLog()).toEqual([]);
	});

	test('fixes only the member whose button was pressed', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await setGroups('trooper1', { additionalgroups: [groups().legion] });
		await openAudit(page);

		await fix(page, 'trooper1');

		expect((await getGroups('trooper1')).additionalgroups).toEqual([]);
		expect((await getGroups('guestmember')).additionalgroups).toEqual([groups().unit]);
		await expect(fixButton(page, 'guestmember')).toBeVisible();
		expect((await counts(page)).fix).toBe(1);
	});

	test('keeps the filter it was pressed from', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page, { all: true });

		await fixButton(page, 'guestmember').click();
		await page.waitForURL(/show=all/);
		await expect(page.locator('tr.access-audit-row')).toHaveCount(9);
	});

	test('is written to the administrator log', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page);
		await fix(page, 'guestmember');

		const [entry] = await adminLog();
		expect(entry.module).toBe('user-access_audit');
		expect(entry.action).toBe('fix');

		await page.goto('/admin/index.php?module=tools-adminlog');
		await expect(page.locator('#content')).toContainText(
			`Fixed guestmember (#${uid('guestmember')}): Remove from Garrison Members`
		);
	});

	test('needs the post key', async ({ page }) => {
		await setGroups('guestmember', { additionalgroups: [groups().unit] });
		await openAudit(page);

		await page.request.post('/admin/index.php?module=user-access_audit&action=fix', {
			form: { my_post_key: 'not-the-key', uid: String(uid('guestmember')) },
		});
		await page.request.get(
			`/admin/index.php?module=user-access_audit&action=fix&uid=${uid('guestmember')}`
		);

		expect((await getGroups('guestmember')).additionalgroups).toEqual([groups().unit]);
	});

	test('leaves a banned member alone', async ({ page }) => {
		await setGroups('banned', { usergroup: BANNED, additionalgroups: [groups().legion] });
		await openAudit(page, { all: true });

		await expect(fixButton(page, 'banned')).toHaveCount(0);
		expect(memberRequests()).not.toContain('/legionId/30001');

		const key = await page.locator('input[name="my_post_key"]').getAttribute('value');
		await page.request.post('/admin/index.php?module=user-access_audit&action=fix', {
			form: { my_post_key: key!, uid: String(uid('banned')) },
		});
		expect((await getGroups('banned')).additionalgroups).toEqual([groups().legion]);
	});
});

test.describe('the order of the table', () => {
	test('is by Legion ID, with the members who have none last', async ({ page }) => {
		// Typed differently, and out of step with the usernames, to show it goes by the number.
		await setLegionId('unitmember', '10001');
		await setLegionId('trooper2', 'TK 9');
		await openAudit(page, { all: true });

		const order = await page
			.locator('tr.access-audit-row')
			.evaluateAll((rows) => rows.map((row) => row.id));
		expect(order).toEqual(
			[
				'trooper2', // 9
				'unitmember', // 10001
				'legionmember', // 10002
				'commander', // 10003
				'trooper1', // 20001
				'banned', // 30001
				'admin',
				'guestmember',
				'staffadmin',
			].map((username) => `access_audit_row_${uid(username)}`)
		);
		// Nobody shares one.
		await expect(page.locator('.access-audit-shared')).toHaveCount(0);
	});
});

test.describe('members sharing a Legion ID', () => {
	test('are noted, each with the others, however the ID was typed', async ({ page }) => {
		// unitmember is TK-10001.
		await setLegionId('trooper1', '10001');
		await setLegionId('trooper2', 'tk 010001');
		await openAudit(page);

		// Side by side, and listed even where the groups are right, as unitmember's are.
		const order = await page
			.locator('tr.access-audit-row')
			.evaluateAll((rows) => rows.map((row) => row.id));
		expect(order).toEqual(
			['trooper1', 'trooper2', 'unitmember'].map((username) => `access_audit_row_${uid(username)}`)
		);
		await expect(row(page, 'unitmember')).toHaveClass(/access-audit-shared-id/);
		await expect(row(page, 'unitmember').locator('.access-audit-change')).toHaveText('Nothing');

		const note = row(page, 'unitmember').locator('.access-audit-shared');
		await expect(note).toHaveText(
			'Same Legion ID as trooper1, trooper2. Check which account is really theirs before fixing either.'
		);
		await expect(note.locator('a')).toHaveText(['trooper1', 'trooper2']);
		await expect(note.locator('a').first()).toHaveAttribute(
			'href',
			`index.php?module=user-users&action=edit&uid=${uid('trooper1')}`
		);
		await expect(row(page, 'trooper1').locator('.access-audit-shared a')).toHaveText([
			'trooper2',
			'unitmember',
		]);

		expect((await counts(page)).shared).toBe(3);
		// One number, one lookup.
		expect(memberRequests().filter((request) => request === '/legionId/10001')).toHaveLength(1);
	});

	test('still leave the fix to the administrator', async ({ page }) => {
		await setLegionId('trooper1', 'TK-10001');
		await openAudit(page);

		// The record is unitmember's, so it says trooper1 belongs in both groups too. The
		// note is there so that is looked at, not pressed.
		await expect(row(page, 'trooper1').locator('.access-audit-change')).toHaveText(
			'Add to Garrison Members; Add to 501st Members'
		);
		await expect(fixButton(page, 'trooper1')).toBeVisible();
		await expect(fixButton(page, 'unitmember')).toHaveCount(0);
	});

	test('include a banned account', async ({ page }) => {
		// banned is TK-30001: a new account on a banned member's ID.
		await setLegionId('trooper1', 'TK-30001');
		stubLegionApi(30001, legionApiMember(30001));
		await openAudit(page);

		await expect(row(page, 'banned').locator('.access-audit-shared a')).toHaveText(['trooper1']);
		await expect(row(page, 'trooper1').locator('.access-audit-shared a')).toHaveText(['banned']);
		await expect(fixButton(page, 'banned')).toHaveCount(0);
	});

	test('are shown as text', async ({ page }) => {
		const rename = (username: string) =>
			execute(`UPDATE ${T('users')} SET username = ? WHERE uid = ?`, [username, uid('trooper1')]);
		// The per-test reset puts groups back, not names.
		await rename('<b id="sharer">x</b>');
		try {
			await setLegionId('trooper1', 'TK-10001');
			await openAudit(page);

			await expect(row(page, 'unitmember').locator('.access-audit-shared a')).toHaveText([
				'<b id="sharer">x</b>',
			]);
			await expect(page.locator('#sharer')).toHaveCount(0);
		} finally {
			await rename('trooper1');
		}
	});
});

test.describe('escaping', () => {
	test('shows what members typed and what the API says as text', async ({ page }) => {
		await setLegionId('trooper1', '<b id="typed">TK</b>-20001');
		await setGroups('trooper1');
		stubLegionApi(
			20001,
			legionApiMember(20001, {
				fullName: '<img id="named" src=x>',
				unit: { id: 80, name: '<i id="unit">Garrison</i>' },
			})
		);
		await openAudit(page);

		const trooper = row(page, 'trooper1');
		await expect(trooper.locator('.access-audit-legion-id')).toHaveText(
			'<b id="typed">TK</b>-20001'
		);
		await expect(trooper.locator('.access-audit-unit')).toHaveText('<i id="unit">Garrison</i>');
		await expect(trooper).toContainText('<img id="named" src=x>');
		await expect(page.locator('#typed, #named, #unit')).toHaveCount(0);
	});
});

test.describe('before it is set up', () => {
	test('says what is missing and links to the settings', async ({ page }) => {
		const restore = await withSettings({
			access_audit_unit: '',
			access_audit_reserve_group: '',
			access_audit_legion_group: '',
		});
		try {
			await page.goto('/admin/index.php?module=user-access_audit');

			const notice = page.locator('#access_audit_not_configured');
			await expect(notice).toContainText('The audit cannot run until it has been set up');
			await expect(notice.locator('li')).toHaveText([
				'Unit',
				'Reserve members group',
				'501st members group',
			]);
			await notice.locator('a', { hasText: 'Settings' }).click();
			await expect(page.locator('#access_audit_save_settings')).toBeVisible();
			expect(memberRequests()).toEqual([]);
		} finally {
			await restore();
		}
	});

	for (const [problem, value] of [
		['a deleted group', JSON.stringify([{ squad: SQUAD.id, name: SQUAD.name, group: 998 }])],
		['a setting it cannot read', 'not json'],
	]) {
		test(`counts squads with ${problem} as not set up, rather than skipping them`, async ({
			page,
		}) => {
			const restore = await withSettings({ access_audit_squads: value });
			try {
				await page.goto('/admin/index.php?module=user-access_audit');
				await expect(page.locator('#access_audit_not_configured li')).toHaveText([
					'Squads - one of their groups no longer exists, or the setting cannot be read',
				]);
				expect(memberRequests()).toEqual([]);
			} finally {
				await restore();
			}
		});
	}

	test("counts a squad's group that is one of the others as not set up", async ({ page }) => {
		const restore = await withSettings({
			access_audit_squads: JSON.stringify([
				{ squad: SQUAD.id, name: SQUAD.name, group: groups().legion },
			]),
		});
		try {
			await page.goto('/admin/index.php?module=user-access_audit');
			await expect(page.locator('#access_audit_not_configured li')).toHaveText([
				"Each squad's group must be a group of its own, not another squad's or one of the other four",
			]);
		} finally {
			await restore();
		}
	});
});

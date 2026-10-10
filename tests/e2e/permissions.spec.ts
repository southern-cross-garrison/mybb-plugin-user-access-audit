import { test, expect } from '../helpers/fixtures';
import { gotoAudit, gotoAuditSettings, loginToAdminCp } from '../helpers/auth';
import { runPhp } from '../helpers/container';
import { fixtures, getGroups, setGroups, uid } from '../helpers/db';
import { stubFixtureMembers } from '../helpers/legion-api';

/**
 * The page changes members' groups, so it is for administrators who may manage users:
 * MyBB's own "Can manage users?" permission, on Users & Groups.
 *
 * staffadmin is an ordinary administrator, not a super admin, so the permissions apply.
 */

/** Give staffadmin the Administrators group's permissions, with "Can manage users?" set. */
async function setCanManageUsers(allowed: boolean): Promise<void> {
	await runPhp(`
$defaults = $db->fetch_field($db->simple_select('adminoptions', 'permissions', 'uid = -4'), 'permissions');
$permissions = my_unserialize($defaults);
$permissions['user']['users'] = ${allowed ? 1 : 0};
$db->replace_query('adminoptions', array(
    'uid' => ${uid('staffadmin')},
    'notes' => '',
    'defaultviews' => '',
    'permissions' => $db->escape_string(my_serialize($permissions)),
));
echo "set";
`);
}

async function clearPermissions(): Promise<void> {
	await runPhp(`$db->delete_query('adminoptions', 'uid = ${uid('staffadmin')}'); echo "cleared";`);
}

test.afterEach(async () => {
	await clearPermissions();
});

test('is open to an administrator who can manage users', async ({ page }) => {
	stubFixtureMembers();
	await setCanManageUsers(true);
	await loginToAdminCp(page, 'staffadmin');

	await gotoAudit(page);
	await expect(page.locator('#access_audit_count_checked')).toBeVisible();
});

test('is closed to an administrator who cannot manage users', async ({ page }) => {
	stubFixtureMembers();
	await setCanManageUsers(false);
	await loginToAdminCp(page, 'staffadmin');

	for (const open of [gotoAudit, gotoAuditSettings]) {
		await open(page);
		await expect(page.locator('#content')).toContainText('Access Denied');
		await expect(
			page.locator('#access_audit_count_checked, #access_audit_save_settings')
		).toHaveCount(0);
	}
});

test('will not fix a member for an administrator who cannot manage users', async ({ page }) => {
	stubFixtureMembers();
	await setGroups('guestmember', { additionalgroups: [fixtures().groups.unit] });
	await setCanManageUsers(false);
	await loginToAdminCp(page, 'staffadmin');

	// A post key from a page this administrator can see.
	await page.goto('/admin/index.php');
	const key = await page
		.locator('input[name="my_post_key"], a[href*="my_post_key="]')
		.first()
		.evaluate(
			(element) =>
				element.getAttribute('value') ??
				element.getAttribute('href')?.match(/my_post_key=([a-f0-9]+)/)?.[1]
		);
	expect(key).toBeTruthy();

	await page.request.post('/admin/index.php?module=user-access_audit&action=fix', {
		form: { my_post_key: key!, uid: String(uid('guestmember')) },
	});

	expect((await getGroups('guestmember')).additionalgroups).toEqual([fixtures().groups.unit]);
});

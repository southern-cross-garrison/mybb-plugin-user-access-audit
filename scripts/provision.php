<?php
/**
 * Provisions the MyBB test forum with everything the Access Audit plugin e2e suite needs:
 * the groups it audits and one it must leave alone, a Legion ID profile field, fixture
 * members, and the plugin itself (installed and activated through MyBB's real plugin code
 * path) set up against the suite's stand-in for the 501st Legion API.
 *
 * Run inside the web container:  docker compose exec -T web php /dev/stdin < scripts/provision.php
 * Idempotent: safe to run repeatedly.
 */

define('IN_MYBB', 1);
define('MYBB_ROOT', '/var/www/html/');
define('THIS_SCRIPT', 'provision.php');
define('NO_ONLINE', 1);

require_once MYBB_ROOT . 'inc/init.php';

// Surface the failing query when something goes wrong during provisioning.
$mybb->dev_mode = 1;
// Loaded as global.php does. UserDataHandler's timezone check reads the timezone names
// from it, and on PHP 8 each one missing is a logged warning rather than a silent notice.
$lang->set_language($mybb->settings['bblanguage']);
$lang->load('global');
require_once MYBB_ROOT . 'inc/functions_user.php';
require_once MYBB_ROOT . 'inc/datahandlers/user.php';

/** @var DB_MySQLi $db */
/** @var MyBB $mybb */
/** @var datacache $cache */
/** @var pluginSystem $plugins */

function out($msg) { echo "    " . $msg . "\n"; }
function fail($msg) { fwrite(STDERR, "PROVISION ERROR: " . $msg . "\n"); exit(1); }

// ---------------------------------------------------------------------------
// Board settings tuned for deterministic testing
// ---------------------------------------------------------------------------
$board_settings = array(
    'dateformat'         => 'Y-m-d',
    'timeformat'         => 'H:i',
    'failedlogincount'   => '0',
    'captchaimage'       => '0',
    'regtype'            => 'instant',
    'verifyemail'        => '0',
    'securityquestion'   => '0',
    'usereferrals'       => '0',
    'dstcorrection'      => '0',
    'timezoneoffset'     => '0',
    // Send PHP/SQL errors to a file the e2e suite can assert on.
    'errorlogmedium'     => 'log',
    'errorloglocation'   => './cache/mybb_errors.log',
);
foreach ($board_settings as $name => $value) {
    $db->update_query('settings', array('value' => $db->escape_string($value)), "name = '" . $db->escape_string($name) . "'");
}
rebuild_settings();
out('board settings applied');

// ---------------------------------------------------------------------------
// Usergroups (cloned from "Registered" so every column gets a sane value)
// ---------------------------------------------------------------------------
function provision_usergroup($title)
{
    global $db, $cache;

    $existing = $db->fetch_array($db->simple_select('usergroups', 'gid', "title = '" . $db->escape_string($title) . "'"));
    if ($existing) {
        return (int)$existing['gid'];
    }

    $template = $db->fetch_array($db->simple_select('usergroups', '*', 'gid = 2'));
    unset($template['gid']);
    $template['title'] = $title;
    $template['type'] = 2; // user-assignable additional group
    $template['namestyle'] = '{username}';
    $template['usertitle'] = '';
    $template['isbannedgroup'] = 0;
    $template['candisplaygroup'] = 1;

    foreach ($template as $k => $v) {
        $template[$k] = $db->escape_string((string)$v);
    }

    $gid = (int)$db->insert_query('usergroups', $template);
    $cache->update_usergroups();
    return $gid;
}

$groups = array(
    // The two the audit manages.
    'unit' => provision_usergroup('Garrison Members'),
    'legion' => provision_usergroup('501st Members'),
    // One it must never touch, the way a board's command staff group is.
    'command' => provision_usergroup('Command Staff'),
);
out('usergroups: ' . json_encode($groups));

// ---------------------------------------------------------------------------
// The Legion ID profile field, and one other the Settings tab offers beside it
// ---------------------------------------------------------------------------
$profile_field_specs = array(
    'legion_id' => array('name' => 'Legion ID', 'description' => 'Your 501st Legion ID'),
    'costumes' => array('name' => 'Costumes', 'description' => 'Costumes you own'),
);

$field_ids = array();
$disporder = 4;
foreach ($profile_field_specs as $key => $spec) {
    $existing = $db->fetch_array($db->simple_select('profilefields', 'fid', "name = '" . $db->escape_string($spec['name']) . "'"));
    if ($existing) {
        $field_ids[$key] = (int)$existing['fid'];
        continue;
    }

    $fid = (int)$db->insert_query('profilefields', array(
        'name' => $db->escape_string($spec['name']),
        'description' => $db->escape_string($spec['description']),
        'disporder' => $disporder++,
        'type' => 'text',
        'regex' => '',
        'length' => 0,
        'maxlength' => 0,
        'required' => 0,
        'registration' => 0,
        'profile' => 1,
        'viewableby' => '-1',
        'editableby' => '-1',
        'postbit' => 0,
        'postnum' => 0,
        'allowhtml' => 0,
        'allowmycode' => 0,
        'allowsmilies' => 0,
        'allowimgcode' => 0,
        'allowvideocode' => 0,
    ));
    $db->write_query('ALTER TABLE ' . TABLE_PREFIX . "userfields ADD fid{$fid} TEXT");
    $field_ids[$key] = $fid;
}
$cache->update_profilefields();
out('profile fields: ' . json_encode($field_ids));

// ---------------------------------------------------------------------------
// Fixture members
// ---------------------------------------------------------------------------
// Each starts with its groups right for the 501st record the suite usually stubs for it
// (tests/helpers/legion-api.ts); the specs move them about from there. The Legion IDs are
// made up, and only ever looked up in the stand-in API.
$FIXTURE_PASSWORD = 'testpass123';

$users = array(
    // Active in this unit.
    'unitmember' => array('usergroup' => 2, 'additionalgroups' => array($groups['unit']), 'legion_id' => 'TK-10001'),
    // Active in another unit.
    'legionmember' => array('usergroup' => 2, 'additionalgroups' => array($groups['legion']), 'legion_id' => 'TK-10002'),
    // Active in this unit and on its command staff.
    'commander' => array('usergroup' => 2, 'additionalgroups' => array($groups['command'], $groups['unit']), 'legion_id' => 'TK-10003'),
    // Has never given a Legion ID, and is in neither group.
    'guestmember' => array('usergroup' => 2, 'additionalgroups' => array(), 'legion_id' => ''),
    // Spare members the specs set up however they need.
    'trooper1' => array('usergroup' => 2, 'additionalgroups' => array(), 'legion_id' => 'TK-20001'),
    'trooper2' => array('usergroup' => 2, 'additionalgroups' => array(), 'legion_id' => 'TK-20002'),
    // An administrator who is not a super admin, so the Admin CP's permissions apply.
    'staffadmin' => array('usergroup' => 4, 'additionalgroups' => array(), 'legion_id' => ''),
    // In MyBB's Banned group, with a Legion ID and the unit's group from before the ban.
    'banned' => array('usergroup' => 7, 'additionalgroups' => array($groups['unit']), 'legion_id' => 'TK-30001'),
);

$user_ids = array('admin' => (int)$db->fetch_field($db->simple_select('users', 'uid', "username = 'admin'"), 'uid'));
foreach ($users as $username => $spec) {
    $existing = $db->fetch_array($db->simple_select('users', 'uid', "username = '" . $db->escape_string($username) . "'"));
    if ($existing) {
        $uid = (int)$existing['uid'];
    } else {
        $handler = new UserDataHandler('insert');
        $handler->set_data(array(
            'username' => $username,
            'password' => $FIXTURE_PASSWORD,
            'password2' => $FIXTURE_PASSWORD,
            'email' => $username . '@example.test',
            'email2' => $username . '@example.test',
            'usergroup' => 2,
            'regip' => '127.0.0.1',
            'lastip' => '127.0.0.1',
            'language' => '',
            'regdate' => TIME_NOW,
        ));
        if (!$handler->validate_user()) {
            fail("could not create user {$username}: " . implode('; ', $handler->get_friendly_errors()));
        }
        $info = $handler->insert_user();
        $uid = (int)$info['uid'];
    }

    $db->update_query('users', array(
        'usergroup' => (int)$spec['usergroup'],
        'additionalgroups' => implode(',', $spec['additionalgroups']),
        'displaygroup' => 0,
    ), 'uid = ' . $uid);

    if (!$db->fetch_field($db->simple_select('userfields', 'ufid', 'ufid = ' . $uid), 'ufid')) {
        $db->insert_query('userfields', array('ufid' => $uid));
    }
    $db->update_query('userfields', array(
        'fid' . $field_ids['legion_id'] => $db->escape_string($spec['legion_id']),
    ), 'ufid = ' . $uid);

    $user_ids[$username] = $uid;
}
out('users: ' . json_encode($user_ids));

// ---------------------------------------------------------------------------
// Install + activate the plugin through MyBB's real plugin code path
// ---------------------------------------------------------------------------
$plugin_file = MYBB_ROOT . 'inc/plugins/access_audit.php';
if (!file_exists($plugin_file)) {
    fail("access_audit plugin file missing at {$plugin_file} - run scripts/deploy.sh");
}
require_once $plugin_file;

if (!access_audit_is_installed()) {
    access_audit_install();
    out('access_audit plugin installed');
}
access_audit_activate();
out('access_audit plugin activated');

$plugin_cache = $cache->read('plugins');
$active = isset($plugin_cache['active']) ? $plugin_cache['active'] : array();
$active['access_audit'] = 'access_audit';
$cache->update('plugins', array('active' => $active));

// The unit is the Southern Cross Garrison's id in the 501st's records, which the stand-in
// API's list of units carries too.
$plugin_settings = array(
    // Never the real 501st API. This is the suite's stand-in (tests/stubs/legion-api.php,
    // copied in by global setup), which answers 503 for anything a test has not stubbed.
    'access_audit_api_url' => 'http://localhost/e2e-legion-api.php',
    'access_audit_unit' => '80',
    'access_audit_unit_name' => 'Southern Cross Garrison',
    'access_audit_legion_id_field' => (string)$field_ids['legion_id'],
    'access_audit_unit_group' => (string)$groups['unit'],
    'access_audit_legion_group' => (string)$groups['legion'],
);
foreach ($plugin_settings as $name => $value) {
    $db->update_query('settings', array('value' => $db->escape_string($value)), "name = '" . $db->escape_string($name) . "'");
}
rebuild_settings();
$db->delete_query('access_audit_lookups');
out('access_audit settings applied');

// ---------------------------------------------------------------------------
// Emit the fixture map for the e2e suite
// ---------------------------------------------------------------------------
$fixtures = array(
    'password' => $FIXTURE_PASSWORD,
    'groups' => $groups,
    'profileFields' => $field_ids,
    'users' => $user_ids,
);
file_put_contents(MYBB_ROOT . 'access-audit-fixtures.json', json_encode($fixtures, JSON_PRETTY_PRINT));
out('fixtures written to access-audit-fixtures.json');

echo "PROVISION OK\n";

<?php
/**
 * MyBB Access Audit Plugin
 *
 * Checks every member's Legion ID against the 501st Legion's API and puts them in the
 * right groups: the 501st's members, and for the unit's own members its active or reserve
 * group as well. Everything happens in the Admin CP, under Users & Groups -> Access Audit.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

global $plugins;

if(defined('IN_ADMINCP'))
{
    // The page lives under Users & Groups and is guarded by MyBB's own "Can manage users?"
    // permission (see the page itself) rather than one of its own: a declared permission
    // starts out as "No" for every administrator whose permissions were saved before the
    // plugin arrived, and the page changes members' groups, which is managing users.
    $plugins->add_hook("admin_user_menu", "access_audit_admin_user_menu");
    $plugins->add_hook("admin_user_action_handler", "access_audit_admin_user_action_handler");
    $plugins->add_hook("admin_tools_get_admin_log_action", "access_audit_admin_log_action");
}

/**
 * Plugin information.
 *
 * @return array
 */
function access_audit_info()
{
    return array(
        "name"          => "Access Audit",
        "description"   => "Audits members' group access against their 501st Legion membership: every Active or Reserve 501st member in the 501st group, the unit's own in its active or reserve group as well, and everybody else in none of them.",
        "website"       => "https://github.com/southern-cross-garrison/mybb-plugin-user-access-audit",
        "author"        => "Kevin Brown (TK-33151)",
        "authorsite"    => "https://www.501scg.org/",
        "version"       => "1.0.2",
        "guid"          => "85f3ee26-1f34-4aeb-81ce-a7366de69a85",
        "codename"      => "access_audit",
        "compatibility" => "18*"
    );
}

/**
 * @return bool
 */
function access_audit_is_installed()
{
    global $db;

    return $db->table_exists('access_audit_lookups');
}

/**
 * Create the plugin's tables and settings.
 */
function access_audit_install()
{
    require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_install.php";

    // The baseline schema, then every upgrade since - the same steps an installed board
    // takes on activation.
    access_audit_install_database();
    access_audit_upgrade_database();
    access_audit_install_settings();
}

/**
 * Activate: bring the schema and settings up to date.
 */
function access_audit_activate()
{
    require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_install.php";

    // MyBB has no upgrade hook, so schema changes land here - _install() only ever runs
    // once, on a board that has no tables yet.
    access_audit_upgrade_database();
    access_audit_install_settings();
}

/**
 * Deactivate. There is nothing to take out: the plugin adds no templates, and the Admin CP
 * page goes with the hooks that register it. Settings and cached lookups are kept.
 */
function access_audit_deactivate()
{
}

/**
 * Remove every trace of the plugin. Members' groups are left as they are.
 */
function access_audit_uninstall()
{
    require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_install.php";

    access_audit_uninstall_database();

    rebuild_settings();
}

/**
 * Hooks: admin_user_menu
 *
 * @param array $sub_menu
 * @return array
 */
function access_audit_admin_user_menu(&$sub_menu)
{
    global $lang;

    $lang->load('user_access_audit', false, true);

    $sub_menu[] = array(
        'id'    => 'access_audit',
        'title' => $lang->access_audit,
        'link'  => 'index.php?module=user-access_audit'
    );

    return $sub_menu;
}

/**
 * Hooks: admin_user_action_handler
 *
 * @param array $actions
 * @return array
 */
function access_audit_admin_user_action_handler(&$actions)
{
    $actions['access_audit'] = array('active' => 'access_audit', 'file' => 'access_audit.php');

    return $actions;
}

/**
 * Word the plugin's entries in the administrator log. MyBB looks the wording up as
 * admin_log_<module>_<action>, which is in the plugin's own Admin CP language file.
 *
 * Hooks: admin_tools_get_admin_log_action
 *
 * @param array $args
 */
function access_audit_admin_log_action(&$args)
{
    global $lang;

    if($args['logitem']['module'] === 'user-access_audit')
    {
        $lang->load('user_access_audit', false, true);
    }
}

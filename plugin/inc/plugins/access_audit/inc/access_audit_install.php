<?php
/**
 * MyBB Access Audit Plugin - Installation
 *
 * The schema, the schema upgrade runner and the settings.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_functions.php";

/**
 * Every table the plugin owns, unprefixed. Uninstall walks this list, so a table added
 * here is dropped with the rest.
 */
function access_audit_plugin_tables()
{
    return array(
        'access_audit_lookups',
        'access_audit_schema_upgrades'
    );
}

/**
 * The character set every plugin table is created with. utf8mb4 regardless of the
 * board's own connection encoding, since members' names come from the 501st's records.
 */
function access_audit_table_charset()
{
    return "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

/**
 * Create the baseline schema.
 *
 * Frozen once released. Do not edit this CREATE TABLE to change the schema - add an
 * upgrade file instead (see access_audit_upgrade_database()), which access_audit_install()
 * runs straight after this, so a new board gets the change by the same step an existing
 * one does.
 */
function access_audit_install_database()
{
    global $db;

    $charset = access_audit_table_charset();

    // What the 501st's API last said about each Legion ID, so that fixing one member and
    // coming back to the audit does not ask the API about every member again. Only real
    // answers are kept: an API that was down is asked again next time. profile_url is
    // dropped again by upgrade 0001.
    $db->write_query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "access_audit_lookups` (
        `legion_id` int(10) unsigned NOT NULL,
        `member_status` varchar(50) NOT NULL DEFAULT '',
        `garrison_id` int(10) unsigned NOT NULL DEFAULT 0,
        `garrison_name` varchar(255) NOT NULL DEFAULT '',
        `full_name` varchar(255) NOT NULL DEFAULT '',
        `profile_url` varchar(255) NOT NULL DEFAULT '',
        `fetched_at` int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`legion_id`)
    ) ENGINE=MyISAM {$charset};");

    access_audit_create_schema_upgrades_table();
}

/**
 * Which schema upgrades a board has had, one row per file in upgrades/.
 */
function access_audit_create_schema_upgrades_table()
{
    global $db;

    $charset = access_audit_table_charset();

    $db->write_query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "access_audit_schema_upgrades` (
        `name` varchar(191) NOT NULL,
        `applied_at` int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`name`)
    ) ENGINE=MyISAM {$charset};");
}

/**
 * Every schema upgrade the plugin ships, in the order they run.
 *
 * One file each in upgrades/, named with a zero-padded sequence number first
 * (0001_add_something.php) so that sorting by name is sorting by age. A file returns a
 * closure that makes the change; it does not define a function, so two upgrades can never
 * collide over a name.
 *
 * @return array Upgrade name (the file name without .php) => path
 */
function access_audit_schema_upgrades()
{
    $upgrades = array();
    foreach((array)glob(MYBB_ROOT . "inc/plugins/access_audit/upgrades/*.php") as $path)
    {
        $upgrades[basename($path, '.php')] = $path;
    }

    ksort($upgrades, SORT_STRING);

    return $upgrades;
}

/**
 * Bring the board's schema up to date.
 *
 * MyBB gives plugins no upgrade hook and CREATE TABLE IF NOT EXISTS will not add a
 * column, so this runs on every activation as well as straight after install: each file
 * in upgrades/ the board has not had yet is run, oldest first, and recorded. Each upgrade
 * must be safe to run again, every step guarded on its own: MyISAM has no transactions,
 * and an upgrade is recorded only after it finishes.
 */
function access_audit_upgrade_database()
{
    global $db;

    access_audit_create_schema_upgrades_table();

    $applied = array();
    $query = $db->simple_select("access_audit_schema_upgrades", "name");
    while($row = $db->fetch_array($query))
    {
        $applied[$row['name']] = true;
    }

    foreach(access_audit_schema_upgrades() as $name => $path)
    {
        if(isset($applied[$name]))
        {
            continue;
        }

        $upgrade = require $path;
        if(!is_callable($upgrade))
        {
            // A file that forgot its return would otherwise be recorded as done without
            // having changed anything.
            trigger_error("Access Audit plugin upgrade {$name} does not return a closure.", E_USER_ERROR);
        }

        $upgrade($db);

        $db->replace_query("access_audit_schema_upgrades", array(
            'name'       => $db->escape_string($name),
            'applied_at' => TIME_NOW,
        ));
    }
}

/**
 * Create any of the plugin's settings the board does not have yet.
 *
 * Called from both install and activate, so a setting added in a later version reaches a
 * board that already has the plugin. Existing settings are left alone, values and all.
 *
 * They are edited on the plugin's own Settings tab, which can offer the API's list of
 * units and the board's profile fields as dropdowns; MyBB's settings screen shows them as
 * plain boxes, so the ones it cannot draw a picker for say to go there instead.
 */
function access_audit_install_settings()
{
    global $db;

    $query = $db->simple_select("settinggroups", "gid", "name = 'access_audit'");
    $group = $db->fetch_array($query);
    if($group)
    {
        $gid = (int)$group['gid'];
    }
    else
    {
        $db->insert_query("settinggroups", array(
            "name"        => "access_audit",
            "title"       => $db->escape_string("Access Audit"),
            "description" => $db->escape_string("Which unit and groups the access audit checks members against. Easiest set under Users & Groups -> Access Audit -> Settings."),
            "disporder"   => 50,
            "isdefault"   => 0
        ));
        $gid = (int)$db->insert_id();
    }

    $settings = array(
        array(
            "name"        => "access_audit_api_url",
            "title"       => "501st Legion API",
            "description" => "Where members' 501st records and the list of units are fetched from.",
            "optionscode" => "text",
            "value"       => ACCESS_AUDIT_DEFAULT_API_URL,
            "disporder"   => 1,
        ),
        array(
            "name"        => "access_audit_unit",
            "title"       => "Unit",
            "description" => "The id of this board's unit in the 501st's records. Choose it from the list under Users & Groups -> Access Audit -> Settings rather than here.",
            "optionscode" => "text",
            "value"       => "",
            "disporder"   => 2,
        ),
        array(
            "name"        => "access_audit_unit_name",
            "title"       => "Unit Name",
            "description" => "The unit's name, as the 501st's records had it when it was chosen. Kept so the audit can name it while the API is down.",
            "optionscode" => "text",
            "value"       => "",
            "disporder"   => 3,
        ),
        array(
            "name"        => "access_audit_legion_id_field",
            "title"       => "Legion ID Profile Field",
            "description" => "The id of the custom profile field that holds a member's Legion ID. Choose it from the list under Users & Groups -> Access Audit -> Settings rather than here.",
            "optionscode" => "text",
            "value"       => "",
            "disporder"   => 4,
        ),
        array(
            "name"        => "access_audit_unit_group",
            "title"       => "Unit Members Group",
            "description" => "Active members of this unit belong in this group.",
            "optionscode" => "groupselectsingle",
            "value"       => "",
            "disporder"   => 5,
        ),
        array(
            "name"        => "access_audit_legion_group",
            "title"       => "501st Members Group",
            "description" => "Every active or reserve 501st member belongs in this group, this unit's own included.",
            "optionscode" => "groupselectsingle",
            "value"       => "",
            "disporder"   => 6,
        ),
        // Last, though it sits beside the unit members group on the plugin's own tab: a
        // board that had the plugin before it already has 5 and 6 taken.
        array(
            "name"        => "access_audit_reserve_group",
            "title"       => "Reserve Members Group",
            "description" => "Reserve members of this unit belong in this group.",
            "optionscode" => "groupselectsingle",
            "value"       => "",
            "disporder"   => 7,
        ),
    );

    foreach($settings as $setting)
    {
        if($db->num_rows($db->simple_select("settings", "sid", "name = '" . $db->escape_string($setting['name']) . "'")) > 0)
        {
            continue;
        }

        // insert_query() quotes values but does not escape them.
        $db->insert_query("settings", array(
            'name'        => $db->escape_string($setting['name']),
            'title'       => $db->escape_string($setting['title']),
            'description' => $db->escape_string($setting['description']),
            'optionscode' => $db->escape_string($setting['optionscode']),
            'value'       => $db->escape_string($setting['value']),
            'disporder'   => (int)$setting['disporder'],
            'gid'         => $gid,
        ));
    }

    rebuild_settings();
}

/**
 * Drop the plugin's tables and settings.
 */
function access_audit_uninstall_database()
{
    global $db;

    foreach(access_audit_plugin_tables() as $table)
    {
        $db->write_query("DROP TABLE IF EXISTS `" . TABLE_PREFIX . $table . "`");
    }

    $db->delete_query("settings", "name LIKE 'access\\_audit\\_%'");
    $db->delete_query("settinggroups", "name = 'access_audit'");
}

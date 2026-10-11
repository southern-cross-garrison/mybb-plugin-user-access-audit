<?php
/**
 * MyBB Access Audit Plugin - Upgrade 0004: access_audit_found_legion_ids
 *
 * Every Legion ID the email lookups have saved to a member's profile, for the Legion IDs tab
 * to list, and so that one taken off a profile again is never saved back.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

/**
 * @param DB_Base $db
 */
return function($db)
{
    $db->write_query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "access_audit_found_legion_ids` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `uid` int(10) unsigned NOT NULL,
        `legion_id` int(10) unsigned NOT NULL,
        `found_at` int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `uid_legion_id` (`uid`, `legion_id`)
    ) ENGINE=MyISAM " . access_audit_table_charset() . ";");
};

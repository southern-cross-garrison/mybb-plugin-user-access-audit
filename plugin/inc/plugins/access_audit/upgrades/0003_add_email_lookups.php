<?php
/**
 * MyBB Access Audit Plugin - Upgrade 0003: access_audit_email_lookups
 *
 * What the API last said about each email address, for finding the Legion ID of a member
 * who has not put one on their profile. Kept by a hash of the address, never the address
 * itself, and only real answers: 0 for an address no approved member has.
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
    $db->write_query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "access_audit_email_lookups` (
        `email_hash` char(40) NOT NULL,
        `legion_id` int(10) unsigned NOT NULL DEFAULT 0,
        `fetched_at` int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`email_hash`)
    ) ENGINE=MyISAM " . access_audit_table_charset() . ";");
};

<?php
/**
 * MyBB Access Audit Plugin - Upgrade 0002: access_audit_lookups.squad_id and squad_name
 *
 * The squad a member's record puts them in, for auditing the squads' groups.
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
    if(!$db->field_exists('squad_id', 'access_audit_lookups'))
    {
        $db->add_column('access_audit_lookups', 'squad_id', "int(10) unsigned NOT NULL DEFAULT 0 AFTER `garrison_name`");
    }

    if(!$db->field_exists('squad_name', 'access_audit_lookups'))
    {
        $db->add_column('access_audit_lookups', 'squad_name', "varchar(255) NOT NULL DEFAULT '' AFTER `squad_id`");
    }

    // An answer kept from before has no squad in it, which would read as a member in none
    // and have the audit taking them out of their squad's group. Asking again costs a
    // lookup per member, once.
    $db->delete_query('access_audit_lookups');
};

<?php
/**
 * MyBB Access Audit Plugin - Upgrade 0001: drop access_audit_lookups.profile_url
 *
 * The audit no longer links to the profile address the API gives: a member's 501st.com
 * page is linked from their Legion ID instead, and that address is built from the number,
 * so there is nothing to keep it for.
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
    if($db->field_exists('profile_url', 'access_audit_lookups'))
    {
        $db->drop_column('access_audit_lookups', 'profile_url');
    }
};

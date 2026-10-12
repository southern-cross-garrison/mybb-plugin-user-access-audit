<?php
/**
 * MyBB Access Audit Plugin - Admin CP language strings
 */

$l['access_audit'] = "Access Audit";

$l['access_audit_tab_audit'] = "Audit";
$l['access_audit_tab_audit_desc'] = "Every member's groups checked against their 501st Legion record. Only the groups chosen on the Settings tab are changed.";
$l['access_audit_tab_legion_ids'] = "Legion IDs";
$l['access_audit_tab_legion_ids_desc'] = "Members with no Legion ID on their profile, looked up by their email address in the background, and the Legion IDs found for them.";
$l['access_audit_tab_settings'] = "Settings";
$l['access_audit_tab_settings_desc'] = "Which unit this board belongs to, where members' Legion IDs are kept, and which squads and groups the audit manages.";

// Settings.
$l['access_audit_choose'] = "Choose...";
$l['access_audit_setting_api_url'] = "501st Legion API";
$l['access_audit_setting_api_url_desc'] = "Where members' records and the list of units are fetched from. Normally https://api.501st.com.";
$l['access_audit_setting_unit'] = "Unit";
$l['access_audit_setting_unit_desc'] = "This board's garrison or outpost.";
$l['access_audit_setting_field'] = "Legion ID profile field";
$l['access_audit_setting_field_desc'] = "The custom profile field members put their Legion ID in. \"TK-12345\", \"TK 12345\" and \"12345\" are all read as 12345.";
$l['access_audit_setting_unit_group'] = "Unit members group";
$l['access_audit_setting_unit_group_desc'] = "For Active members of this unit.";
$l['access_audit_setting_reserve_group'] = "Reserve members group";
$l['access_audit_setting_reserve_group_desc'] = "For Reserve members of this unit.";
$l['access_audit_setting_retired_group'] = "Retired members group";
$l['access_audit_setting_retired_group_desc'] = "For Retired members of this unit, who are in none of the other groups.";
$l['access_audit_setting_legion_group'] = "501st members group";
$l['access_audit_setting_legion_group_desc'] = "For Active and Reserve members of any unit, this one included.";
$l['access_audit_setting_same_group'] = "The unit members, reserve members, retired members and 501st members groups must be four different groups";
$l['access_audit_save_settings'] = "Save Settings";
$l['access_audit_settings_saved'] = "The settings have been saved.";
$l['access_audit_squads'] = "Squads";
$l['access_audit_squads_desc'] = "Active members of each squad given a group here are also put in that group. Leave a squad at Choose... to not audit it.";
$l['access_audit_squads_no_unit'] = "Choose this board's unit and save to see its squads.";
$l['access_audit_col_squad'] = "Squad";
$l['access_audit_col_squad_group'] = "Its group";
$l['access_audit_squad_not_unit'] = "(not one of this unit's squads)";
$l['access_audit_squads_unavailable'] = "The list of squads could not be fetched from the 501st Legion API. The squads already chosen can still be kept, changed or taken off.";
$l['access_audit_setting_squads'] = "Squads - one of their groups no longer exists, or the setting cannot be read";
$l['access_audit_setting_squad_same_group'] = "Each squad's group must be a group of its own, not another squad's or one of the other four";
$l['access_audit_units_unavailable'] = "The list of units could not be fetched from the 501st Legion API. Check its address, or try again later.";

$l['access_audit_error_api_url'] = "Enter the 501st Legion API's address, starting with http:// or https://.";
$l['access_audit_error_unit'] = "Choose this board's unit.";
$l['access_audit_error_units_unavailable'] = "The list of units could not be fetched from the 501st Legion API, so the unit cannot be changed right now.";
$l['access_audit_error_field'] = "Choose the profile field that holds members' Legion IDs.";
$l['access_audit_error_unit_group'] = "Choose the unit members group.";
$l['access_audit_error_reserve_group'] = "Choose the reserve members group.";
$l['access_audit_error_retired_group'] = "Choose the retired members group.";
$l['access_audit_error_legion_group'] = "Choose the 501st members group.";
$l['access_audit_error_same_group'] = "The unit members, reserve members, retired members and 501st members groups must be four different groups.";
$l['access_audit_error_squad'] = "Only squads of this board's unit can be audited. Set the ones that are not back to Choose...";
$l['access_audit_error_squads_unavailable'] = "The list of squads could not be fetched from the 501st Legion API, so squads cannot be added right now.";
$l['access_audit_error_squad_group'] = "Each squad's group must be one of the board's groups.";
$l['access_audit_error_squad_same_group'] = "Each squad needs a group of its own, not another squad's or one of the unit members, reserve members, retired members and 501st members groups.";

// The audit.
$l['access_audit_not_configured'] = "The audit cannot run until it has been set up on the <a href=\"{1}\">Settings</a> tab. Still needed:";
$l['access_audit_not_configured_short'] = "The audit has not been set up yet.";
$l['access_audit_summary'] = "Summary";
$l['access_audit_summary_rules'] = "Active members of <strong>{1}</strong> belong in both <strong>{2}</strong> and <strong>{3}</strong>, and its Reserve members in both <strong>{4}</strong> and <strong>{3}</strong>. Its Retired members belong in <strong>{5}</strong> only. Active and Reserve members of any other unit belong in <strong>{3}</strong> only. Anybody else - no Legion ID, any other status, or no public 501st record - belongs in none of them, except that a member with no public record who is in <strong>{5}</strong> already is left in it, since a retired member's record can be private.";
$l['access_audit_summary_squad'] = "Active members of <strong>{1}</strong> belong in <strong>{2}</strong> as well.";
$l['access_audit_summary_email'] = "Members with no Legion ID on their profile are looked up by email address in the background (see <a href=\"{1}\">Legion IDs</a>) and are not checked until then.";
$l['access_audit_count_checked'] = "Members checked";
$l['access_audit_count_ok'] = "Groups correct";
$l['access_audit_count_fix'] = "Groups need changing";
$l['access_audit_count_unchecked'] = "Could not be checked";
$l['access_audit_count_banned'] = "Banned, left alone";
$l['access_audit_count_shared'] = "Sharing a Legion ID with another member";
$l['access_audit_count_waiting'] = "Waiting to be looked up by email address";
$l['access_audit_count_waiting_desc'] = "Members with no Legion ID on their profile, not looked up yet or with one found and not saved yet. See <a href=\"{1}\">Legion IDs</a> for how long that will take.";
$l['access_audit_checked_at'] = "Checked against the 501st";
$l['access_audit_members'] = "Members";
$l['access_audit_show_all'] = "Show every member";
$l['access_audit_show_problems'] = "Show only members needing attention";
$l['access_audit_all_ok'] = "Every member's groups are correct.";
$l['access_audit_no_members'] = "There are no members.";

$l['access_audit_col_member'] = "Member";
$l['access_audit_col_posts'] = "Posts";
$l['access_audit_col_legion_id'] = "Legion ID";
$l['access_audit_col_record'] = "501st record";
$l['access_audit_col_groups'] = "In now";
$l['access_audit_col_change'] = "Needs";
$l['access_audit_col_action'] = "Action";

$l['access_audit_none'] = "None";
$l['access_audit_in_none'] = "None of them";
$l['access_audit_primary'] = "(primary)";
$l['access_audit_record_no_legion_id'] = "No Legion ID on their profile";
$l['access_audit_record_unchecked'] = "Could not reach the 501st Legion API";
$l['access_audit_record_waiting'] = "No Legion ID on their profile, and not looked up by email address yet";
$l['access_audit_record_classified'] = "No public record - private, retired, or not a real Legion ID";
$l['access_audit_record_classified_retired'] = "Left in {1}: a retired member's record can be private, so whether they have retired cannot be told.";
$l['access_audit_record_banned'] = "Banned - left alone";
$l['access_audit_shared_with'] = "Same Legion ID as {1}. Check which account is really theirs before fixing either.";

$l['access_audit_change_add'] = "Add to {1}";
$l['access_audit_change_remove'] = "Remove from {1}";
$l['access_audit_change_primary'] = "Primary group becomes {1}";
$l['access_audit_change_separator'] = "; ";
$l['access_audit_change_none'] = "Nothing";
$l['access_audit_change_unknown'] = "Unknown until the API answers";
$l['access_audit_unknown_group'] = "Group #{1}";

$l['access_audit_fix'] = "Fix";
$l['access_audit_refresh'] = "Check Again";

// Results.
$l['access_audit_fixed'] = "{1} has been fixed: {2}.";
$l['access_audit_nothing_to_fix'] = "{1}'s groups are already correct.";
$l['access_audit_error_no_member'] = "That member does not exist.";
$l['access_audit_error_unchecked'] = "{1} could not be checked: the 501st Legion API did not answer. Nothing was changed.";
$l['access_audit_error_waiting'] = "{1} could not be checked: they have no Legion ID on their profile and have not been looked up by email address yet. Nothing was changed.";
$l['access_audit_error_changed'] = "{1}'s groups were changed by somebody else while the audit ran, so nothing was changed. Check them again.";

// Legion IDs by email address.
$l['access_audit_lookup_not_configured'] = "Members are looked up by email address once the audit has been set up on the <a href=\"{1}\">Settings</a> tab.";
$l['access_audit_lookup_rules'] = "Members with no Legion ID on their profile are looked up by email address, matched against approved 501st members' primary and secondary addresses. A Legion ID found is saved to their profile; one a member typed is never replaced, and no groups are changed. Lookups run in the background, {1} every 15 minutes. An address with no match is looked up again after a week.";
$l['access_audit_lookup_members'] = "Members with no Legion ID";
$l['access_audit_lookup_never'] = "Not looked up yet";
$l['access_audit_lookup_unsaved'] = "Legion ID found, saved on the next run";
$l['access_audit_lookup_recheck'] = "No 501st member had their address a week ago, to be looked up again";
$l['access_audit_lookup_no_match'] = "Looked up, no 501st member has their address";
$l['access_audit_lookup_found'] = "Legion IDs saved from email addresses";
$l['access_audit_lookup_allowance'] = "Lookups the API allows now";
$l['access_audit_lookup_allowance_value'] = "{1} of {2}";
$l['access_audit_lookup_next'] = "Next lookups";
$l['access_audit_lookup_next_value'] = "{1} more batches, about {2} minutes. The next run is {3}.";
$l['access_audit_lookup_next_save'] = "Only saving the Legion IDs found. The next run is {1}.";
$l['access_audit_lookup_next_now'] = "due now, on the next page view of the forum";
$l['access_audit_lookup_up_to_date'] = "Everybody has been looked up.";
$l['access_audit_lookup_task_disabled'] = "None: the Access Audit task is disabled under Tools & Maintenance -> Task Manager.";
$l['access_audit_lookup_found_title'] = "Legion IDs saved";
$l['access_audit_lookup_none_found'] = "No Legion IDs have been found by email address yet.";
$l['access_audit_lookup_now'] = "Look Up a Batch Now";
$l['access_audit_col_found_at'] = "Saved";
$l['access_audit_deleted_member'] = "Deleted member #{1}";

// A batch's result, in the task log and on the Legion IDs tab.
$l['access_audit_batch_result'] = "Looked up {1} email addresses. Legion IDs saved: {2}; no match: {3}; not answered: {4}. Still to look up: {5}.";
$l['access_audit_batch_limited'] = "The 501st Legion API turned lookups away for asking too often; the rest wait for a later batch.";
$l['access_audit_batch_no_room'] = "Nothing looked up: the 501st Legion API's limit has no room until the last batch is 15 minutes old. {1} still to look up.";
$l['access_audit_batch_done'] = "Nothing to look up: everybody has been looked up.";
$l['access_audit_batch_saved_kept'] = "Saved {1} Legion IDs found by earlier lookups.";

// The administrator log, looked up as admin_log_user_access_audit_<action>.
$l['admin_log_user_access_audit_fix'] = "Fixed {2} (#{1}): {3}";
$l['admin_log_user_access_audit_settings'] = "Changed the access audit settings";
$l['admin_log_user_access_audit_lookup'] = "Looked up {1} email addresses for Legion IDs, saving {2}";

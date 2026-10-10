<?php
/**
 * MyBB Access Audit Plugin - Admin CP language strings
 */

$l['access_audit'] = "Access Audit";

$l['access_audit_tab_audit'] = "Audit";
$l['access_audit_tab_audit_desc'] = "Every member's groups checked against their 501st Legion record. Only the unit members group and the 501st members group are ever changed; any other group a member is in is left alone.";
$l['access_audit_tab_settings'] = "Settings";
$l['access_audit_tab_settings_desc'] = "Which unit this board belongs to, where members' Legion IDs are kept, and which groups the audit manages.";

// Settings.
$l['access_audit_choose'] = "Choose...";
$l['access_audit_setting_api_url'] = "501st Legion API";
$l['access_audit_setting_api_url_desc'] = "Where members' records and the list of units are fetched from. Normally https://api.501st.com.";
$l['access_audit_setting_unit'] = "Unit";
$l['access_audit_setting_unit_desc'] = "This board's garrison or outpost. Active members of it belong in both the unit members group and the 501st members group.";
$l['access_audit_setting_field'] = "Legion ID profile field";
$l['access_audit_setting_field_desc'] = "The custom profile field members put their Legion ID in. \"TK-12345\", \"TK 12345\" and \"12345\" are all read as 12345.";
$l['access_audit_setting_unit_group'] = "Unit members group";
$l['access_audit_setting_unit_group_desc'] = "Active members of this unit belong in this group, and nobody else does.";
$l['access_audit_setting_legion_group'] = "501st members group";
$l['access_audit_setting_legion_group_desc'] = "Every active 501st member belongs in this group, this unit's own included, and nobody else does.";
$l['access_audit_setting_same_group'] = "The unit members group and the 501st members group must be different groups";
$l['access_audit_save_settings'] = "Save Settings";
$l['access_audit_settings_saved'] = "The settings have been saved.";
$l['access_audit_units_unavailable'] = "The list of units could not be fetched from the 501st Legion API. Check its address, or try again later.";

$l['access_audit_error_api_url'] = "Enter the 501st Legion API's address, starting with http:// or https://.";
$l['access_audit_error_unit'] = "Choose this board's unit.";
$l['access_audit_error_units_unavailable'] = "The list of units could not be fetched from the 501st Legion API, so the unit cannot be changed right now.";
$l['access_audit_error_field'] = "Choose the profile field that holds members' Legion IDs.";
$l['access_audit_error_unit_group'] = "Choose the unit members group.";
$l['access_audit_error_legion_group'] = "Choose the 501st members group.";
$l['access_audit_error_same_group'] = "The unit members group and the 501st members group must be different groups.";

// The audit.
$l['access_audit_not_configured'] = "The audit cannot run until it has been set up on the <a href=\"{1}\">Settings</a> tab. Still needed:";
$l['access_audit_not_configured_short'] = "The audit has not been set up yet.";
$l['access_audit_summary'] = "Summary";
$l['access_audit_summary_rules'] = "Active members of <strong>{1}</strong> belong in both <strong>{2}</strong> and <strong>{3}</strong>. Active members of any other unit belong in <strong>{3}</strong> only. Anybody else - no Legion ID, a Reserve or other inactive status, or no public 501st record - belongs in neither.";
$l['access_audit_count_checked'] = "Members checked";
$l['access_audit_count_ok'] = "Groups correct";
$l['access_audit_count_fix'] = "Groups need changing";
$l['access_audit_count_unchecked'] = "Could not be checked";
$l['access_audit_count_banned'] = "Banned, left alone";
$l['access_audit_count_shared'] = "Sharing a Legion ID with another member";
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
$l['access_audit_neither'] = "Neither";
$l['access_audit_primary'] = "(primary)";
$l['access_audit_record_no_legion_id'] = "No Legion ID on their profile";
$l['access_audit_record_unchecked'] = "Could not reach the 501st Legion API";
$l['access_audit_record_classified'] = "No public record - private, retired, or not a real Legion ID";
$l['access_audit_record_banned'] = "Banned - MyBB restores their groups when the ban is lifted";
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
$l['access_audit_fixed'] = "{1}'s groups have been fixed: {2}.";
$l['access_audit_nothing_to_fix'] = "{1}'s groups are already correct.";
$l['access_audit_error_no_member'] = "That member does not exist.";
$l['access_audit_error_unchecked'] = "{1} could not be checked: the 501st Legion API did not answer. Nothing was changed.";
$l['access_audit_error_changed'] = "{1}'s groups were changed by somebody else while the audit ran, so nothing was changed. Check them again.";

// The administrator log, looked up as admin_log_user_access_audit_<action>.
$l['admin_log_user_access_audit_fix'] = "Fixed the groups of {2} (#{1}): {3}";
$l['admin_log_user_access_audit_settings'] = "Changed the access audit settings";

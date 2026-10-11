<?php
/**
 * MyBB Access Audit Plugin - Admin CP language strings
 */

$l['access_audit'] = "Access Audit";

$l['access_audit_tab_audit'] = "Audit";
$l['access_audit_tab_audit_desc'] = "Every member's groups checked against their 501st Legion record. Only the unit members, reserve members and 501st members groups, and the squads' groups, are ever changed; any other group a member is in is left alone.";
$l['access_audit_tab_settings'] = "Settings";
$l['access_audit_tab_settings_desc'] = "Which unit this board belongs to, where members' Legion IDs are kept, and which squads and groups the audit manages.";

// Settings.
$l['access_audit_choose'] = "Choose...";
$l['access_audit_setting_api_url'] = "501st Legion API";
$l['access_audit_setting_api_url_desc'] = "Where members' records and the list of units are fetched from. Normally https://api.501st.com.";
$l['access_audit_setting_unit'] = "Unit";
$l['access_audit_setting_unit_desc'] = "This board's garrison or outpost. Active members of it belong in the unit members group, Reserve members in the reserve members group, and both in the 501st members group.";
$l['access_audit_setting_field'] = "Legion ID profile field";
$l['access_audit_setting_field_desc'] = "The custom profile field members put their Legion ID in. \"TK-12345\", \"TK 12345\" and \"12345\" are all read as 12345.";
$l['access_audit_setting_unit_group'] = "Unit members group";
$l['access_audit_setting_unit_group_desc'] = "Active members of this unit belong in this group, and nobody else does.";
$l['access_audit_setting_reserve_group'] = "Reserve members group";
$l['access_audit_setting_reserve_group_desc'] = "Reserve members of this unit belong in this group, and nobody else does. Another unit's Reserve members belong in the 501st members group only.";
$l['access_audit_setting_legion_group'] = "501st members group";
$l['access_audit_setting_legion_group_desc'] = "Every Active or Reserve 501st member belongs in this group, this unit's own included, and nobody else does.";
$l['access_audit_setting_same_group'] = "The unit members, reserve members and 501st members groups must be three different groups";
$l['access_audit_save_settings'] = "Save Settings";
$l['access_audit_settings_saved'] = "The settings have been saved.";
$l['access_audit_squads'] = "Squads";
$l['access_audit_squads_desc'] = "Active members of each squad chosen here belong in its group as well, and nobody else does - on top of the groups above. Reserve members do not. The squads offered are the unit's own, as saved above. Choose a squad and its group in an empty row to add one, and set a squad back to Choose... to stop auditing it.";
$l['access_audit_squads_no_unit'] = "Choose this board's unit and save to be offered its squads.";
$l['access_audit_squads_none'] = "The 501st's records show no squads in this unit.";
$l['access_audit_col_squad'] = "Squad";
$l['access_audit_col_squad_group'] = "Its group";
$l['access_audit_squads_unavailable'] = "The list of squads could not be fetched from the 501st Legion API. The squads already chosen can still be kept, changed or taken off.";
$l['access_audit_setting_squads'] = "Squads - one of their groups no longer exists, or the setting cannot be read";
$l['access_audit_setting_squad_same_group'] = "Each squad's group must be a group of its own, not another squad's or one of the other three";
$l['access_audit_units_unavailable'] = "The list of units could not be fetched from the 501st Legion API. Check its address, or try again later.";

$l['access_audit_error_api_url'] = "Enter the 501st Legion API's address, starting with http:// or https://.";
$l['access_audit_error_unit'] = "Choose this board's unit.";
$l['access_audit_error_units_unavailable'] = "The list of units could not be fetched from the 501st Legion API, so the unit cannot be changed right now.";
$l['access_audit_error_field'] = "Choose the profile field that holds members' Legion IDs.";
$l['access_audit_error_unit_group'] = "Choose the unit members group.";
$l['access_audit_error_reserve_group'] = "Choose the reserve members group.";
$l['access_audit_error_legion_group'] = "Choose the 501st members group.";
$l['access_audit_error_same_group'] = "The unit members, reserve members and 501st members groups must be three different groups.";
$l['access_audit_error_squad_incomplete'] = "Choose both a squad and its group, or neither.";
$l['access_audit_error_squad'] = "Choose squads of this board's unit. If the unit has just been changed, take its old squads off first.";
$l['access_audit_error_squads_unavailable'] = "The list of squads could not be fetched from the 501st Legion API, so squads cannot be added right now.";
$l['access_audit_error_squad_group'] = "Choose a group for each squad.";
$l['access_audit_error_same_squad'] = "Each squad can only be chosen once.";
$l['access_audit_error_squad_same_group'] = "Each squad needs a group of its own, not another squad's or one of the unit members, reserve members and 501st members groups.";

// The audit.
$l['access_audit_not_configured'] = "The audit cannot run until it has been set up on the <a href=\"{1}\">Settings</a> tab. Still needed:";
$l['access_audit_not_configured_short'] = "The audit has not been set up yet.";
$l['access_audit_summary'] = "Summary";
$l['access_audit_summary_rules'] = "Active members of <strong>{1}</strong> belong in both <strong>{2}</strong> and <strong>{3}</strong>, and its Reserve members in both <strong>{4}</strong> and <strong>{3}</strong>. Active and Reserve members of any other unit belong in <strong>{3}</strong> only. Anybody else - no Legion ID, any other status, or no public 501st record - belongs in none of them.";
$l['access_audit_summary_squad'] = "Active members of <strong>{1}</strong> belong in <strong>{2}</strong> as well.";
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
$l['access_audit_in_none'] = "None of them";
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

<?php
/**
 * MyBB Access Audit Plugin - Admin CP: Users & Groups -> Access Audit
 *
 * The audit: every member, what the 501st's records say about them, and what has to
 * change for their groups to match, fixed one member at a time. And the Settings
 * tab, which says which unit and which groups the audit is about.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_functions.php";

/** @var DefaultPage $page */

// The page changes members' groups, which is managing users: an administrator who may not
// do that on Users & Groups -> Users may not do it here either.
check_admin_permissions(array('module' => 'user', 'action' => 'users'));

$page->add_breadcrumb_item($lang->access_audit, "index.php?module=user-access_audit");

// The Admin CP is served from its own directory, so the path climbs out of it.
$page->extra_header .= "<link rel=\"stylesheet\" href=\"../jscripts/access_audit/access-audit-admin.css\" type=\"text/css\" />\n";

$action = $mybb->get_input('action');
$base_url = "index.php?module=user-access_audit";
$show_all = $mybb->get_input('show') === 'all';
// The audit's address with its filter kept, for redirects and for the page's own links.
$audit_url = $base_url . ($show_all ? "&show=all" : "");
$audit_link = htmlspecialchars_uni($audit_url);

$sub_tabs = array(
    'audit' => array(
        'title'       => $lang->access_audit_tab_audit,
        'link'        => $base_url,
        'description' => $lang->access_audit_tab_audit_desc
    ),
    'settings' => array(
        'title'       => $lang->access_audit_tab_settings,
        'link'        => "{$base_url}&amp;action=settings",
        'description' => $lang->access_audit_tab_settings_desc
    ),
);

/**
 * Turn away a form posted without the post key, back to the audit. In the Admin CP
 * verify_post_check() returns false rather than stopping the page.
 */
function access_audit_admin_require_post()
{
    global $mybb, $lang, $audit_url;

    if($mybb->request_method !== "post" || !verify_post_check($mybb->get_input('my_post_key')))
    {
        flash_message($lang->invalid_post_verify_key2, 'error');
        admin_redirect($audit_url);
    }
}

/**
 * Send the administrator to the Settings tab when the audit cannot run yet.
 *
 * @param array $config As access_audit_config()
 */
function access_audit_admin_require_config(array $config)
{
    global $lang, $base_url;

    if($config['missing'])
    {
        flash_message($lang->access_audit_not_configured_short, 'error');
        admin_redirect("{$base_url}&action=settings");
    }
}

// ---------------------------------------------------------------------------
// Fix one member
// ---------------------------------------------------------------------------

if($action == "fix")
{
    access_audit_admin_require_post();

    $config = access_audit_config();
    access_audit_admin_require_config($config);

    $members = access_audit_members($config, $mybb->get_input('uid', MyBB::INPUT_INT));
    if(!$members)
    {
        flash_message($lang->access_audit_error_no_member, 'error');
        admin_redirect($audit_url);
    }

    // Worked out again rather than taken from the page: what is applied is always what
    // the rules say now, never something a posted form says.
    $results = access_audit_evaluate($config, $members);
    $result = $results[0];
    $member = $result['member'];
    $username = htmlspecialchars_uni($member['username']);

    if($result['verdict'] === ACCESS_AUDIT_UNCHECKED)
    {
        flash_message($lang->sprintf($lang->access_audit_error_unchecked, $username), 'error');
        admin_redirect($audit_url);
    }

    if(!$result['plan'])
    {
        flash_message($lang->sprintf($lang->access_audit_nothing_to_fix, $username), 'success');
        admin_redirect($audit_url);
    }

    if(!access_audit_apply($member, $result['plan']))
    {
        flash_message($lang->sprintf($lang->access_audit_error_changed, $username), 'error');
        admin_redirect($audit_url);
    }

    $cache->update_moderators();

    log_admin_action((int)$member['uid'], $member['username'], access_audit_describe_plan($result['plan'], false));

    flash_message($lang->sprintf($lang->access_audit_fixed, $username, access_audit_describe_plan($result['plan'])), 'success');
    admin_redirect($audit_url);
}

// ---------------------------------------------------------------------------
// Check again
// ---------------------------------------------------------------------------

if($action == "refresh")
{
    access_audit_admin_require_post();

    access_audit_forget_lookups();

    admin_redirect($audit_url);
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

if($action == "settings")
{
    $errors = array();

    $values = array(
        'api_url'       => access_audit_setting('api_url'),
        'unit'          => (int)access_audit_setting('unit'),
        'field'         => (int)access_audit_setting('legion_id_field'),
        'unit_group'    => (int)access_audit_setting('unit_group'),
        'reserve_group' => (int)access_audit_setting('reserve_group'),
        'legion_group'  => (int)access_audit_setting('legion_group'),
    );

    if($mybb->request_method == "post")
    {
        $values = array(
            'api_url'       => trim($mybb->get_input('api_url')),
            'unit'          => $mybb->get_input('unit', MyBB::INPUT_INT),
            'field'         => $mybb->get_input('legion_id_field', MyBB::INPUT_INT),
            'unit_group'    => $mybb->get_input('unit_group', MyBB::INPUT_INT),
            'reserve_group' => $mybb->get_input('reserve_group', MyBB::INPUT_INT),
            'legion_group'  => $mybb->get_input('legion_group', MyBB::INPUT_INT),
        );
    }

    // From the address being saved, so changing it and choosing a unit work in one go.
    $units = access_audit_valid_api_url($values['api_url']) ? access_audit_api_units($values['api_url']) : null;

    $fields = array();
    $query = $db->simple_select("profilefields", "fid, name", "", array("order_by" => "disporder, name", "order_dir" => "ASC"));
    while($field = $db->fetch_array($query))
    {
        $fields[(int)$field['fid']] = $field['name'];
    }

    $groups = array();
    $query = $db->simple_select("usergroups", "gid, title", "", array("order_by" => "title", "order_dir" => "ASC"));
    while($group = $db->fetch_array($query))
    {
        $groups[(int)$group['gid']] = $group['title'];
    }

    if($mybb->request_method == "post")
    {
        if(!verify_post_check($mybb->get_input('my_post_key')))
        {
            $errors[] = $lang->invalid_post_verify_key2;
        }

        if(!access_audit_valid_api_url($values['api_url']))
        {
            $errors[] = $lang->access_audit_error_api_url;
        }

        $unit_name = '';
        if($values['unit'] <= 0)
        {
            $errors[] = $lang->access_audit_error_unit;
        }
        elseif($units !== null)
        {
            if(!isset($units[$values['unit']]))
            {
                $errors[] = $lang->access_audit_error_unit;
            }
            else
            {
                $unit_name = $units[$values['unit']]['name'];
            }
        }
        elseif($values['unit'] === (int)access_audit_setting('unit'))
        {
            // The API is down, and the unit is the one already saved: keep its name.
            $unit_name = access_audit_setting('unit_name');
        }
        else
        {
            $errors[] = $lang->access_audit_error_units_unavailable;
        }

        if(!isset($fields[$values['field']]))
        {
            $errors[] = $lang->access_audit_error_field;
        }

        if(!isset($groups[$values['unit_group']]))
        {
            $errors[] = $lang->access_audit_error_unit_group;
        }

        if(!isset($groups[$values['reserve_group']]))
        {
            $errors[] = $lang->access_audit_error_reserve_group;
        }

        if(!isset($groups[$values['legion_group']]))
        {
            $errors[] = $lang->access_audit_error_legion_group;
        }

        if(!access_audit_groups_distinct(array($values['unit_group'], $values['reserve_group'], $values['legion_group'])))
        {
            $errors[] = $lang->access_audit_error_same_group;
        }

        if(!$errors)
        {
            $previous_api = access_audit_setting('api_url');

            $settings = array(
                'access_audit_api_url'         => $values['api_url'],
                'access_audit_unit'            => (string)$values['unit'],
                'access_audit_unit_name'       => $unit_name,
                'access_audit_legion_id_field' => (string)$values['field'],
                'access_audit_unit_group'      => (string)$values['unit_group'],
                'access_audit_reserve_group'   => (string)$values['reserve_group'],
                'access_audit_legion_group'    => (string)$values['legion_group'],
            );
            foreach($settings as $name => $value)
            {
                $db->update_query("settings", array('value' => $db->escape_string($value)), "name = '" . $db->escape_string($name) . "'");
            }
            rebuild_settings();

            // Another API's answers are not this one's.
            if(access_audit_api_base($previous_api) !== access_audit_api_base($values['api_url']))
            {
                access_audit_forget_lookups();
            }

            log_admin_action();

            flash_message($lang->access_audit_settings_saved, 'success');
            admin_redirect("{$base_url}&action=settings");
        }
    }

    $page->add_breadcrumb_item($lang->access_audit_tab_settings);
    $page->output_header($lang->access_audit . " - " . $lang->access_audit_tab_settings);
    $page->output_nav_tabs($sub_tabs, 'settings');

    if($errors)
    {
        $page->output_inline_error($errors);
    }

    $unit_options = array(0 => $lang->access_audit_choose);
    if($units !== null)
    {
        foreach($units as $id => $unit)
        {
            $unit_options[$id] = $unit['type'] !== '' && $unit['type'] !== 'Garrison'
                ? $unit['name'] . ' (' . $unit['type'] . ')'
                : $unit['name'];
        }
    }
    elseif((int)access_audit_setting('unit') > 0)
    {
        // Without the list there is still the unit already saved, so the rest of the form
        // can be saved while the API is down.
        $saved = access_audit_setting('unit_name');
        $unit_options[(int)access_audit_setting('unit')] = $saved !== '' ? $saved : '#' . (int)access_audit_setting('unit');
    }

    $field_options = array(0 => $lang->access_audit_choose) + $fields;
    $group_options = array(0 => $lang->access_audit_choose) + $groups;

    $form = new Form("{$base_url}&amp;action=settings", "post", "access_audit_settings");
    $container = new FormContainer($lang->access_audit_tab_settings);

    $container->output_row(
        $lang->access_audit_setting_api_url . " <em>*</em>",
        $lang->access_audit_setting_api_url_desc,
        $form->generate_text_box('api_url', $values['api_url'], array('id' => 'api_url')),
        'api_url'
    );

    $unit_note = $units === null
        ? "<div class=\"access-audit-warning\" id=\"access_audit_units_unavailable\">{$lang->access_audit_units_unavailable}</div>"
        : '';
    $container->output_row(
        $lang->access_audit_setting_unit . " <em>*</em>",
        $lang->access_audit_setting_unit_desc,
        $unit_note . $form->generate_select_box('unit', $unit_options, $values['unit'], array('id' => 'unit')),
        'unit'
    );

    $container->output_row(
        $lang->access_audit_setting_field . " <em>*</em>",
        $lang->access_audit_setting_field_desc,
        $form->generate_select_box('legion_id_field', $field_options, $values['field'], array('id' => 'legion_id_field')),
        'legion_id_field'
    );

    $container->output_row(
        $lang->access_audit_setting_unit_group . " <em>*</em>",
        $lang->access_audit_setting_unit_group_desc,
        $form->generate_select_box('unit_group', $group_options, $values['unit_group'], array('id' => 'unit_group')),
        'unit_group'
    );

    $container->output_row(
        $lang->access_audit_setting_reserve_group . " <em>*</em>",
        $lang->access_audit_setting_reserve_group_desc,
        $form->generate_select_box('reserve_group', $group_options, $values['reserve_group'], array('id' => 'reserve_group')),
        'reserve_group'
    );

    $container->output_row(
        $lang->access_audit_setting_legion_group . " <em>*</em>",
        $lang->access_audit_setting_legion_group_desc,
        $form->generate_select_box('legion_group', $group_options, $values['legion_group'], array('id' => 'legion_group')),
        'legion_group'
    );

    $container->end();

    $form->output_submit_wrapper(array($form->generate_submit_button($lang->access_audit_save_settings, array('id' => 'access_audit_save_settings'))));
    $form->end();

    $page->output_footer();
}

// ---------------------------------------------------------------------------
// The audit
// ---------------------------------------------------------------------------

if(!$action)
{
    $page->output_header($lang->access_audit);
    $page->output_nav_tabs($sub_tabs, 'audit');

    $config = access_audit_config();

    if($config['missing'])
    {
        $missing = array();
        foreach($config['missing'] as $key)
        {
            $missing[] = $lang->$key;
        }

        echo "<div class=\"access-audit-warning\" id=\"access_audit_not_configured\">"
            . $lang->sprintf($lang->access_audit_not_configured, "{$base_url}&amp;action=settings")
            . "<ul><li>" . implode("</li><li>", $missing) . "</li></ul></div>";

        $page->output_footer();
        exit;
    }

    $results = access_audit_evaluate($config, access_audit_members($config));

    $counts = array('checked' => count($results), 'ok' => 0, 'fix' => 0, 'unchecked' => 0, 'banned' => 0, 'shared' => 0);
    foreach($results as $result)
    {
        // Counted on top of whatever else is said about the member.
        if($result['shared_with'])
        {
            $counts['shared']++;
        }

        if($result['verdict'] === ACCESS_AUDIT_BANNED)
        {
            $counts['banned']++;
        }
        elseif($result['verdict'] === ACCESS_AUDIT_UNCHECKED)
        {
            $counts['unchecked']++;
        }
        elseif($result['plan'])
        {
            $counts['fix']++;
        }
        else
        {
            $counts['ok']++;
        }
    }

    $unit_name = $config['unit_name'] !== '' ? htmlspecialchars_uni($config['unit_name']) : '#' . (int)$config['unit'];
    $oldest = access_audit_oldest_lookup();

    $summary = new Table;
    $summary->construct_cell($lang->sprintf($lang->access_audit_summary_rules,
        $unit_name,
        access_audit_group_name($config['unit_group']),
        access_audit_group_name($config['legion_group']),
        access_audit_group_name($config['reserve_group'])), array('colspan' => 2));
    $summary->construct_row();
    foreach(array('checked', 'ok', 'fix', 'unchecked', 'banned', 'shared') as $key)
    {
        $label = "access_audit_count_{$key}";
        $summary->construct_cell($lang->$label, array('width' => '30%'));
        $summary->construct_cell(my_number_format($counts[$key]), array('id' => "access_audit_count_{$key}"));
        $summary->construct_row();
    }
    if($oldest !== null)
    {
        $summary->construct_cell($lang->access_audit_checked_at);
        $summary->construct_cell(my_date('relative', $oldest), array('id' => 'access_audit_checked_at'));
        $summary->construct_row();
    }
    $summary->output($lang->access_audit_summary);

    // One form for the whole table: each row's Fix button posts it with that member's id,
    // and Check Again to its own action, so the post key is on the page once. There is
    // deliberately no way to fix everybody at once: each change takes access away from or
    // gives it to a real person, so each is looked at - the member's 501st record is linked
    // from the row for that - and made on its own.
    $form = new Form("{$audit_link}&amp;action=fix", "post", "access_audit_form");

    $table = new Table;
    $table->construct_header($lang->access_audit_col_member);
    $table->construct_header($lang->access_audit_col_posts, array('class' => 'align_center', 'width' => '1'));
    $table->construct_header($lang->access_audit_col_legion_id);
    $table->construct_header($lang->access_audit_col_record);
    $table->construct_header($lang->access_audit_col_groups);
    $table->construct_header($lang->access_audit_col_change);
    $table->construct_header($lang->access_audit_col_action, array('class' => 'align_center', 'width' => '1'));

    foreach($results as $result)
    {
        $needs_attention = $result['plan'] || $result['verdict'] === ACCESS_AUDIT_UNCHECKED || $result['shared_with'];
        if(!$show_all && !$needs_attention)
        {
            continue;
        }

        $member = $result['member'];
        $uid = (int)$member['uid'];
        $record = $result['record'];

        $member_html = "<a href=\"index.php?module=user-users&amp;action=edit&amp;uid={$uid}\">"
            . htmlspecialchars_uni($member['username']) . "</a>";
        if($result['shared_with'])
        {
            $others = array();
            foreach($result['shared_with'] as $other)
            {
                $others[] = "<a href=\"index.php?module=user-users&amp;action=edit&amp;uid=" . (int)$other['uid'] . "\">"
                    . htmlspecialchars_uni($other['username']) . "</a>";
            }
            $member_html .= "<div class=\"access-audit-shared smalltext\">"
                . $lang->sprintf($lang->access_audit_shared_with, implode(', ', $others)) . "</div>";
        }
        $table->construct_cell($member_html, array('class' => 'access-audit-member'));

        // Linked to a search of their posts on the board, so whether an account is really
        // used - and by whom - can be seen before acting on it. A search for nobody's posts
        // only says it found none, so a member without any has nothing to link.
        $postnum = (int)$member['postnum'];
        if($postnum > 0)
        {
            $posts_html = "<a href=\"" . htmlspecialchars_uni($mybb->settings['bburl']) . "/search.php?action=finduser&amp;uid={$uid}\" target=\"_blank\" rel=\"noopener\">"
                . my_number_format($postnum) . "</a>";
        }
        else
        {
            $posts_html = "<span class=\"access-audit-muted\">0</span>";
        }
        $table->construct_cell($posts_html, array('class' => 'access-audit-posts align_center'));

        // As the member typed it, linked to their record on 501st.com by the number it was
        // read as, so whoever acts on the row can look at the record first - and a mistyped
        // ID is one click from showing whose it really is. Nothing to link without a number.
        if($member['legion_id'] === '')
        {
            $legion_id_html = "<span class=\"access-audit-muted\">{$lang->access_audit_none}</span>";
        }
        elseif($result['legion_number'] > 0)
        {
            $legion_id_html = "<a href=\"https://501st.com/member/" . (int)$result['legion_number'] . "\" target=\"_blank\" rel=\"noopener noreferrer\">"
                . htmlspecialchars_uni($member['legion_id']) . "</a>";
        }
        else
        {
            $legion_id_html = htmlspecialchars_uni($member['legion_id']);
        }
        $table->construct_cell($legion_id_html, array('class' => 'access-audit-legion-id'));

        // What the 501st says. The record itself is a click away on the Legion ID.
        switch($result['verdict'])
        {
            case ACCESS_AUDIT_BANNED:
                $record_html = $lang->access_audit_record_banned;
                break;
            case ACCESS_AUDIT_NO_LEGION_ID:
                $record_html = $lang->access_audit_record_no_legion_id;
                break;
            case ACCESS_AUDIT_UNCHECKED:
                $record_html = "<span class=\"access-audit-error\">{$lang->access_audit_record_unchecked}</span>";
                break;
            default:
                if($record['status'] === ACCESS_AUDIT_CLASSIFIED)
                {
                    $record_html = $lang->access_audit_record_classified;
                }
                else
                {
                    $record_html = "<strong class=\"access-audit-status\">" . htmlspecialchars_uni($record['status']) . "</strong>";
                    if($record['garrison_name'] !== '')
                    {
                        $record_html .= " - <span class=\"access-audit-unit\">" . htmlspecialchars_uni($record['garrison_name']) . "</span>";
                    }
                    if($record['name'] !== '')
                    {
                        $record_html .= "<br /><span class=\"smalltext\">" . htmlspecialchars_uni($record['name']) . "</span>";
                    }
                }
        }
        $table->construct_cell($record_html, array('class' => 'access-audit-record'));

        $in = array();
        $additional = access_audit_group_list($member['additionalgroups']);
        foreach(access_audit_audited_groups($config) as $gid)
        {
            if((int)$member['usergroup'] === $gid)
            {
                $in[] = access_audit_group_name($gid) . " <span class=\"smalltext\">{$lang->access_audit_primary}</span>";
            }
            elseif(in_array($gid, $additional, true))
            {
                $in[] = access_audit_group_name($gid);
            }
        }
        $table->construct_cell($in ? implode('<br />', $in) : "<span class=\"access-audit-muted\">{$lang->access_audit_in_none}</span>", array('class' => 'access-audit-groups'));

        if($result['plan'])
        {
            $change = access_audit_describe_plan($result['plan']);
            $button = "<button type=\"submit\" class=\"button access-audit-fix\" name=\"uid\" value=\"{$uid}\" formaction=\"{$audit_link}&amp;action=fix\">{$lang->access_audit_fix}</button>";
        }
        elseif($result['verdict'] === ACCESS_AUDIT_UNCHECKED)
        {
            $change = "<span class=\"access-audit-muted\">{$lang->access_audit_change_unknown}</span>";
            $button = '';
        }
        else
        {
            $change = "<span class=\"access-audit-muted\">{$lang->access_audit_change_none}</span>";
            $button = '';
        }
        $table->construct_cell($change, array('class' => 'access-audit-change'));
        $table->construct_cell($button, array('class' => 'align_center'));

        $classes = array('access-audit-row', 'access-audit-' . str_replace('_', '-', $result['verdict']));
        if($result['plan'])
        {
            $classes[] = 'access-audit-needs-fix';
        }
        if($result['shared_with'])
        {
            $classes[] = 'access-audit-shared-id';
        }
        $table->construct_row(array('id' => "access_audit_row_{$uid}", 'class' => implode(' ', $classes)));
    }

    if($table->num_rows() == 0)
    {
        $table->construct_cell($show_all ? $lang->access_audit_no_members : $lang->access_audit_all_ok, array('colspan' => 7, 'id' => 'access_audit_empty'));
        $table->construct_row();
    }

    $toggle = $show_all
        ? "<a href=\"{$base_url}\" id=\"access_audit_toggle\">{$lang->access_audit_show_problems}</a>"
        : "<a href=\"{$base_url}&amp;show=all\" id=\"access_audit_toggle\">{$lang->access_audit_show_all}</a>";
    $table->output($lang->access_audit_members . " <span class=\"float_right smalltext\">{$toggle}</span>");

    $buttons = array();
    $buttons[] = "<button type=\"submit\" class=\"button\" id=\"access_audit_refresh\" formaction=\"{$audit_link}&amp;action=refresh\">{$lang->access_audit_refresh}</button>";
    $form->output_submit_wrapper($buttons);
    $form->end();

    $page->output_footer();
}

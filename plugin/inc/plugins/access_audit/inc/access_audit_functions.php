<?php
/**
 * MyBB Access Audit Plugin - The audit
 *
 * Every member is judged by the Legion ID on their profile:
 *
 * - no Legion ID: in none of the three groups;
 * - a 501st record that is neither Active nor Reserve (a classified record, anything
 *   else): in none;
 * - Active or Reserve, in another unit: in the 501st members group only;
 * - Active, in this unit: in the unit members group and the 501st members group - they are
 *   501st members as much as anybody;
 * - Reserve, in this unit: in the reserve members group and the 501st members group.
 *
 * On top of that, an Active member of one of the squads chosen in the settings is in that
 * squad's group, and nobody else is.
 *
 * Only those groups are ever changed. Every other group a member is in - command staff,
 * moderators, administrators - is left exactly as it is.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_api.php";

define('ACCESS_AUDIT_DEFAULT_API_URL', 'https://api.501st.com');

/**
 * What the API calls a record it will not show.
 */
define('ACCESS_AUDIT_CLASSIFIED', 'Classified Record');

/**
 * The two statuses that make somebody a 501st member as far as the audit is concerned.
 * Reserve members are in good standing but not active, so this unit's own have a group of
 * their own rather than the unit members group.
 */
define('ACCESS_AUDIT_ACTIVE', 'Active');
define('ACCESS_AUDIT_RESERVE', 'Reserve');

/**
 * The group a member is moved to when the group being taken away from them is their
 * primary group and they belong in none: MyBB's Registered group.
 */
define('ACCESS_AUDIT_REGISTERED_GROUP', 2);

/**
 * Verdicts, one per member audited.
 */
define('ACCESS_AUDIT_NO_LEGION_ID', 'no_legion_id');
define('ACCESS_AUDIT_UNCHECKED', 'unchecked');
define('ACCESS_AUDIT_INACTIVE', 'inactive');
define('ACCESS_AUDIT_OTHER_UNIT', 'other_unit');
define('ACCESS_AUDIT_OUR_UNIT', 'our_unit');
define('ACCESS_AUDIT_OTHER_UNIT_RESERVE', 'other_unit_reserve');
define('ACCESS_AUDIT_OUR_UNIT_RESERVE', 'our_unit_reserve');
define('ACCESS_AUDIT_BANNED', 'banned');

/**
 * Rebuild MyBB's settings, and see that the next request reads the new ones.
 *
 * rebuild_settings() rewrites inc/settings.php, which every request loads with require.
 * Where OPcache is on, PHP goes on running its cached copy of the file until it next
 * checks the timestamp - two seconds by default, and never on hosts that turn the check
 * off - so the page a save redirects to would show the settings as they were.
 */
function access_audit_rebuild_settings()
{
    rebuild_settings();

    if(function_exists('opcache_invalidate'))
    {
        // Hosts can disable the function or restrict OPcache to some paths, and either way
        // the settings are saved: a stale page is all that is left.
        @opcache_invalidate(MYBB_ROOT . "inc/settings.php", true);
    }
}

/**
 * One of the plugin's settings.
 *
 * @param string $name Without the access_audit_ prefix
 * @return string
 */
function access_audit_setting($name)
{
    global $mybb;

    $key = 'access_audit_' . $name;

    return isset($mybb->settings[$key]) ? (string)$mybb->settings[$key] : '';
}

/**
 * The digits of a Legion ID as members type it: "TK-12345", "TK 12345" or "12345".
 *
 * @param string $legion_id
 * @return int 0 when there is no number in it
 */
function access_audit_legion_number($legion_id)
{
    $digits = ltrim(preg_replace('/\D/', '', (string)$legion_id), '0');

    // Nine digits is far past any Legion ID, and keeps the number inside an int column.
    return $digits !== '' && strlen($digits) <= 9 ? (int)$digits : 0;
}

/**
 * The squads setting: a JSON list of {"squad": id, "name": name, "group": gid}, in the
 * order they were chosen. The name is the squad's as the 501st's records had it when it
 * was chosen, kept so the audit can name it while the API is down.
 *
 * @param string $value
 * @return array|null Of array('squad' => int, 'name' => string, 'group' => int); null when
 *                    the value is not such a list, or names a squad twice
 */
function access_audit_parse_squads($value)
{
    $value = trim((string)$value);
    if($value === '')
    {
        return array();
    }

    $data = json_decode($value, true);
    if(!is_array($data))
    {
        return null;
    }

    $squads = array();
    foreach($data as $entry)
    {
        if(!is_array($entry) || !isset($entry['squad'], $entry['group']) || !is_numeric($entry['squad']) || !is_numeric($entry['group']))
        {
            return null;
        }

        $squad = (int)$entry['squad'];
        if($squad <= 0 || (int)$entry['group'] <= 0 || isset($squads[$squad]))
        {
            return null;
        }

        $squads[$squad] = array(
            'squad' => $squad,
            'name'  => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '',
            'group' => (int)$entry['group'],
        );
    }

    return array_values($squads);
}

/**
 * The squads setting's value for a list of squads.
 *
 * @param array $squads As access_audit_parse_squads()
 * @return string
 */
function access_audit_encode_squads(array $squads)
{
    if(!$squads)
    {
        return '';
    }

    $entries = array();
    foreach($squads as $squad)
    {
        $entries[] = array('squad' => (int)$squad['squad'], 'name' => (string)$squad['name'], 'group' => (int)$squad['group']);
    }

    // Non-ASCII names stay escaped, so the value is plain ASCII whatever the board's
    // character set.
    return json_encode($entries);
}

/**
 * A squad's name, or its id when no name was kept.
 *
 * @param array $squad As access_audit_parse_squads()
 * @return string
 */
function access_audit_squad_name(array $squad)
{
    return $squad['name'] !== '' ? $squad['name'] : '#' . (int)$squad['squad'];
}

/**
 * The audit's configuration, and what is missing from it.
 *
 * @return array 'unit', 'unit_name', 'field', 'unit_group', 'reserve_group', 'legion_group',
 *               'squads' (as access_audit_parse_squads(), empty when unusable) and 'missing'
 *               (language keys for each setting that is not usable)
 */
function access_audit_config()
{
    global $db, $cache;

    $config = array(
        'unit'          => (int)access_audit_setting('unit'),
        'unit_name'     => access_audit_setting('unit_name'),
        'field'         => (int)access_audit_setting('legion_id_field'),
        'unit_group'    => (int)access_audit_setting('unit_group'),
        'reserve_group' => (int)access_audit_setting('reserve_group'),
        'legion_group'  => (int)access_audit_setting('legion_group'),
        'squads'        => access_audit_parse_squads(access_audit_setting('squads')),
        'missing'       => array(),
    );

    if(access_audit_api_base() === '')
    {
        $config['missing'][] = 'access_audit_setting_api_url';
    }

    if($config['unit'] <= 0)
    {
        $config['missing'][] = 'access_audit_setting_unit';
    }

    // A field or group deleted since it was chosen is as good as none.
    if($config['field'] <= 0 || !$db->fetch_field($db->simple_select("profilefields", "fid", "fid = " . $config['field']), "fid"))
    {
        $config['missing'][] = 'access_audit_setting_field';
    }

    $groups = (array)$cache->read('usergroups');
    $labels = array(
        'unit_group'    => 'access_audit_setting_unit_group',
        'reserve_group' => 'access_audit_setting_reserve_group',
        'legion_group'  => 'access_audit_setting_legion_group',
    );
    foreach($labels as $key => $label)
    {
        if($config[$key] <= 0 || !isset($groups[$config[$key]]))
        {
            $config['missing'][] = $label;
        }
    }

    $core_groups = array($config['unit_group'], $config['reserve_group'], $config['legion_group']);
    if(!access_audit_groups_distinct($core_groups))
    {
        $config['missing'][] = 'access_audit_setting_same_group';
    }

    // Squads are optional, but a list that cannot be read, or a squad whose group has been
    // deleted, would quietly stop that squad being audited - so the audit says so instead.
    $squads_usable = $config['squads'] !== null;
    foreach((array)$config['squads'] as $squad)
    {
        if(!isset($groups[$squad['group']]))
        {
            $squads_usable = false;
        }
    }
    if(!$squads_usable)
    {
        $config['missing'][] = 'access_audit_setting_squads';
        $config['squads'] = array();
    }
    elseif(access_audit_groups_distinct($core_groups) && !access_audit_groups_distinct(access_audit_audited_groups($config)))
    {
        $config['missing'][] = 'access_audit_setting_squad_same_group';
    }

    return $config;
}

/**
 * Whether none of the audited groups chosen so far is chosen twice. A group being two of
 * them at once would have the audit both adding a member to it and taking them out.
 *
 * @param array $gids Of int; 0 for one not chosen yet, which does not count
 * @return bool
 */
function access_audit_groups_distinct(array $gids)
{
    $chosen = array_filter(array_map('intval', $gids));

    return count($chosen) === count(array_unique($chosen));
}

/**
 * The groups the audit manages, in the order their changes are described: the unit's own
 * two, then the 501st members group, then each squad's.
 *
 * @param array $config As access_audit_config()
 * @return array Of int
 */
function access_audit_audited_groups(array $config)
{
    $gids = array((int)$config['unit_group'], (int)$config['reserve_group'], (int)$config['legion_group']);
    foreach($config['squads'] as $squad)
    {
        $gids[] = (int)$squad['group'];
    }

    return $gids;
}

/**
 * The group an Active member with this record belongs in for their squad, if any.
 *
 * @param array $config As access_audit_config()
 * @param array $record As access_audit_api_parse_member()
 * @return int 0 when their squad is not one the audit manages, or they are in none
 */
function access_audit_squad_group(array $config, array $record)
{
    foreach($config['squads'] as $squad)
    {
        if((int)$record['squad_id'] > 0 && (int)$record['squad_id'] === (int)$squad['squad'])
        {
            return (int)$squad['group'];
        }
    }

    return 0;
}

/**
 * The members to audit, with their groups, post count and the Legion ID on their
 * profile, in username order.
 *
 * @param array $config As access_audit_config()
 * @param int $uid Just this member; 0 for everybody
 * @return array
 */
function access_audit_members(array $config, $uid = 0)
{
    global $db;

    $field = "fid" . (int)$config['field'];
    $where = $uid > 0 ? "WHERE u.uid = " . (int)$uid : "";

    $members = array();
    $query = $db->query("
        SELECT u.uid, u.username, u.usergroup, u.additionalgroups, u.displaygroup, u.postnum, f.{$field} AS legion_id
        FROM " . TABLE_PREFIX . "users u
        LEFT JOIN " . TABLE_PREFIX . "userfields f ON (f.ufid = u.uid)
        {$where}
        ORDER BY u.username ASC, u.uid ASC
    ");
    while($member = $db->fetch_array($query))
    {
        $member['legion_id'] = trim((string)$member['legion_id']);
        $members[] = $member;
    }

    return $members;
}

/**
 * A member's additional groups as a list of ids.
 *
 * @param string $additionalgroups
 * @return array Of int
 */
function access_audit_group_list($additionalgroups)
{
    return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$additionalgroups)))));
}

/**
 * Audit members.
 *
 * @param array $config As access_audit_config(), with nothing missing
 * @param array $members As access_audit_members()
 * @return array One entry per member, in access_audit_compare_results() order: 'member',
 *               'legion_number', 'record' (the 501st's, or null), 'verdict', 'plan' (as
 *               access_audit_plan(), or null when nothing should change or nothing can be
 *               said), and 'shared_with' (the other members among $members on the same
 *               Legion ID, each 'uid' and 'username')
 */
function access_audit_evaluate(array $config, array $members)
{
    global $cache;

    $groups = (array)$cache->read('usergroups');

    $numbers = array();
    foreach($members as $member)
    {
        $number = access_audit_legion_number($member['legion_id']);
        if($number > 0 && !access_audit_is_banned($member, $groups))
        {
            $numbers[] = $number;
        }
    }

    $records = access_audit_lookup_members($numbers);

    $results = array();
    foreach($members as $member)
    {
        $number = access_audit_legion_number($member['legion_id']);
        $record = $number > 0 && isset($records[$number]) ? $records[$number] : null;

        // MyBB puts a banned member's groups back as they were when the ban is lifted, so
        // anything changed now would be undone then. And a banned member is kept off the
        // board by their banned group, not by these two.
        if(access_audit_is_banned($member, $groups))
        {
            $verdict = ACCESS_AUDIT_BANNED;
            $wanted = null;
        }
        elseif($number === 0)
        {
            $verdict = ACCESS_AUDIT_NO_LEGION_ID;
            $wanted = array();
        }
        elseif($record === null)
        {
            $verdict = ACCESS_AUDIT_UNCHECKED;
            $wanted = null;
        }
        elseif($record['status'] !== ACCESS_AUDIT_ACTIVE && $record['status'] !== ACCESS_AUDIT_RESERVE)
        {
            $verdict = ACCESS_AUDIT_INACTIVE;
            $wanted = array();
        }
        elseif((int)$record['garrison_id'] === (int)$config['unit'])
        {
            // The unit's own group first, so that it is the one that takes over as primary
            // when a member moves between active and reserve (see access_audit_plan()).
            if($record['status'] === ACCESS_AUDIT_RESERVE)
            {
                $verdict = ACCESS_AUDIT_OUR_UNIT_RESERVE;
                $wanted = array((int)$config['reserve_group'], (int)$config['legion_group']);
            }
            else
            {
                $verdict = ACCESS_AUDIT_OUR_UNIT;
                $wanted = array((int)$config['unit_group'], (int)$config['legion_group']);
            }
        }
        else
        {
            // Another unit's reserve is no business of this unit's reserve group.
            $verdict = $record['status'] === ACCESS_AUDIT_RESERVE ? ACCESS_AUDIT_OTHER_UNIT_RESERVE : ACCESS_AUDIT_OTHER_UNIT;
            $wanted = array((int)$config['legion_group']);
        }

        // A squad's group is for its Active members only, whichever unit the squad is in.
        // It goes last, so that it is never the one made primary in place of another.
        if($wanted && $record['status'] === ACCESS_AUDIT_ACTIVE)
        {
            $squad_group = access_audit_squad_group($config, $record);
            if($squad_group > 0)
            {
                $wanted[] = $squad_group;
            }
        }

        $plan = $wanted === null ? null : access_audit_plan($member, $wanted, $config);

        $results[] = array(
            'member'        => $member,
            'legion_number' => $number,
            'record'        => $record,
            'verdict'       => $verdict,
            'plan'          => $plan && $plan['changed'] ? $plan : null,
            'shared_with'   => array(),
        );
    }

    // Two accounts on one Legion ID is one person with two accounts, or somebody claiming
    // another member's ID to get their access - which the 501st's record cannot tell apart,
    // since it answers for the ID and not the account. Each is noted with the others, and
    // banned accounts count: an ID shared with a banned account is how a ban gets dodged.
    $holders = array();
    foreach($results as $index => $result)
    {
        if($result['legion_number'] > 0)
        {
            $holders[$result['legion_number']][] = $index;
        }
    }
    foreach($holders as $indexes)
    {
        if(count($indexes) < 2)
        {
            continue;
        }

        foreach($indexes as $index)
        {
            foreach($indexes as $other)
            {
                if($other !== $index)
                {
                    $results[$index]['shared_with'][] = array(
                        'uid'      => (int)$results[$other]['member']['uid'],
                        'username' => $results[$other]['member']['username'],
                    );
                }
            }
        }
    }

    usort($results, 'access_audit_compare_results');

    return $results;
}

/**
 * The audit's order: by Legion ID, so accounts sharing one sit together, then by username.
 * Members with no Legion ID come last.
 *
 * @param array $a
 * @param array $b
 * @return int
 */
function access_audit_compare_results(array $a, array $b)
{
    if($a['legion_number'] !== $b['legion_number'])
    {
        if($a['legion_number'] === 0 || $b['legion_number'] === 0)
        {
            return $a['legion_number'] === 0 ? 1 : -1;
        }

        return $a['legion_number'] < $b['legion_number'] ? -1 : 1;
    }

    $by_name = strnatcasecmp($a['member']['username'], $b['member']['username']);

    // usort() is not stable before PHP 8, so the uid settles a tie.
    return $by_name !== 0 ? $by_name : (int)$a['member']['uid'] - (int)$b['member']['uid'];
}

/**
 * Whether a member's primary group is a banned group.
 *
 * @param array $member
 * @param array $groups The usergroups cache
 * @return bool
 */
function access_audit_is_banned(array $member, array $groups)
{
    $gid = (int)$member['usergroup'];

    return isset($groups[$gid]) && !empty($groups[$gid]['isbannedgroup']);
}

/**
 * Work out what a member's groups should become.
 *
 * Joining a group adds it to the member's additional groups. Leaving one takes it out of
 * them - or, when it is their primary group, which a member cannot be without, swaps it
 * for the first of the groups they belong in (gone from active to reserve in this unit: the
 * reserve members group; moved from this unit to another: the 501st members group), or for
 * Registered when they belong in none. A display group that is taken away goes back to
 * showing the primary group.
 *
 * @param array $member As access_audit_members()
 * @param array $wanted The audited groups the member belongs in, the one to make primary
 *                      if need be first: none, the 501st members group, or it and one of
 *                      the unit's two - and a squad's group after those
 * @param array $config As access_audit_config()
 * @return array 'usergroup', 'additionalgroups' (comma separated), 'displaygroup', 'add'
 *               and 'remove' (group ids), 'primary_from' and 'primary_to' (group ids, equal
 *               when the primary group stays), and 'changed'
 */
function access_audit_plan(array $member, array $wanted, array $config)
{
    $audited = access_audit_audited_groups($config);
    $wanted = array_values(array_intersect(array_map('intval', $wanted), $audited));

    $primary = (int)$member['usergroup'];
    $additional = access_audit_group_list($member['additionalgroups']);
    $display = (int)$member['displaygroup'];

    $add = array();
    $remove = array();

    foreach($audited as $gid)
    {
        $in = $primary === $gid || in_array($gid, $additional, true);
        $belongs = in_array($gid, $wanted, true);

        if($belongs && !$in)
        {
            $add[] = $gid;
        }
        elseif(!$belongs && $in)
        {
            $remove[] = $gid;
        }
    }

    $new_primary = $primary;
    if(in_array($primary, $remove, true))
    {
        $new_primary = $wanted ? $wanted[0] : ACCESS_AUDIT_REGISTERED_GROUP;
    }

    $new_additional = array_merge(array_diff($additional, $remove), $add);
    // A member's primary group is never one of their additional groups too.
    $new_additional = array_values(array_unique(array_diff($new_additional, array($new_primary))));

    $new_display = in_array($display, $remove, true) ? 0 : $display;

    $old_additional = $additional;
    sort($old_additional);
    $sorted_additional = $new_additional;
    sort($sorted_additional);

    return array(
        'usergroup'        => $new_primary,
        'additionalgroups' => implode(',', $new_additional),
        'displaygroup'     => $new_display,
        'add'              => $add,
        'remove'           => $remove,
        'primary_from'     => $primary,
        'primary_to'       => $new_primary,
        'changed'          => $new_primary !== $primary || $sorted_additional !== $old_additional || $new_display !== $display,
    );
}

/**
 * Put a member's groups as a plan says.
 *
 * Guarded on the groups the plan was worked out from, so a change made to the member in
 * the meantime - by another administrator, or the member joining a group - is never
 * overwritten with a plan that no longer fits it.
 *
 * @param array $member As access_audit_members()
 * @param array $plan As access_audit_plan()
 * @return bool Whether the member was changed
 */
function access_audit_apply(array $member, array $plan)
{
    global $db;

    $db->update_query("users", array(
        'usergroup'        => (int)$plan['usergroup'],
        'additionalgroups' => $db->escape_string($plan['additionalgroups']),
        'displaygroup'     => (int)$plan['displaygroup'],
    ), "uid = " . (int)$member['uid']
        . " AND usergroup = " . (int)$member['usergroup']
        . " AND additionalgroups = '" . $db->escape_string($member['additionalgroups']) . "'"
        . " AND displaygroup = " . (int)$member['displaygroup']);

    return $db->affected_rows() > 0;
}

/**
 * A plan, in words: "Add to Garrison Members; remove from 501st Members".
 *
 * @param array $plan As access_audit_plan()
 * @param bool $html Whether to escape the group names for a page
 * @return string
 */
function access_audit_describe_plan(array $plan, $html = true)
{
    global $lang;

    $parts = array();
    foreach($plan['add'] as $gid)
    {
        $parts[] = $lang->sprintf($lang->access_audit_change_add, access_audit_group_name($gid, $html));
    }
    foreach($plan['remove'] as $gid)
    {
        $parts[] = $lang->sprintf($lang->access_audit_change_remove, access_audit_group_name($gid, $html));
    }
    if($plan['primary_to'] !== $plan['primary_from'])
    {
        $parts[] = $lang->sprintf($lang->access_audit_change_primary, access_audit_group_name($plan['primary_to'], $html));
    }

    return implode($lang->access_audit_change_separator, $parts);
}

/**
 * A group's name.
 *
 * @param int $gid
 * @param bool $html Whether to escape it for a page
 * @return string
 */
function access_audit_group_name($gid, $html = true)
{
    global $cache, $lang;

    $groups = (array)$cache->read('usergroups');
    $name = isset($groups[(int)$gid]) ? $groups[(int)$gid]['title'] : $lang->sprintf($lang->access_audit_unknown_group, (int)$gid);

    return $html ? htmlspecialchars_uni($name) : $name;
}

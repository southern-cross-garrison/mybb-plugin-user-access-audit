<?php
/**
 * MyBB Access Audit Plugin - Legion IDs by email address
 *
 * A member with no Legion ID on their profile - or none with a number in it - is looked up
 * by their email address, which the API matches against approved members' primary and
 * secondary addresses, and a Legion ID found is saved to their profile. The API answers only
 * so many of these lookups (see ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT), so they are made in
 * batches by a scheduled task (inc/tasks/access_audit.php), each spending whatever the limit
 * has room for, until everybody has been looked up - on a board of hundreds, a matter of
 * hours. The audit only reads what they found, and says nothing about a member who has yet
 * to be looked up.
 *
 * Only a field with no number in it is ever filled: a Legion ID a member has typed is never
 * replaced. Saving one changes nobody's groups - that is still the audit's, a member at a
 * time, with their 501st record on the row to check it against.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_functions.php";

/**
 * The scheduled task's file, in inc/tasks/, without .php.
 */
define('ACCESS_AUDIT_TASK_FILE', 'access_audit');

/**
 * How many of the found Legion IDs the Legion IDs tab lists, newest first.
 */
define('ACCESS_AUDIT_FOUND_SHOWN', 50);

/**
 * The members to look up: not banned, with no number in their Legion ID field, and an email
 * address - by that address, since two accounts can share one.
 *
 * @param array $config As access_audit_config()
 * @return array email => array('members' => array (as access_audit_members()),
 *               'legion_id' => int|null (the kept answer: 0 for no match, null for none),
 *               'fetched_at' => int (0 for never asked))
 */
function access_audit_lookup_candidates(array $config)
{
    global $cache;

    $groups = (array)$cache->read('usergroups');

    $candidates = array();
    foreach(access_audit_members($config) as $member)
    {
        if(access_audit_is_banned($member, $groups) || access_audit_legion_number($member['legion_id']) > 0)
        {
            continue;
        }

        $email = access_audit_normalise_email($member['email']);
        if($email === '')
        {
            continue;
        }

        if(!isset($candidates[$email]))
        {
            $candidates[$email] = array('members' => array(), 'legion_id' => null, 'fetched_at' => 0);
        }
        $candidates[$email]['members'][] = $member;
    }

    foreach(access_audit_kept_emails(array_keys($candidates)) as $email => $kept)
    {
        $candidates[$email]['legion_id'] = $kept['legion_id'];
        $candidates[$email]['fetched_at'] = $kept['fetched_at'];
    }

    return $candidates;
}

/**
 * Whether an address is due to be looked up: never asked about, or not for a while (see
 * ACCESS_AUDIT_EMAIL_RECHECK).
 *
 * @param array $candidate As access_audit_lookup_candidates()
 * @return bool
 */
function access_audit_lookup_due(array $candidate)
{
    return $candidate['fetched_at'] === 0 || $candidate['fetched_at'] < TIME_NOW - ACCESS_AUDIT_EMAIL_RECHECK;
}

/**
 * Look up one batch: as many of the addresses due as the API's limit has room for, never
 * asked about first, then the longest ago. Each Legion ID found is saved to the profile of
 * every member with that address, and noted (see access_audit_found_legion_ids()).
 *
 * A Legion ID saved once and taken off the member's profile since is not saved again: it
 * was somebody's decision that it is not theirs - an address shared with a partner who is
 * the 501st member, say.
 *
 * @param array $config As access_audit_config(), with nothing missing
 * @return array 'asked', 'saved', 'no_match', 'unanswered' (counts of addresses, but
 *               'saved' of members), 'limited' (whether the API turned lookups away), and
 *               'remaining' (addresses still due)
 */
function access_audit_lookup_batch(array $config)
{
    global $db;

    $result = array('asked' => 0, 'saved' => 0, 'no_match' => 0, 'unanswered' => 0, 'limited' => false, 'remaining' => 0);

    $candidates = access_audit_lookup_candidates($config);
    $due = array();
    foreach($candidates as $email => $candidate)
    {
        if(access_audit_lookup_due($candidate))
        {
            $due[$email] = $candidate['fetched_at'];
        }
    }
    asort($due);

    $asking = array_slice(array_keys($due), 0, access_audit_email_allowance());
    $result['remaining'] = count($due);
    if(!$asking)
    {
        return $result;
    }

    $answers = access_audit_lookup_emails($asking, $limited);
    $result['asked'] = count($asking);
    $result['limited'] = $limited;
    $result['remaining'] = count($due) - count($answers);

    foreach($asking as $email)
    {
        if(!isset($answers[$email]))
        {
            $result['unanswered']++;
            continue;
        }

        $legion_id = (int)$answers[$email];
        if($legion_id === 0)
        {
            $result['no_match']++;
            continue;
        }

        foreach($candidates[$email]['members'] as $member)
        {
            $uid = (int)$member['uid'];
            if($db->fetch_field($db->simple_select("access_audit_found_legion_ids", "id", "uid = {$uid} AND legion_id = {$legion_id}", array('limit' => 1)), "id"))
            {
                continue;
            }

            if(access_audit_save_legion_id($member, $legion_id, $config))
            {
                $db->insert_query("access_audit_found_legion_ids", array(
                    'uid'       => $uid,
                    'legion_id' => $legion_id,
                    'found_at'  => TIME_NOW,
                ));
                $result['saved']++;
            }
        }
    }

    return $result;
}

/**
 * A batch's result, in words, for the task log and the Legion IDs tab.
 *
 * @param array $result As access_audit_lookup_batch()
 * @return string
 */
function access_audit_describe_batch(array $result)
{
    global $lang;

    if($result['asked'] === 0)
    {
        return $result['remaining'] > 0
            ? $lang->sprintf($lang->access_audit_batch_no_room, my_number_format($result['remaining']))
            : $lang->access_audit_batch_done;
    }

    $text = $lang->sprintf($lang->access_audit_batch_result,
        my_number_format($result['asked']),
        my_number_format($result['saved']),
        my_number_format($result['no_match']),
        my_number_format($result['unanswered']),
        my_number_format($result['remaining']));
    if($result['limited'])
    {
        $text .= ' ' . $lang->access_audit_batch_limited;
    }

    return $text;
}

/**
 * How far the lookups have got, for the Legion IDs tab. Counted in members, not addresses.
 *
 * @param array $config As access_audit_config()
 * @return array 'members' (with no Legion ID, and an address to look up), 'never' (not
 *               looked up yet), 'due' (to look up, those included), 'no_match',
 *               'batches' (how many batches the due addresses will take), 'found' (Legion
 *               IDs saved, ever), 'allowance' (lookups the limit has room for now) and
 *               'next_run' (Unix time; null when the task is disabled)
 */
function access_audit_lookup_progress(array $config)
{
    global $db;

    $progress = array('members' => 0, 'never' => 0, 'due' => 0, 'no_match' => 0, 'batches' => 0, 'found' => 0, 'allowance' => 0, 'next_run' => null);

    $due_addresses = 0;
    foreach(access_audit_lookup_candidates($config) as $candidate)
    {
        $members = count($candidate['members']);
        $progress['members'] += $members;
        if($candidate['fetched_at'] === 0)
        {
            $progress['never'] += $members;
        }
        if(access_audit_lookup_due($candidate))
        {
            $progress['due'] += $members;
            $due_addresses++;
        }
        elseif($candidate['legion_id'] === 0)
        {
            $progress['no_match'] += $members;
        }
    }

    $progress['batches'] = (int)ceil($due_addresses / ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT);
    $progress['found'] = (int)$db->fetch_field($db->simple_select("access_audit_found_legion_ids", "COUNT(*) AS found"), "found");
    $progress['allowance'] = access_audit_email_allowance();

    $task = $db->fetch_array($db->simple_select("tasks", "enabled, nextrun", "file = '" . ACCESS_AUDIT_TASK_FILE . "'"));
    if($task && (int)$task['enabled'] === 1)
    {
        $progress['next_run'] = (int)$task['nextrun'];
    }

    return $progress;
}

/**
 * The Legion IDs the lookups have saved, newest first.
 *
 * @return array Each with 'uid', 'username' (null for a member deleted since), 'legion_id'
 *               and 'found_at'
 */
function access_audit_found_legion_ids()
{
    global $db;

    $found = array();
    $query = $db->query("
        SELECT f.uid, f.legion_id, f.found_at, u.username
        FROM " . TABLE_PREFIX . "access_audit_found_legion_ids f
        LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = f.uid)
        ORDER BY f.id DESC
        LIMIT " . (int)ACCESS_AUDIT_FOUND_SHOWN . "
    ");
    while($row = $db->fetch_array($query))
    {
        $found[] = array(
            'uid'       => (int)$row['uid'],
            'username'  => $row['username'],
            'legion_id' => (int)$row['legion_id'],
            'found_at'  => (int)$row['found_at'],
        );
    }

    return $found;
}

/**
 * Have the scheduled task run on the next page view, rather than wait its turn - once the
 * audit has just been set up, say.
 */
function access_audit_schedule_lookups()
{
    global $db, $cache;

    $db->update_query("tasks", array('nextrun' => TIME_NOW), "file = '" . ACCESS_AUDIT_TASK_FILE . "' AND enabled = 1");
    $cache->update_tasks();
}

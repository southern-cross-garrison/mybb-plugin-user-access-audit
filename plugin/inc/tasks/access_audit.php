<?php
/**
 * MyBB Access Audit Plugin - Scheduled task: Legion IDs by email address
 *
 * Every five minutes, a batch of members with no Legion ID looked up by their email address
 * (see access_audit_lookup_batch()). Most runs find the API's limit already spent by the last
 * batch and do nothing; it is every five minutes rather than fifteen so that a batch is never
 * held up a whole extra turn for a lookup the limit counts until just after it.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

require_once MYBB_ROOT . "inc/plugins/access_audit/inc/access_audit_lookup.php";

/**
 * Run one batch, and log what it did. A run that did nothing is not logged, or there would
 * be an entry every five minutes once everybody has been looked up.
 *
 * @param array $task
 */
function task_access_audit($task)
{
    global $lang;

    // The strings are the Admin CP page's. Run from the Admin CP's task manager the language
    // path is the Admin CP's already; run from the forum it is not.
    $lang->load(defined('IN_ADMINCP') ? 'user_access_audit' : 'admin/user_access_audit', false, true);

    $config = access_audit_config();
    if($config['missing'])
    {
        return;
    }

    $result = access_audit_lookup_batch($config);
    if($result['asked'] > 0 || $result['saved'] > 0)
    {
        add_task_log($task, access_audit_describe_batch($result));
    }
}

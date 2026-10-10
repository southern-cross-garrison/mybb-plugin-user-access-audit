<?php
/**
 * MyBB Access Audit Plugin - The 501st Legion's API
 *
 * Two calls: /garrisons, the list of units (garrisons and outposts) the Settings tab picks
 * from, and /legionId/{id}, one member's record. The record is what the audit judges a
 * member by: memberStatus, and garrisonId for which unit they belong to.
 *
 * The API answers a member who has made their record private and an ID nobody holds the
 * same way: 200, with nothing but {"memberStatus": "Classified Record"}. Members who have
 * left or retired come back like that too, and in practice most classified IDs are those.
 * The audit counts any of them as not active (see access_audit_evaluate()).
 *
 * An API that is down, slow, or answers with anything that is not a record is no answer at
 * all - never a reason to take a member's access away.
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

/**
 * Seconds to wait on the API, connecting and in all, for each request.
 */
define('ACCESS_AUDIT_API_CONNECT_TIMEOUT', 5);
define('ACCESS_AUDIT_API_TIMEOUT', 15);

/**
 * How many member records are fetched at once. A board of a few hundred members is a few
 * hundred requests; one at a time that outlasts PHP's time limit.
 */
define('ACCESS_AUDIT_API_CONCURRENCY', 8);

/**
 * How long an answer from the API is reused for, in seconds. Long enough that fixing the
 * members on the page one by one does not ask about everybody again after each; short
 * enough that an audit opened later is the 501st's word as it is now.
 */
define('ACCESS_AUDIT_LOOKUP_TTL', 900);

/**
 * The API's base address, with no trailing slash, or '' when it is not set.
 *
 * @param string|null $base A base other than the setting's, for the Settings tab checking a
 *                          new one before it is saved
 * @return string
 */
function access_audit_api_base($base = null)
{
    if($base === null)
    {
        $base = access_audit_setting('api_url');
    }

    return rtrim(trim((string)$base), '/');
}

/**
 * Whether an address is one the plugin will send requests to: http or https, with a host.
 *
 * @param string $url
 * @return bool
 */
function access_audit_valid_api_url($url)
{
    return (bool)preg_match('#^https?://[^\s/?\#]+#i', (string)$url) && !preg_match('#\s#', (string)$url);
}

/**
 * A curl handle for one GET of the API.
 *
 * Not fetch_remote_file(): it refuses localhost and private addresses, which is right for
 * a URL a member supplies and wrong for one only an administrator can set, and it cannot
 * run requests side by side.
 *
 * @param string $url
 * @return resource|CurlHandle
 */
function access_audit_api_handle($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => ACCESS_AUDIT_API_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => ACCESS_AUDIT_API_TIMEOUT,
        CURLOPT_HTTPHEADER     => array('Accept: application/json'),
    ));

    return $ch;
}

/**
 * Free a curl handle. Only before PHP 8, where handles are resources: since then they are
 * objects freed when the last reference goes, curl_close() does nothing, and from 8.5 on it
 * is deprecated.
 *
 * @param resource|CurlHandle $ch
 */
function access_audit_api_close($ch)
{
    if(PHP_VERSION_ID < 80000)
    {
        curl_close($ch);
    }
}

/**
 * Decode a response, or null if it is not a 200 carrying a JSON object.
 *
 * @param string|false $body
 * @param int $status
 * @return array|null
 */
function access_audit_api_decode($body, $status)
{
    if(!is_string($body) || (int)$status !== 200)
    {
        return null;
    }

    $data = json_decode($body, true);

    return is_array($data) ? $data : null;
}

/**
 * The 501st's units: every garrison and outpost, sorted by name.
 *
 * @param string|null $base As access_audit_api_base()
 * @return array|null unit id => array('name' => string, 'type' => string); null when the API
 *                    gave no usable answer
 */
function access_audit_api_units($base = null)
{
    $base = access_audit_api_base($base);
    if($base === '' || !access_audit_valid_api_url($base) || !function_exists('curl_init'))
    {
        return null;
    }

    $ch = access_audit_api_handle($base . '/garrisons');
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    access_audit_api_close($ch);

    $data = access_audit_api_decode($body, $status);
    if($data === null || !isset($data['garrisons']) || !is_array($data['garrisons']))
    {
        return null;
    }

    $units = array();
    foreach($data['garrisons'] as $unit)
    {
        if(!is_array($unit) || !isset($unit['garrisonId'], $unit['name']) || !is_numeric($unit['garrisonId']) || !is_string($unit['name']))
        {
            continue;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $unit['name']));
        if($name === '' || (int)$unit['garrisonId'] <= 0)
        {
            continue;
        }

        $units[(int)$unit['garrisonId']] = array(
            'name' => $name,
            'type' => isset($unit['unitType']) && is_string($unit['unitType']) ? trim($unit['unitType']) : '',
        );
    }

    if(!$units)
    {
        return null;
    }

    uasort($units, function($a, $b)
    {
        return strnatcasecmp($a['name'], $b['name']);
    });

    return $units;
}

/**
 * One member's record, as the audit needs it, from a decoded /legionId/{id} response.
 *
 * @param array $data
 * @return array 'status', 'garrison_id', 'garrison_name', 'name'
 */
function access_audit_api_parse_member(array $data)
{
    $status = isset($data['memberStatus']) && is_string($data['memberStatus']) ? trim($data['memberStatus']) : '';

    // A classified record has a status and nothing else. Anything else without a name is
    // treated the same way: there is no record to go on.
    if(!isset($data['fullName']) || !is_string($data['fullName']))
    {
        return array(
            'status'        => ACCESS_AUDIT_CLASSIFIED,
            'garrison_id'   => 0,
            'garrison_name' => '',
            'name'          => '',
        );
    }

    return array(
        'status'        => $status !== '' ? my_substr($status, 0, 50) : 'Unknown',
        'garrison_id'   => isset($data['garrisonId']) && is_numeric($data['garrisonId']) ? (int)$data['garrisonId'] : 0,
        'garrison_name' => isset($data['garrisonName']) && is_string($data['garrisonName']) ? my_substr(trim($data['garrisonName']), 0, 255) : '',
        'name'          => my_substr(trim(preg_replace('/\s+/u', ' ', $data['fullName'])), 0, 255),
    );
}

/**
 * Fetch member records from the API, several at a time.
 *
 * @param array $legion_ids Of int
 * @return array legion id => record (as access_audit_api_parse_member()), for the IDs the
 *               API answered; an ID it did not answer is missing
 */
function access_audit_api_fetch_members(array $legion_ids)
{
    $base = access_audit_api_base();
    if($base === '' || !access_audit_valid_api_url($base) || !function_exists('curl_multi_init') || !$legion_ids)
    {
        return array();
    }

    $results = array();
    $pending = array_values(array_unique(array_map('intval', $legion_ids)));
    $multi = curl_multi_init();
    $running_handles = array();

    $start_next = function() use (&$pending, &$running_handles, $multi, $base)
    {
        $legion_id = array_shift($pending);
        $ch = access_audit_api_handle($base . '/legionId/' . $legion_id);
        curl_multi_add_handle($multi, $ch);
        $running_handles[(int)$legion_id] = $ch;
    };

    while($pending && count($running_handles) < ACCESS_AUDIT_API_CONCURRENCY)
    {
        $start_next();
    }

    do
    {
        $status = curl_multi_exec($multi, $active);
        if($active)
        {
            curl_multi_select($multi, 1.0);
        }

        while($done = curl_multi_info_read($multi))
        {
            $ch = $done['handle'];
            $legion_id = array_search($ch, $running_handles, true);

            if($legion_id !== false)
            {
                $data = $done['result'] === CURLE_OK
                    ? access_audit_api_decode(curl_multi_getcontent($ch), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE))
                    : null;

                if($data !== null)
                {
                    $results[(int)$legion_id] = access_audit_api_parse_member($data);
                }

                unset($running_handles[$legion_id]);
            }

            curl_multi_remove_handle($multi, $ch);
            access_audit_api_close($ch);

            if($pending)
            {
                $start_next();
                $active = true;
            }
        }
    }
    while(($active || $running_handles) && $status === CURLM_OK);

    foreach($running_handles as $ch)
    {
        curl_multi_remove_handle($multi, $ch);
        access_audit_api_close($ch);
    }
    curl_multi_close($multi);

    return $results;
}

/**
 * The 501st's records for these Legion IDs: from the lookups table when they were fetched
 * recently, otherwise from the API, keeping what it answers.
 *
 * @param array $legion_ids Of int
 * @return array legion id => record; an ID neither had is missing
 */
function access_audit_lookup_members(array $legion_ids)
{
    global $db;

    $legion_ids = array_values(array_unique(array_filter(array_map('intval', $legion_ids))));
    if(!$legion_ids)
    {
        return array();
    }

    $records = array();
    $fresh_since = TIME_NOW - ACCESS_AUDIT_LOOKUP_TTL;

    foreach(array_chunk($legion_ids, 500) as $chunk)
    {
        $query = $db->simple_select("access_audit_lookups", "*",
            "legion_id IN (" . implode(',', $chunk) . ") AND fetched_at >= " . (int)$fresh_since);
        while($row = $db->fetch_array($query))
        {
            $records[(int)$row['legion_id']] = array(
                'status'        => $row['member_status'],
                'garrison_id'   => (int)$row['garrison_id'],
                'garrison_name' => $row['garrison_name'],
                'name'          => $row['full_name'],
            );
        }
    }

    $missing = array_values(array_diff($legion_ids, array_keys($records)));
    if(!$missing)
    {
        return $records;
    }

    @set_time_limit(0);

    foreach(access_audit_api_fetch_members($missing) as $legion_id => $record)
    {
        $records[$legion_id] = $record;

        $db->replace_query("access_audit_lookups", array(
            'legion_id'     => (int)$legion_id,
            'member_status' => $db->escape_string($record['status']),
            'garrison_id'   => (int)$record['garrison_id'],
            'garrison_name' => $db->escape_string($record['garrison_name']),
            'full_name'     => $db->escape_string($record['name']),
            'fetched_at'    => TIME_NOW,
        ));
    }

    return $records;
}

/**
 * Forget every kept answer, so the next audit asks the API about everybody.
 */
function access_audit_forget_lookups()
{
    global $db;

    $db->delete_query("access_audit_lookups");
}

/**
 * When the oldest answer the audit is going on was fetched.
 *
 * @return int|null Unix time; null when nothing is kept
 */
function access_audit_oldest_lookup()
{
    global $db;

    $oldest = $db->fetch_field($db->simple_select("access_audit_lookups", "MIN(fetched_at) AS oldest",
        "fetched_at >= " . (int)(TIME_NOW - ACCESS_AUDIT_LOOKUP_TTL)), "oldest");

    return $oldest ? (int)$oldest : null;
}

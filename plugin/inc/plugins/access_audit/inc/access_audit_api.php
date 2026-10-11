<?php
/**
 * MyBB Access Audit Plugin - The 501st Legion's API
 *
 * Four calls: /garrisons and /squads, the lists of units (garrisons and outposts) and of
 * squads the Settings tab picks from, /legionId/{id}, one member's record, and
 * /email/{email}, the Legion ID of the member with that email address. The record is what
 * the audit judges a member by: memberStatus, garrisonId for which unit they belong to, and
 * squadId for which squad, if any. The list of squads does not say which unit each is in,
 * so that comes from their officers' records (see access_audit_api_squads()). Email
 * addresses are only ever looked up in the background, a batch at a time, for the API's
 * limit on those (see ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT).
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
 * How long a member record from the API is reused for, in seconds. Long enough that fixing
 * the members on the page one by one does not ask about everybody again after each; short
 * enough that an audit opened later is the 501st's word as it is now.
 */
define('ACCESS_AUDIT_LOOKUP_TTL', 900);

/**
 * The API allows each address 60 email lookups in any 15 minutes (its
 * EMAIL_LOOKUP_RATE_LIMIT_PER_IP) and answers the rest with 429 Too Many Requests; member
 * records have no such limit. So members are looked up by email address in batches in the
 * background (see access_audit_lookup.php), which keep under it by the plugin's own count -
 * a little short of it: a lookup the API did not answer counts there and not here, and the
 * board may not be the only thing asking from its address.
 */
define('ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT', 50);
define('ACCESS_AUDIT_EMAIL_LOOKUP_WINDOW', 900);

/**
 * How long until an address no approved member has is asked about again: for a member who
 * has joined the 501st since, or given it the address they use here. An address that is
 * somebody's has its Legion ID saved, and is not asked about again at all.
 */
define('ACCESS_AUDIT_EMAIL_RECHECK', 604800);

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
 * One GET of the API, decoded.
 *
 * @param string $base As access_audit_api_base()
 * @param string $path From the base, starting with a slash
 * @return array|null As access_audit_api_decode(); null when the base is not usable either
 */
function access_audit_api_get($base, $path)
{
    if($base === '' || !access_audit_valid_api_url($base) || !function_exists('curl_init'))
    {
        return null;
    }

    $ch = access_audit_api_handle($base . $path);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    access_audit_api_close($ch);

    return access_audit_api_decode($body, $status);
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
    $data = access_audit_api_get(access_audit_api_base($base), '/garrisons');
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
 * The squads of one unit, sorted by name.
 *
 * /squads lists every squad in the Legion and says nothing of which unit each belongs to,
 * and no call answers that directly. What each squad does list is its officers, and an
 * officer's own record names their garrison - and their squad, which is checked, so an
 * officer whose record puts them somewhere else is passed over for the next one. That is a
 * record per squad, so they go through the lookups table like members' do.
 *
 * @param int $unit
 * @param string|null $base As access_audit_api_base()
 * @return array|null squad id => name; null when the API gave no usable answer, or did not
 *                    answer for an officer, since a squad left out for that would look
 *                    like a squad the unit does not have. A squad none of whose officers
 *                    has a public record cannot be placed, and is left out.
 */
function access_audit_api_squads($unit, $base = null)
{
    $base = access_audit_api_base($base);
    $data = access_audit_api_get($base, '/squads');
    if($data === null || !isset($data['squad']) || !is_array($data['squad']))
    {
        return null;
    }

    $squads = array();
    foreach($data['squad'] as $squad)
    {
        if(!is_array($squad) || !isset($squad['squadId'], $squad['name']) || !is_numeric($squad['squadId']) || !is_string($squad['name']))
        {
            continue;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $squad['name']));
        if($name === '' || (int)$squad['squadId'] <= 0)
        {
            continue;
        }

        $officers = array();
        foreach(isset($squad['officers']) && is_array($squad['officers']) ? $squad['officers'] : array() as $officer)
        {
            if(is_array($officer) && isset($officer['legionId']) && is_numeric($officer['legionId']) && (int)$officer['legionId'] > 0)
            {
                $officers[] = (int)$officer['legionId'];
            }
        }

        $squads[(int)$squad['squadId']] = array('name' => $name, 'officers' => array_values(array_unique($officers)));
    }

    if(!$squads)
    {
        return null;
    }

    // Each squad's first officer, then the next for any squad that could not be placed by
    // the first, and so on. Almost every squad is placed by the first.
    $units = array();
    for($round = 0; ; $round++)
    {
        $asking = array();
        foreach($squads as $id => $squad)
        {
            if(!isset($units[$id]) && isset($squad['officers'][$round]))
            {
                $asking[$id] = $squad['officers'][$round];
            }
        }
        if(!$asking)
        {
            break;
        }

        $records = access_audit_lookup_members(array_values($asking), $base);
        foreach($asking as $id => $legion_id)
        {
            if(!isset($records[$legion_id]))
            {
                return null;
            }

            $record = $records[$legion_id];
            if($record['garrison_id'] > 0 && $record['squad_id'] === $id)
            {
                $units[$id] = $record['garrison_id'];
            }
        }
    }

    $names = array();
    foreach($squads as $id => $squad)
    {
        if(isset($units[$id]) && $units[$id] === (int)$unit)
        {
            $names[$id] = $squad['name'];
        }
    }

    uasort($names, 'strnatcasecmp');

    return $names;
}

/**
 * One member's record, as the audit needs it, from a decoded /legionId/{id} response.
 *
 * @param array $data
 * @return array 'status', 'garrison_id', 'garrison_name', 'squad_id' (0 for none),
 *               'squad_name' and 'name'
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
            'squad_id'      => 0,
            'squad_name'    => '',
            'name'          => '',
        );
    }

    // A member in no squad comes back as squadId "" and squadName "No Squad"; one in a
    // squad has its id as a string of digits.
    $squad_id = isset($data['squadId']) && is_numeric($data['squadId']) ? max(0, (int)$data['squadId']) : 0;

    return array(
        'status'        => $status !== '' ? my_substr($status, 0, 50) : 'Unknown',
        'garrison_id'   => isset($data['garrisonId']) && is_numeric($data['garrisonId']) ? (int)$data['garrisonId'] : 0,
        'garrison_name' => isset($data['garrisonName']) && is_string($data['garrisonName']) ? my_substr(trim($data['garrisonName']), 0, 255) : '',
        'squad_id'      => $squad_id,
        'squad_name'    => $squad_id > 0 && isset($data['squadName']) && is_string($data['squadName']) ? my_substr(trim($data['squadName']), 0, 255) : '',
        'name'          => my_substr(trim(preg_replace('/\s+/u', ' ', $data['fullName'])), 0, 255),
    );
}

/**
 * GET several paths of the API, several at a time.
 *
 * @param array $paths key => path from the base, starting with a slash
 * @param string|null $base As access_audit_api_base()
 * @param bool $limited Set to whether the API turned a request away for asking too often,
 *                      after which nothing more is asked
 * @return array key => decoded response (as access_audit_api_decode()), for the paths the
 *               API answered; a path it did not answer is missing
 */
function access_audit_api_fetch_many(array $paths, $base = null, &$limited = null)
{
    $limited = false;
    $base = access_audit_api_base($base);
    if($base === '' || !access_audit_valid_api_url($base) || !function_exists('curl_multi_init') || !$paths)
    {
        return array();
    }

    $results = array();
    $pending = $paths;
    $multi = curl_multi_init();
    $running_handles = array();

    $start_next = function() use (&$pending, &$running_handles, $multi, $base)
    {
        // Not array_shift(), which would renumber Legion IDs used as keys.
        reset($pending);
        $key = key($pending);
        $ch = access_audit_api_handle($base . $pending[$key]);
        unset($pending[$key]);
        curl_multi_add_handle($multi, $ch);
        $running_handles[$key] = $ch;
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
            $key = array_search($ch, $running_handles, true);

            if($key !== false)
            {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if($done['result'] === CURLE_OK && $code === 429)
                {
                    // Anything more asked now would only be turned away too.
                    $limited = true;
                    $pending = array();
                }

                $data = $done['result'] === CURLE_OK
                    ? access_audit_api_decode(curl_multi_getcontent($ch), $code)
                    : null;

                if($data !== null)
                {
                    $results[$key] = $data;
                }

                unset($running_handles[$key]);
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
 * Fetch member records from the API, several at a time.
 *
 * @param array $legion_ids Of int
 * @param string|null $base As access_audit_api_base()
 * @return array legion id => record (as access_audit_api_parse_member()), for the IDs the
 *               API answered; an ID it did not answer is missing
 */
function access_audit_api_fetch_members(array $legion_ids, $base = null)
{
    $paths = array();
    foreach(array_unique(array_map('intval', $legion_ids)) as $legion_id)
    {
        $paths[$legion_id] = '/legionId/' . $legion_id;
    }

    $records = array();
    foreach(access_audit_api_fetch_many($paths, $base) as $legion_id => $data)
    {
        $records[(int)$legion_id] = access_audit_api_parse_member($data);
    }

    return $records;
}

/**
 * The Legion ID an /email/{email} response gives.
 *
 * The API answers an address no approved member has with {"error": "..."}, and one that
 * an approved member has - as their primary or secondary address - with {"legionId": n}.
 *
 * @param array $data
 * @return int|null The Legion ID; 0 for no match; null when the answer is neither
 */
function access_audit_api_parse_email(array $data)
{
    if(isset($data['legionId']))
    {
        $legion_id = is_int($data['legionId']) || (is_string($data['legionId']) && ctype_digit($data['legionId']))
            ? access_audit_legion_number((string)$data['legionId'])
            : 0;

        return $legion_id > 0 ? $legion_id : null;
    }

    return isset($data['error']) && is_string($data['error']) ? 0 : null;
}

/**
 * Look email addresses up on the API, several at a time.
 *
 * @param array $emails Of string, as access_audit_normalise_email()
 * @param bool $limited As access_audit_api_fetch_many()
 * @return array email => Legion ID (0 for no match), for the addresses the API answered;
 *               one it did not answer is missing
 */
function access_audit_api_fetch_emails(array $emails, &$limited = null)
{
    $paths = array();
    foreach(array_unique($emails) as $email)
    {
        $paths[$email] = '/email/' . rawurlencode($email);
    }

    $found = array();
    foreach(access_audit_api_fetch_many($paths, null, $limited) as $email => $data)
    {
        $legion_id = access_audit_api_parse_email($data);
        if($legion_id !== null)
        {
            $found[(string)$email] = $legion_id;
        }
    }

    return $found;
}

/**
 * The 501st's records for these Legion IDs: from the lookups table when they were fetched
 * recently, otherwise from the API, keeping what it answers.
 *
 * @param array $legion_ids Of int
 * @param string|null $base As access_audit_api_base(). The table holds the saved API's
 *                          answers only, so another API - the Settings tab trying an
 *                          address before it is saved - is asked directly, and nothing kept.
 * @return array legion id => record; an ID neither had is missing
 */
function access_audit_lookup_members(array $legion_ids, $base = null)
{
    global $db;

    $legion_ids = array_values(array_unique(array_filter(array_map('intval', $legion_ids))));
    if(!$legion_ids)
    {
        return array();
    }

    if($base !== null && access_audit_api_base($base) !== access_audit_api_base())
    {
        @set_time_limit(0);

        return access_audit_api_fetch_members($legion_ids, $base);
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
                'squad_id'      => (int)$row['squad_id'],
                'squad_name'    => $row['squad_name'],
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
            'squad_id'      => (int)$record['squad_id'],
            'squad_name'    => $db->escape_string($record['squad_name']),
            'full_name'     => $db->escape_string($record['name']),
            'fetched_at'    => TIME_NOW,
        ));
    }

    return $records;
}

/**
 * An email address as it is looked up: trimmed and in lower case, or '' when it is not an
 * address at all.
 *
 * @param string $email
 * @return string
 */
function access_audit_normalise_email($email)
{
    $email = strtolower(trim((string)$email));

    return $email !== '' && validate_email_format($email) ? $email : '';
}

/**
 * What the email lookups table holds for these addresses, however old.
 *
 * The table holds a hash of each address rather than the address, so the plugin keeps no
 * copy of members' addresses of its own.
 *
 * @param array $emails Of string, as access_audit_normalise_email()
 * @return array email => array('legion_id' => int (0 for no match), 'fetched_at' => int);
 *               an address never asked about is missing
 */
function access_audit_kept_emails(array $emails)
{
    global $db;

    $hashes = array();
    foreach(array_filter(array_unique($emails), 'strlen') as $email)
    {
        $hashes[sha1($email)] = $email;
    }

    $kept = array();
    foreach(array_chunk(array_keys($hashes), 500) as $chunk)
    {
        $query = $db->simple_select("access_audit_email_lookups", "email_hash, legion_id, fetched_at",
            "email_hash IN ('" . implode("','", $chunk) . "')");
        while($row = $db->fetch_array($query))
        {
            $kept[$hashes[$row['email_hash']]] = array(
                'legion_id'  => (int)$row['legion_id'],
                'fetched_at' => (int)$row['fetched_at'],
            );
        }
    }

    return $kept;
}

/**
 * How many email lookups the API's limit leaves room for now (see
 * ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT). Every answer kept was a lookup, so the answers kept in
 * the last window are the lookups spent in it.
 *
 * @return int
 */
function access_audit_email_allowance()
{
    global $db;

    $spent = (int)$db->fetch_field($db->simple_select("access_audit_email_lookups", "COUNT(*) AS spent",
        "fetched_at > " . (int)(TIME_NOW - ACCESS_AUDIT_EMAIL_LOOKUP_WINDOW)), "spent");

    return max(0, ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT - $spent);
}

/**
 * Ask the API for the Legion IDs of the members with these email addresses, and keep what
 * it answers. Nothing is checked against the allowance here: the caller asks for no more
 * than access_audit_email_allowance().
 *
 * @param array $emails Of string, as access_audit_normalise_email()
 * @param bool $limited As access_audit_api_fetch_many()
 * @return array email => Legion ID (0 for no match), for the addresses the API answered
 */
function access_audit_lookup_emails(array $emails, &$limited = null)
{
    global $db;

    $limited = false;
    $emails = array_values(array_filter(array_unique($emails), 'strlen'));
    if(!$emails)
    {
        return array();
    }

    @set_time_limit(0);

    $answers = access_audit_api_fetch_emails($emails, $limited);
    foreach($answers as $email => $legion_id)
    {
        $db->replace_query("access_audit_email_lookups", array(
            'email_hash' => $db->escape_string(sha1($email)),
            'legion_id'  => (int)$legion_id,
            'fetched_at' => TIME_NOW,
        ));
    }

    return $answers;
}

/**
 * Forget kept answers, so they are asked for again.
 *
 * @param bool $emails Whether email lookups' answers go too. Check Again keeps them: they
 *                     are rationed (see ACCESS_AUDIT_EMAIL_LOOKUP_LIMIT), and asking about
 *                     every address again takes hours.
 */
function access_audit_forget_lookups($emails = true)
{
    global $db;

    $db->delete_query("access_audit_lookups");
    if($emails)
    {
        $db->delete_query("access_audit_email_lookups");
    }
}

/**
 * When the oldest member record the audit is going on was fetched.
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

<?php
/**
 * A stand-in for the 501st Legion API's /garrisons, /squads and /legionId/{id}, for the e2e
 * suite.
 *
 * Global setup copies this to the forum root, and provisioning points the
 * access_audit_api_url setting at it, so no test ever reaches the real API. A test says
 * what the API answers by writing a file into e2e-legion-api/ beside it
 * (tests/helpers/legion-api.ts): garrisons.json for the list of units, squads.json for the
 * list of squads, <id>.json for one
 * Legion ID, each {"status": 200, "body": {...}} or {"raw": "..."}. Anything with no file
 * answers 503, the API being down. Every request is appended to
 * e2e-legion-api/requests.log.
 */
$dir = __DIR__ . '/e2e-legion-api';
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';

if(is_dir($dir))
{
    file_put_contents($dir . '/requests.log', $path . "\n", FILE_APPEND | LOCK_EX);
}

$file = null;
if($path === '/garrisons')
{
    $file = $dir . '/garrisons.json';
}
elseif($path === '/squads')
{
    $file = $dir . '/squads.json';
}
elseif(preg_match('#^/legionId/(\d+)$#', $path, $match))
{
    $file = $dir . '/' . $match[1] . '.json';
}

$response = $file !== null && is_file($file) ? json_decode(file_get_contents($file), true) : null;

if(!is_array($response))
{
    http_response_code(503);
    exit;
}

http_response_code(isset($response['status']) ? (int)$response['status'] : 200);
header('Content-Type: application/json');
echo isset($response['raw']) ? $response['raw'] : json_encode(isset($response['body']) ? $response['body'] : null);

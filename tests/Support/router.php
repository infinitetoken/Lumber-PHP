<?php

declare(strict_types=1);

// The test server's router (php -S): keeps each request in the file LUMBER_TEST_REQUESTS names, and answers as its path
// asks, so a test points the client at http://127.0.0.1:port/status/503/retry-after/60/logs to get a 503 with a
// Retry-After of 60. /sleep/<ms> waits before answering.

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);
$request = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'headers' => array_change_key_case(getallheaders(), \CASE_LOWER),
    'body' => file_get_contents('php://input'),
];
file_put_contents((string) getenv('LUMBER_TEST_REQUESTS'), json_encode($request) . "\n", \FILE_APPEND | \LOCK_EX);

if (preg_match('~/sleep/(\d+)~', $path, $match) === 1) {
    usleep((int) $match[1] * 1000);
}
$status = preg_match('~/status/(\d+)~', $path, $match) === 1 ? (int) $match[1] : 201;
if (preg_match('~/retry-after/([^/]+)~', $path, $match) === 1) {
    header('Retry-After: ' . urldecode($match[1]));
}
http_response_code($status);
header('Content-Type: application/json');
echo json_encode($status < 300 ? ['data' => ['id' => 'log-1']] : ['message' => "Refused with HTTP {$status}"]);

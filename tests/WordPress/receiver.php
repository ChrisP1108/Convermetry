<?php
/**
 * A real HTTP endpoint for the end-to-end suite to deliver to.
 *
 * Run by PHP's built-in server ({@see WebhookReceiver}). Every request is
 * appended to the capture file as one JSON line, so a test can assert on what
 * actually arrived over the wire — headers, signature, body — rather than on
 * what the plugin believed it sent.
 *
 * The path decides the response: /ok answers 200, /fail answers 500, so the
 * retry and failure paths can be exercised against genuine HTTP statuses.
 * /whoami answers this instance's token and is NOT captured — it is how the
 * caller tells its own server apart from a stale one left on the port.
 */

declare(strict_types=1);

$log = (string) getenv('CVM_RECEIVER_LOG');
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Answered before anything is captured: an identity probe is the caller's, not
// a delivery, and recording it would show up as a phantom webhook.
if ($uri === '/whoami') {
    header('Content-Type: text/plain');
    echo (string) getenv('CVM_RECEIVER_TOKEN');

    return true;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
    }
}

if ($log !== '') {
    file_put_contents(
        $log,
        json_encode([
            'path'    => $uri,
            'method'  => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'headers' => $headers,
            'body'    => (string) file_get_contents('php://input'),
        ]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if ($uri === '/fail') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"receiver refused"}';

    return true;
}

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

return true;

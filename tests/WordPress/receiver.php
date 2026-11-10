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
 * retry and failure paths can be exercised against genuine HTTP statuses. A
 * second segment swaps the JSON body for another (see the end of this file).
 * /whoami answers this instance's token and is NOT captured — it is how the
 * caller tells its own server apart from a stale one left on the port.
 */

declare(strict_types=1);

$log = (string) getenv('CVMTRY_RECEIVER_LOG');
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Answered before anything is captured: an identity probe is the caller's, not
// a delivery, and recording it would show up as a phantom webhook.
if ($uri === '/whoami') {
    header('Content-Type: text/plain');
    echo (string) getenv('CVMTRY_RECEIVER_TOKEN');

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

// A second segment picks a body other than the default JSON, for the Activity
// Log's storage tests: /ok/japanese-html, /fail/japanese-html, /fail/latin1-html.
[$outcome, $variant] = array_pad(explode('/', ltrim($uri, '/'), 2), 2, '');

http_response_code($outcome === 'fail' ? 500 : 200);

if ($variant === 'japanese-html') {
    // About 90 KB, so the transport's 64 KB download limit cuts it, in bytes,
    // inside a character.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><body>', str_repeat('<p>日本語のテキストです。</p>', 2300), '</body></html>';

    return true;
}

if ($variant === 'latin1-html') {
    // A short error page in ISO-8859-1: each "é" is the single byte 0xE9.
    header('Content-Type: text/html; charset=iso-8859-1');
    echo "<html><body><h1>Erreur 500</h1><p>Service momentan\xE9ment indisponible. R\xE9essayez plus tard.</p></body></html>";

    return true;
}

header('Content-Type: application/json');
echo $outcome === 'fail' ? '{"error":"receiver refused"}' : '{"ok":true}';

return true;

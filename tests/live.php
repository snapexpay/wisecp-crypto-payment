<?php
/**
 * Live check of the gateway without a WISECP licence: the real gateway code on the WISECP stubs, the real SnapEX Pay
 * API (test invoices) and real signed webhooks. Router for the PHP built-in server, state kept in $SNAPEX_LIVE_DIR:
 *
 *   SNAPEX_LIVE_DIR=/tmp/live SNAPEX_LIVE_URL=https://<public url> php -S 127.0.0.1:8099 integrations/wisecp/tests/live.php
 *
 * $SNAPEX_LIVE_DIR/keys.json: {"api_key": "smk_…", "secret": "whsec_…"}. Webhook URL in the cabinet:
 * <public url>/payment/SnapexPay/9f2c1d/callback.
 *   GET  /create?amount=12.50&checkout=1001        admin in test mode → SnapEX test invoice, prints the payment page URL
 *   POST /payment/SnapexPay/9f2c1d/callback       webhook (GET with ?snapex_return=…&token=… is the client coming back)
 *   GET  /state                                   checkouts, recorded payments, module log
 */

$dir = rtrim((string) getenv('SNAPEX_LIVE_DIR'), '/');
$public = rtrim((string) getenv('SNAPEX_LIVE_URL'), '/');
$keys = json_decode((string) @file_get_contents($dir.'/keys.json'), true);
if ($dir === '' || $public === '' || ! is_array($keys)) {
    http_response_code(500);
    exit("Set SNAPEX_LIVE_DIR (with keys.json) and SNAPEX_LIVE_URL.\n");
}
define('APP_URI', $public);

require __DIR__.'/wisecp_stubs.php';
require __DIR__.'/../src/coremio/modules/Payment/SnapexPay/SnapexPay.php';

wisecp_stub_boot();
$stateFile = $dir.'/state.json';
if (is_file($stateFile)) {
    $GLOBALS['wisecp'] = json_decode((string) file_get_contents($stateFile), true) + $GLOBALS['wisecp'];
}
$GLOBALS['wisecp']['settings'] = [
    'api_key' => $keys['api_key'], 'webhook_secret' => $keys['secret'], 'fee_on' => 'merchant', 'expires_minutes' => '60',
    'test_mode' => 1, 'partner_id' => '', 'api_url' => '',
];

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$out = null;
$status = 200;
$headers = ['Content-Type: application/json'];
if ($path === '/create') {
    $id = (string) (int) ($_GET['checkout'] ?? 1001);
    $amount = (string) ($_GET['amount'] ?? '12.50');
    wisecp_checkout($id, ['invoice_id' => 'L'.$id]);
    $GLOBALS['wisecp']['admin'] = true; // test mode: admins only
    $html = wisecp_area($id, $amount);
    $GLOBALS['wisecp']['admin'] = false;
    $out = ['pay_url' => preg_match('#href="([^"]+)"#', (string) $html, $m) ? html_entity_decode($m[1]) : null, 'html' => $html];
} elseif ($path === '/payment/SnapexPay/9f2c1d/callback') {
    $in = [];
    foreach ($_SERVER as $name => $value) {
        if (strpos($name, 'HTTP_') === 0) {
            $in[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        }
    }
    $res = wisecp_callback(['get' => $_GET, 'body' => (string) file_get_contents('php://input'), 'headers' => $in]);
    file_put_contents($dir.'/webhooks.log', gmdate('c').' '.$_SERVER['REQUEST_METHOD'].' '.json_encode(['status' => $res['status'], 'headers' => $res['headers'], 'result' => $res['result'],
        'body' => strlen($res['body']) > 200 ? substr($res['body'], 0, 200).'…' : $res['body']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
    $status = $res['status'];
    $headers = $res['headers'];
    $raw = $res['body'];
} elseif ($path === '/state') {
    $out = $GLOBALS['wisecp'];
    unset($out['settings']); // API key and webhook secret
} elseif (in_array($path, ['/pay/successful', '/pay/failed', '/pay/return'], true)) {
    $headers = ['Content-Type: text/plain'];
    $raw = 'WISECP page '.$path."\n";
} else {
    http_response_code(404);
    exit;
}

unset($GLOBALS['wisecp']['settings']);
file_put_contents($stateFile, json_encode($GLOBALS['wisecp'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
http_response_code($status);
foreach ($headers as $header) {
    header($header);
}
echo isset($raw) ? $raw : json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

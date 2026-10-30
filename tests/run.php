<?php
/**
 * Offline tests for the WISECP gateway: stub WISECP (payment module base, checkouts with custom data, module log)
 * and a fake SnapEX Pay API. Run: php integrations/wisecp/tests/run.php
 */

require __DIR__.'/wisecp_stubs.php';
require __DIR__.'/../src/coremio/modules/Payment/SnapexPay/SnapexPay.php';
wisecp_stub_boot();

use SnapexPayWisecp\Api;

// ---- fake SnapEX Pay API (same rules as the real one: Idempotency-Key replay, external_id used once, $1 minimum)
$GLOBALS['remote'] = [];
$GLOBALS['keys'] = [];
$GLOBALS['calls'] = [];
$GLOBALS['api_down'] = false;
Api::$transport = function ($method, $url, $headers, $body) {
    $GLOBALS['calls'][] = [$method, $url, $headers, $body];
    if ($GLOBALS['api_down']) {
        return [503, json_encode(['error' => ['code' => 'unavailable', 'message' => 'Service unavailable.']])];
    }
    if (! in_array('Authorization: Bearer sk_test', $headers, true)) {
        return [401, json_encode(['error' => ['code' => 'unauthorized', 'message' => 'A valid merchant API key is required.']])];
    }
    if ($method === 'POST') {
        $data = json_decode($body, true);
        $key = '';
        foreach ($headers as $h) {
            if (strpos($h, 'Idempotency-Key: ') === 0) {
                $key = substr($h, 17);
            }
        }
        if (isset($GLOBALS['keys'][$key])) {
            $old = $GLOBALS['remote'][$GLOBALS['keys'][$key]];
            if ($old['_request'] !== $data) {
                return [409, json_encode(['error' => ['code' => 'conflict', 'message' => 'Idempotency-Key was already used with a different request.']])];
            }

            return [200, json_encode(['data' => $old])];
        }
        foreach ($GLOBALS['remote'] as $old) {
            if ($old['external_id'] === $data['external_id']) {
                return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['external_id has already been used by this merchant.']]]])];
            }
        }
        if ((float) $data['amount_usd'] < 1) {
            return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['Minimum invoice is $1.']]]])];
        }
        $id = 'INV-'.(count($GLOBALS['remote']) + 1);
        $GLOBALS['keys'][$key] = $id;
        $GLOBALS['remote'][$id] = [
            'invoice_id' => $id, 'environment' => $data['environment'], 'amount_usd' => $data['amount_usd'],
            'status' => 'open', 'pay_url' => 'https://pay.snapex.pro/'.md5($id), 'external_id' => $data['external_id'],
            'expires_at' => gmdate('c', time() + $data['expires_in']), '_request' => $data,
        ];

        return [201, json_encode(['data' => $GLOBALS['remote'][$id]])];
    }
    $id = rawurldecode(basename($url));

    return isset($GLOBALS['remote'][$id])
        ? [200, json_encode(['data' => $GLOBALS['remote'][$id]])]
        : [404, json_encode(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']])];
};

$failures = 0;
function check($cond, $label)
{
    global $failures;
    echo ($cond ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $cond) {
        $failures++;
    }
}
function settings(array $s = [])
{
    $GLOBALS['wisecp']['settings'] = $s + $GLOBALS['base_settings'];
}
function payUrl($html)
{
    return preg_match('#href="([^"]+)"#', (string) $html, $m) ? html_entity_decode($m[1]) : null;
}
function lastRemote()
{
    return end($GLOBALS['remote']);
}
function remoteStatus($id, $status, array $extra = [])
{
    $GLOBALS['remote'][$id] = $extra + ['status' => $status] + $GLOBALS['remote'][$id];
    $GLOBALS['remote'][$id]['status'] = $status;
}
function webhook($type, $invoiceId, $secret = 'whsec_x', $ts = null)
{
    $ts = $ts === null ? (string) time() : (string) $ts;
    $body = json_encode(['id' => 'evt_'.mt_rand(), 'type' => $type, 'data' => ['invoice_id' => $invoiceId, 'status' => 'paid', 'amount_usd' => '999.00']]);

    return wisecp_callback(['body' => $body, 'headers' => ['Webhook-Timestamp' => $ts, 'Webhook-Signature' => 'v1='.hash_hmac('sha256', $ts.'.'.$body, $secret)]]);
}
function clientBack($successUrl)
{
    parse_str((string) parse_url($successUrl, PHP_URL_QUERY), $get);

    return wisecp_callback(['get' => $get]);
}
function location(array $res)
{
    foreach ($res['headers'] as $h) {
        if (stripos($h, 'Location: ') === 0) {
            return substr($h, 10);
        }
    }

    return null;
}
function state($checkoutId)
{
    return $GLOBALS['wisecp']['checkouts'][$checkoutId]['custom']['snapex_pay']['attempts'];
}

$GLOBALS['base_settings'] = ['api_key' => 'sk_test', 'webhook_secret' => 'whsec_x', 'fee_on' => 'merchant', 'expires_minutes' => '30', 'test_mode' => 0, 'partner_id' => '', 'api_url' => ''];
settings();
$dir = __DIR__.'/../src/coremio/modules/Payment/SnapexPay';

echo "module files\n";
$config = include $dir.'/config.php';
check($config['meta']['version'] === SnapexPay::VERSION && $config['meta']['name'] === 'SnapEX Pay (Crypto)', 'config.php: name and version match the class');
check(is_file($dir.'/'.$config['meta']['logo']), 'config.php: logo file exists');
check(isset($config['settings']['force_convert_to'], $config['settings']['commission_rate']) && $config['settings']['api_url'] === SnapexPay::DEFAULT_API_URL, 'config.php: standard settings + defaults');
$en = include $dir.'/lang/en.php';
foreach (['name', 'invoice-name', 'option-name', 'description', 'pay-button'] as $k) {
    check(isset($en[$k]) && $en[$k] !== '', "lang/en.php: mandatory key $k");
}
foreach (['tr', 'ru'] as $code) {
    $other = include $dir.'/lang/'.$code.'.php';
    check(array_keys($other) === array_keys($en), "lang/$code.php has the same keys as en");
}
$src = file_get_contents($dir.'/SnapexPay.php');
preg_match_all("/text\\('([a-z-]+)'\\)/", $src, $m);
check(! array_diff(array_unique($m[1]), array_keys($en)), 'every language key used in the code exists');

echo "settings page\n";
$g = new SnapexPay;
$fields = $g->config_fields();
check(array_keys($fields) === ['api_key', 'webhook_secret', 'fee_on', 'expires_minutes', 'test_mode', 'partner_id', 'api_url'], 'fields: key, secret, fee payer, window, test mode, Partner ID, API URL');
check(strpos($fields['webhook_secret']['description'], 'https://billing.host.example/payment/SnapexPay/9f2c1d/callback') !== false, 'webhook URL for the cabinet shown under the secret');
check($fields['api_key']['type'] === 'password' && $fields['webhook_secret']['type'] === 'password' && $fields['test_mode']['checked'] === false, 'secrets as password fields, test mode off');
check($fields['api_url']['value'] === SnapexPay::DEFAULT_API_URL && $fields['fee_on']['value'] === 'merchant', 'defaults');

echo "payment page\n";
wisecp_checkout(1001, ['invoice_id' => 501]);
$html = wisecp_area(1001, '45.00');
$inv = lastRemote();
$req = $inv['_request'];
check(payUrl($html) === $inv['pay_url'] && strpos($html, 'Pay with crypto') !== false, 'button opens the SnapEX payment page');
check((bool) preg_match('/^wisecp-[0-9a-f]{10}-1001-0-live-\d+$/', $req['external_id']) && $req['environment'] === 'live' && $req['expires_in'] === 1800
    && $req['fee_on'] === 'merchant' && $req['amount_usd'] === '45.00' && $req['description'] === 'Invoice #501', 'invoice request fields');
check(strpos($req['success_url'], 'https://billing.host.example/payment/SnapexPay/9f2c1d/callback?snapex_return='.$req['external_id'].'&token=') === 0, 'client returns to the callback link with a signed reference');
check($req['cancel_url'] === 'https://billing.host.example/pay/return', 'cancel goes back to WISECP');
check(! array_key_exists('partner_ref', $req), 'no Partner ID → no partner_ref');
check(end($GLOBALS['calls'])[2][3] === 'Idempotency-Key: '.$req['external_id'], 'Idempotency-Key is the external_id');
$st = state(1001);
check(isset($st[$inv['invoice_id']]) && $st[$inv['invoice_id']]['amount_usd'] === '45.00' && $st[$inv['invoice_id']]['status'] === 'open', 'attempt kept in the checkout custom data');
$again = wisecp_area(1001, '45.00');
check(payUrl($again) === payUrl($html) && count($GLOBALS['remote']) === 1, 'page reload reuses the open invoice');
$GLOBALS['wisecp']['checkouts'][1001]['custom'] = [];
$race = wisecp_area(1001, '45.00');
check(payUrl($race) === payUrl($html) && count($GLOBALS['remote']) === 1, 'parallel load (attempt not stored yet): same key, one SnapEX invoice');
$changed = wisecp_area(1001, '47.00');
check(payUrl($changed) !== payUrl($html) && count($GLOBALS['remote']) === 2 && strpos(lastRemote()['external_id'], '-1001-1-live-') !== false, 'amount changed → new attempt');
$GLOBALS['wisecp']['checkouts'][1001]['custom']['snapex_pay']['attempts'][lastRemote()['invoice_id']]['expires_at'] = time() + 120;
wisecp_area(1001, '47.00');
check(count($GLOBALS['remote']) === 3, 'invoice about to expire → a fresh one');

wisecp_checkout(1002);
$html = wisecp_area(1002, '40.00', 2);
check(lastRemote()['amount_usd'] === '50.00' && state(1002)[lastRemote()['invoice_id']]['currency'] === 'EUR', 'EUR converted to USD with WISECP rates');
check(lastRemote()['_request']['description'] === 'Order payment #1002', 'cart checkout: description by checkout');
$count = count($GLOBALS['remote']);
wisecp_checkout(1003);
$html = wisecp_area(1003, '400.00', 3);
check(payUrl($html) === null && strpos($html, 'TRY could not be converted') !== false && count($GLOBALS['remote']) === $count, 'currency without a rate: clear error, no invoice');
$html = wisecp_area(1003, '0.50');
check(strpos($html, 'Could not start the crypto payment: Minimum invoice is $1.') !== false, 'API validation message reaches the client');
settings(['api_key' => 'wrong']);
$html = wisecp_area(1003, '10.00');
check(strpos($html, 'A valid merchant API key is required.') !== false && strpos(json_encode($GLOBALS['wisecp']['log']), 'wrong') === false, 'bad API key explained; key never logged');
settings(['test_mode' => 1]);
$html = wisecp_area(1003, '20.00');
check(payUrl($html) === null && strpos($html, 'being set up') !== false && count($GLOBALS['remote']) === $count, 'test mode: clients get no button and no invoice');
$GLOBALS['wisecp']['admin'] = true;
$html = wisecp_area(1003, '20.00');
$GLOBALS['wisecp']['admin'] = false;
check(payUrl($html) !== null && lastRemote()['environment'] === 'test' && strpos($html, 'Test mode') !== false, 'test mode: a logged-in admin gets a test invoice');
settings(['partner_id' => ' int-42 ', 'fee_on' => 'customer', 'expires_minutes' => '99999']);
wisecp_checkout(1004);
wisecp_area(1004, '20.00');
$req = lastRemote()['_request'];
check(($req['partner_ref'] ?? null) === 'int-42' && $req['fee_on'] === 'customer' && $req['expires_in'] === 86400, 'Partner ID → partner_ref, client pays the fee, window capped at 24 h');
settings();
$GLOBALS['wisecp']['lang'] = 'tr';
wisecp_checkout(1005);
$html = wisecp_area(1005, '15.00');
$GLOBALS['wisecp']['lang'] = 'en';
check(strpos($html, 'Kripto ile öde') !== false, 'Turkish button');

echo "webhook\n";
wisecp_checkout(2001, ['invoice_id' => 601]);
wisecp_area(2001, '25.00');
$id = lastRemote()['invoice_id'];
check(webhook('invoice.paid', $id, 'whsec_wrong')['status'] === 401, 'wrong signature → 401');
check(webhook('invoice.paid', $id, 'whsec_x', time() - 3600)['status'] === 400, 'old timestamp → 400');
settings(['webhook_secret' => '']);
check(webhook('invoice.paid', $id)['status'] === 503, 'no webhook secret → 503');
settings();
$res = webhook('invoice.awaiting', $id);
check($res['status'] === 200 && $res['body'] === 'OK' && ! $GLOBALS['wisecp']['recorded'], 'invoice still open → 200, nothing recorded');
remoteStatus($id, 'paid', ['payment' => ['received_amount' => '25.03', 'symbol' => 'USDT', 'network' => 'TRON']]);
$GLOBALS['api_down'] = true;
check(webhook('invoice.paid', $id)['status'] === 502 && ! $GLOBALS['wisecp']['recorded'], 'API unreachable → 502 (SnapEX retries), nothing recorded');
$GLOBALS['api_down'] = false;
$res = webhook('invoice.paid', $id);
$rec = end($GLOBALS['wisecp']['recorded']);
check($res['status'] === 200 && $res['body'] === 'OK' && $rec['status'] === 'successful' && $rec['checkout'] === 2001, 'paid → WISECP records a successful payment, webhook gets OK');
check($rec['message'] === ['SnapEX Pay invoice' => $id, 'Paid with' => '25.03 USDT (TRON)'] && $GLOBALS['wisecp']['checkouts'][2001]['status'] === 'paid', 'payment note: SnapEX invoice ID and what was paid');
check($rec['transaction_id'] === $id && $rec['checkout_id'] === 2001, 'result names the checkout and gives the SnapEX invoice as transaction_id (WISECP 5 stores and dedupes by it)');
check(state(2001)[$id]['status'] === 'paid' && state(2001)[$id]['claimed_at'] > 0 && ! isset($GLOBALS['wisecp']['checkouts'][0]), 'webhook (no checkout set yet) saves the state on its own checkout, not on checkout 0');
$n = count($GLOBALS['wisecp']['recorded']);
$res = webhook('invoice.paid', $id);
check($res['status'] === 200 && count($GLOBALS['wisecp']['recorded']) === $n, 'repeated webhook → 200, no second payment');
check(webhook('payout.completed', 'PO-1')['body'] === 'OK' && webhook('invoice.paid', 'INV-404')['status'] === 200, 'other events and unknown invoices → 200');

wisecp_checkout(2002);
wisecp_area(2002, '30.00');
$id = lastRemote()['invoice_id'];
remoteStatus($id, 'paid');
$GLOBALS['remote'][$id]['amount_usd'] = '3.00';
check(webhook('invoice.paid', $id)['status'] === 200 && $GLOBALS['wisecp']['checkouts'][2002]['status'] === 'unpaid', 'amount differs from the checkout → nothing recorded');
$GLOBALS['remote'][$id]['amount_usd'] = '30.00';
$GLOBALS['remote'][$id]['external_id'] = 'wisecp-0000000000-2002-0-live-1';
check(webhook('invoice.paid', $id)['status'] === 200 && $GLOBALS['wisecp']['checkouts'][2002]['status'] === 'unpaid', 'invoice of another WISECP on the same account → ignored');
$GLOBALS['remote'][$id]['external_id'] = $GLOBALS['remote'][$id]['_request']['external_id'];

settings(['test_mode' => 1]);
$GLOBALS['wisecp']['admin'] = true;
wisecp_checkout(2003);
wisecp_area(2003, '12.00');
$GLOBALS['wisecp']['admin'] = false;
$id = lastRemote()['invoice_id'];
settings();
remoteStatus($id, 'paid');
check(webhook('invoice.paid', $id)['status'] === 200 && $GLOBALS['wisecp']['checkouts'][2003]['status'] === 'unpaid', 'test payment while test mode is off → not recorded');
settings(['test_mode' => 1]);
webhook('invoice.paid', $id);
$rec = end($GLOBALS['wisecp']['recorded']);
check($GLOBALS['wisecp']['checkouts'][2003]['status'] === 'paid' && $rec['message']['Environment'] === 'test', 'test payment in test mode → recorded, marked test');
settings();

wisecp_checkout(2004);
wisecp_area(2004, '50.00');
$id = lastRemote()['invoice_id'];
remoteStatus($id, 'partially_paid', ['payment' => ['received_amount' => '20', 'symbol' => 'USDT']]);
webhook('invoice.partially_paid', $id);
$rec = end($GLOBALS['wisecp']['recorded']);
check($rec['status'] === 'pending' && strpos($rec['message']['Note'], '20 USDT received') !== false, 'partially paid → pending for the admin, with the received amount');
$n = count($GLOBALS['wisecp']['recorded']);
webhook('invoice.partially_paid', $id);
check(count($GLOBALS['wisecp']['recorded']) === $n, 'partial reported once');
remoteStatus($id, 'partially_paid');
$res = webhook('invoice.extra_payment', $id);
check($res['status'] === 200 && strpos(end($GLOBALS['wisecp']['log'])[4], 'Extra payment') !== false && count($GLOBALS['wisecp']['recorded']) === $n, 'extra payment → module log only');

wisecp_checkout(2005);
wisecp_area(2005, '10.00');
$id = lastRemote()['invoice_id'];
remoteStatus($id, 'expired');
check(webhook('invoice.expired', $id)['status'] === 200 && $GLOBALS['wisecp']['checkouts'][2005]['status'] === 'unpaid' && state(2005)[$id]['status'] === 'expired', 'expired → nothing recorded, status kept');
wisecp_area(2005, '10.00');
check(lastRemote()['invoice_id'] !== $id, 'paying again after expiry → new invoice');

echo "client returns\n";
wisecp_checkout(3001, ['invoice_id' => 701]);
wisecp_area(3001, '19.99');
$inv = lastRemote();
$back = $inv['_request']['success_url'];
$res = clientBack($back);
check($res['status'] === 200 && strpos($res['body'], 'http-equiv="refresh"') !== false && strpos($res['body'], 'href="'.$inv['pay_url'].'"') !== false
    && strpos($res['body'], 'Last checked') !== false, 'not paid yet → page that re-checks every 10 s, with a link back to the payment page');
check(clientBack(str_replace('token=', 'token=x', $back))['status'] === 403, 'tampered return link → 403');
remoteStatus($inv['invoice_id'], 'paid');
$res = clientBack($back);
check($res['status'] === 302 && location($res) === 'https://billing.host.example/pay/successful' && end($GLOBALS['wisecp']['recorded'])['checkout'] === 3001
    && ! isset(end($GLOBALS['wisecp']['recorded'])['callback_message']), 'paid → recorded right away, client goes to WISECP "payment successful"');
$n = count($GLOBALS['wisecp']['recorded']);
$res = clientBack($back);
check(location($res) === 'https://billing.host.example/pay/successful' && count($GLOBALS['wisecp']['recorded']) === $n, 'reload after payment → successful page, nothing recorded twice');
check(webhook('invoice.paid', $inv['invoice_id'])['body'] === 'OK' && count($GLOBALS['wisecp']['recorded']) === $n, 'late webhook after the return page → no second payment');

wisecp_checkout(3002);
wisecp_area(3002, '19.99');
$inv = lastRemote();
remoteStatus($inv['invoice_id'], 'paid');
$GLOBALS['wisecp']['checkouts'][3002]['custom']['snapex_pay']['attempts'][$inv['invoice_id']]['claimed_at'] = time();
$res = clientBack($inv['_request']['success_url']);
check($res['status'] === 200 && strpos($res['body'], 'Status: paid') !== false, 'webhook is recording it right now → client waits a moment');
check(webhook('invoice.paid', $inv['invoice_id'])['status'] === 409, 'and the webhook is retried later (409)');
$GLOBALS['wisecp']['checkouts'][3002]['custom']['snapex_pay']['attempts'][$inv['invoice_id']]['claimed_at'] = time() - 600;
check(webhook('invoice.paid', $inv['invoice_id'])['body'] === 'OK' && $GLOBALS['wisecp']['checkouts'][3002]['status'] === 'paid', 'stale claim → recorded');

wisecp_checkout(3003);
wisecp_area(3003, '19.99');
$inv = lastRemote();
remoteStatus($inv['invoice_id'], 'expired');
$res = clientBack($inv['_request']['success_url']);
check(location($res) === 'https://billing.host.example/pay/failed', 'expired → WISECP "payment failed"');
wisecp_checkout(3004, ['invoice_id' => 702, 'redirect' => ['success' => 'https://billing.host.example/invoices/complete/702', 'failed' => 'https://billing.host.example/invoices/detail/702?pay=failed']]);
wisecp_area(3004, '19.99');
$inv = lastRemote();
remoteStatus($inv['invoice_id'], 'paid');
webhook('invoice.paid', $inv['invoice_id']);
$res = clientBack($inv['_request']['success_url']);
check(location($res) === 'https://billing.host.example/invoices/complete/702', 'paid by webhook, client back → the checkout\'s own success page (the invoice)');
$GLOBALS['api_down'] = true;
remoteStatus($inv['invoice_id'], 'open');
$res = clientBack($inv['_request']['success_url']);
$GLOBALS['api_down'] = false;
check($res['status'] === 200 && strpos($res['body'], 'http-equiv="refresh"') !== false, 'API unreachable on return → waiting page keeps re-checking');

echo "safety\n";
wisecp_checkout(4001);
$m = new ReflectionMethod('SnapexPay', 'button');
$out = $m->invoke(new SnapexPay, 'https://pay.snapex.pro/x" onmouseover="alert(1)');
check(strpos($out, '" onmouseover') === false && strpos($out, '&quot; onmouseover') !== false, 'pay URL HTML-escaped');
$log = json_encode($GLOBALS['wisecp']['log']);
check(strpos($log, 'sk_test') === false && strpos($log, 'whsec_x') === false, 'module log has no API key or webhook secret');
check(SnapexPay::parseExternalId('wisecp-0123456789-55-2-test-123') === ['0123456789', '55'] && SnapexPay::parseExternalId('blesta-x') === null, 'external_id parsing');

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);

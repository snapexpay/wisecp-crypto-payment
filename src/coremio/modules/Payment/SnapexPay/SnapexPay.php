<?php
/**
 * SnapEX Pay — crypto payments for WISECP (third-party payment gateway).
 *
 * The client pays on the SnapEX Pay page (USDT on several networks or other coins swapped to USDT).
 * The payment is recorded from the signed webhook or when the client comes back from the payment page,
 * and in both cases only after the invoice is read back from the SnapEX Pay API.
 *
 * @link https://pay.snapex.pro
 */

require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'Api.php';

use SnapexPayWisecp\Api;
use SnapexPayWisecp\ApiException;

class SnapexPay extends PaymentGatewayModule
{
    const VERSION = '1.0.2';

    const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    /** Webhook timestamps older than this are rejected (replay protection). */
    const WEBHOOK_TOLERANCE = 300;

    /** Key of the module's record in the checkout custom data. */
    const DATA_KEY = 'snapex_pay';

    /** Statuses the client can still pay. */
    const OPEN_STATUSES = ['open', 'awaiting'];

    /** A claim on a payment expires after this many seconds (crashed request); WISECP's unpaid check dedupes after that. */
    const CLAIM_TTL = 120;

    /** @var array|null test hook: ['get' => array, 'body' => string, 'headers' => array] instead of $_GET, php://input and $_SERVER */
    public static $request;

    /** @var callable|null test hook: fn(int $status, string $body, array $headers) instead of sending the response and exiting */
    public static $finish;

    public function __construct()
    {
        $this->name = __CLASS__;

        parent::__construct();
    }

    // ------------------------------------------------------------------ settings

    public function config_fields()
    {
        $s = isset($this->config['settings']) ? (array) $this->config['settings'] : [];

        return [
            'api_key' => [
                'name' => $this->text('api-key'),
                'description' => $this->text('api-key-desc'),
                'type' => 'password',
                'value' => isset($s['api_key']) ? $s['api_key'] : '',
                'placeholder' => 'smk_…',
            ],
            'webhook_secret' => [
                'name' => $this->text('webhook-secret'),
                'description' => sprintf($this->text('webhook-secret-desc'), $this->link('callback')),
                'type' => 'password',
                'value' => isset($s['webhook_secret']) ? $s['webhook_secret'] : '',
                'placeholder' => 'whsec_…',
            ],
            'fee_on' => [
                'name' => $this->text('fee-on'),
                'type' => 'dropdown',
                'options' => [
                    'merchant' => $this->text('fee-on-merchant'),
                    'customer' => $this->text('fee-on-customer'),
                ],
                'value' => isset($s['fee_on']) ? $s['fee_on'] : 'merchant',
            ],
            'expires_minutes' => [
                'name' => $this->text('expires-minutes'),
                'description' => $this->text('expires-minutes-desc'),
                'type' => 'text',
                'value' => isset($s['expires_minutes']) ? $s['expires_minutes'] : '60',
                'placeholder' => '60',
            ],
            'test_mode' => [
                'name' => $this->text('test-mode'),
                'description' => $this->text('test-mode-desc'),
                'type' => 'approval',
                'value' => 1,
                'checked' => $this->testMode(),
            ],
            'partner_id' => [
                'name' => $this->text('partner-id'),
                'description' => $this->text('partner-id-desc'),
                'type' => 'text',
                'value' => isset($s['partner_id']) ? $s['partner_id'] : '',
            ],
            'api_url' => [
                'name' => $this->text('api-url'),
                'description' => $this->text('api-url-desc'),
                'type' => 'text',
                'value' => isset($s['api_url']) && trim((string) $s['api_url']) !== '' ? $s['api_url'] : self::DEFAULT_API_URL,
            ],
        ];
    }

    // ------------------------------------------------------------------ payment page

    /**
     * Payment step of the checkout: creates (or reuses) a SnapEX Pay invoice for this checkout and returns the
     * button that opens it. The checkout keeps the list of SnapEX invoices in its custom data.
     */
    public function area($params = [])
    {
        $checkoutId = $this->checkoutId();
        $environment = $this->testMode() ? 'test' : 'live';
        if ($this->testMode() && ! self::isAdmin()) {
            return $this->box($this->text('test-mode-clients'));
        }

        $currency = strtoupper((string) $this->currency(isset($params['currency']) ? $params['currency'] : ''));
        $local = self::decimal(isset($params['amount']) ? $params['amount'] : 0);
        $usd = $this->toUsd($local, $currency, isset($params['currency']) ? $params['currency'] : null);
        if ($usd === null) {
            return $this->box(sprintf($this->text('error-currency'), $currency), true);
        }
        if ((float) $usd <= 0 || $checkoutId === '') {
            return $this->box($this->text('error-amount'), true);
        }

        $fingerprint = sha1(implode('|', [$checkoutId, $local, $currency, $usd, $environment]));
        $state = $this->state($checkoutId);
        foreach (array_reverse($state['attempts'], true) as $attempt) {
            if ($attempt['fingerprint'] === $fingerprint && in_array($attempt['status'], self::OPEN_STATUSES, true)
                && (int) $attempt['expires_at'] > time() + 300) {
                return $this->button($attempt['pay_url']);
            }
        }

        $minutes = isset($this->config['settings']['expires_minutes']) ? (int) $this->config['settings']['expires_minutes'] : 60;
        $minutes = max(1, min(1440, $minutes ?: 60));
        // external_id doubles as the Idempotency-Key. SnapEX rejects a reused external_id, so it changes with the
        // attempt number and minute: double loads collapse into one SnapEX invoice, a retry after expiry gets a new one.
        $externalId = self::externalId($checkoutId, count($state['attempts']), $environment, time());
        $data = [
            'amount_usd' => $usd,
            'fee_on' => $this->setting('fee_on') === 'customer' ? 'customer' : 'merchant',
            'environment' => $environment,
            'external_id' => $externalId,
            'description' => $this->description($checkoutId),
            'expires_in' => $minutes * 60,
        ];
        $back = $this->link('callback');
        if (preg_match('#^https?://#i', $back)) {
            $data['success_url'] = $back.(strpos($back, '?') === false ? '?' : '&')
                .http_build_query(['snapex_return' => $externalId, 'token' => $this->returnToken($externalId)]);
        }
        $cancel = $this->link('return') ?: $this->link('failed');
        if (preg_match('#^https?://#i', $cancel)) {
            $data['cancel_url'] = $cancel;
        }
        $partnerId = trim($this->setting('partner_id'));
        if ($partnerId !== '') {
            $data['partner_ref'] = $partnerId;
        }

        try {
            $created = $this->api()->createInvoice($data, $externalId);
        } catch (ApiException $e) {
            $this->log('createInvoice', $data, $e->getMessage());

            return $this->box(sprintf($this->text('error-create'), $e->getMessage()), true);
        }
        $this->log('createInvoice', $data, ['invoice_id' => isset($created['invoice_id']) ? $created['invoice_id'] : null]);
        if (empty($created['invoice_id']) || empty($created['pay_url'])) {
            return $this->box(sprintf($this->text('error-create'), 'empty response'), true);
        }

        $state = $this->state($checkoutId);
        $state['attempts'][(string) $created['invoice_id']] = [
            'external_id' => $externalId,
            'fingerprint' => $fingerprint,
            'amount' => $local,
            'currency' => $currency,
            'amount_usd' => $usd,
            'environment' => $environment,
            'pay_url' => (string) $created['pay_url'],
            'status' => isset($created['status']) ? (string) $created['status'] : 'open',
            'expires_at' => self::timestamp(isset($created['expires_at']) ? $created['expires_at'] : null),
            'claimed_at' => 0,
            'recorded' => false,
        ];
        $this->saveState($checkoutId, $state);

        return $this->button((string) $created['pay_url']);
    }

    /** wisecp-{site}-{checkout}-{attempt}-{environment}-{minute}; site: a short hash of the WISECP address. */
    public static function externalId($checkoutId, $attempt, $environment, $time)
    {
        return 'wisecp-'.self::siteHash().'-'.$checkoutId.'-'.(int) $attempt.'-'.$environment.'-'.intdiv((int) $time, 60);
    }

    /** @return array{0:string,1:string}|null [site hash, checkout ID] */
    public static function parseExternalId($externalId)
    {
        if (! preg_match('/^wisecp-([0-9a-f]{10})-([A-Za-z0-9]+)-\d+-(live|test)-\d+$/', (string) $externalId, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    private static function siteHash()
    {
        return substr(sha1(defined('APP_URI') ? (string) APP_URI : ''), 0, 10);
    }

    /**
     * USD amount for SnapEX: as is for USD, otherwise through WISECP's exchange rates. Null when that is not possible
     * (the README asks to set "convert to USD" for the gateway, then WISECP hands the amount over in USD already).
     */
    private function toUsd($amount, $currency, $currencyId)
    {
        if ($currency === 'USD') {
            return self::money($amount);
        }
        try {
            if (class_exists('Helper') && method_exists('Helper', 'Load')) {
                Helper::Load(['Money']);
            }
            if (! class_exists('Money') || ! method_exists('Money', 'Currency') || ! method_exists('Money', 'exChange')) {
                return null;
            }
            $usd = Money::Currency('USD');
            if (! is_array($usd) || empty($usd['id'])) {
                return null;
            }
            $converted = Money::exChange($amount, $currencyId, $usd['id']);
        } catch (\Throwable $e) {
            return null;
        }

        return is_numeric($converted) && (float) $converted > 0 ? self::money($converted) : null;
    }

    private function description($checkoutId)
    {
        $data = isset($this->checkout['data']) && is_array($this->checkout['data']) ? $this->checkout['data'] : [];
        if (! empty($data['invoice_id']) && is_scalar($data['invoice_id'])) {
            return 'Invoice #'.$data['invoice_id'];
        }

        return 'Order payment #'.$checkoutId;
    }

    private function button($payUrl)
    {
        $html = '<div class="snapexpay-pay" style="text-align:center;padding:16px 0">'
            .'<a class="btn btn-success gonderbtn yesilbtn" href="'.self::e($payUrl).'" rel="noopener" style="display:inline-block;padding:12px 28px;font-weight:600">'
            .self::e($this->text('pay-button')).'</a>';
        if ($this->testMode()) {
            $html .= '<div style="margin-top:8px;font-size:13px;color:#92400e">'.self::e($this->text('test-mode-note')).'</div>';
        }

        return $html.'</div>';
    }

    private function box($message, $error = false)
    {
        return '<div class="snapexpay-pay" style="text-align:center;padding:16px 0;'.($error ? 'color:#b91c1c' : 'color:#374151').'">'
            .self::e($message).'</div>';
    }

    // ------------------------------------------------------------------ notifications

    /**
     * WISECP calls this for the callback link: the signed SnapEX webhook (POST), or the client coming back from the
     * payment page (GET with snapex_return). Whatever triggered it, the invoice is read from the SnapEX API, and the
     * payment data comes from that response and from the record made in area(), never from the request.
     *
     * @return array|false payment result for WISECP; requests with nothing to record end here with their own response
     */
    public function callback()
    {
        list($get, $body, $headers) = self::incoming();
        if (isset($get['snapex_return'])) {
            return $this->clientReturn((string) $get['snapex_return'], isset($get['token']) ? (string) $get['token'] : '');
        }

        return $this->webhook($body, $headers);
    }

    private function webhook($body, array $headers)
    {
        $secret = trim($this->setting('webhook_secret'));
        if ($secret === '') {
            return $this->finish(503, 'Webhook secret is not set in the gateway settings.');
        }
        $timestamp = isset($headers['webhook-timestamp']) ? (string) $headers['webhook-timestamp'] : '';
        $signature = isset($headers['webhook-signature']) ? (string) $headers['webhook-signature'] : '';
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return $this->finish(400, 'Webhook timestamp is missing or too old.');
        }
        if (! hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature)) {
            return $this->finish(401, 'Webhook signature does not match the webhook secret.');
        }

        $event = json_decode($body, true);
        $type = is_array($event) && isset($event['type']) ? (string) $event['type'] : '';
        $snapexId = is_array($event) && isset($event['data']['invoice_id']) && is_scalar($event['data']['invoice_id']) ? (string) $event['data']['invoice_id'] : '';
        if (strpos($type, 'invoice.') !== 0 || $snapexId === '') {
            return $this->finish(200, 'OK'); // other events: nothing to do
        }

        try {
            $remote = $this->api()->invoice($snapexId);
        } catch (ApiException $e) {
            $this->log('getInvoice', ['invoice_id' => $snapexId, 'event' => $type], $e->getMessage());
            if ($e->getCode() === 404) {
                return $this->finish(200, 'OK'); // not an invoice of this API key: retrying will not help
            }

            return $this->finish(502, 'SnapEX Pay could not be reached to confirm the payment.'); // SnapEX retries
        }
        $found = $this->locate($remote);
        if (! $found) {
            return $this->finish(200, 'OK'); // the same merchant account may serve other shops
        }
        list($checkoutId, $attempt) = $found;
        if ($type === 'invoice.extra_payment') {
            $this->log('webhook', ['invoice_id' => $snapexId, 'event' => $type], 'Extra payment received for '.$snapexId.' — check the SnapEX Pay cabinet');

            return $this->finish(200, 'OK');
        }

        $result = $this->settle($checkoutId, $attempt, $remote);
        if (is_array($result)) {
            return $result + ['callback_message' => 'OK'];
        }

        return $this->finish($result === 'busy' ? 409 : 200, $result === 'busy' ? 'Busy, retry later.' : 'OK');
    }

    /** The client is back from the payment page. The token stops outsiders from using the link to poll the API. */
    private function clientReturn($externalId, $token)
    {
        $parsed = self::parseExternalId($externalId);
        if (! $parsed || ! hash_equals($this->returnToken($externalId), $token)) {
            return $this->finish(403, 'Invalid payment return link.');
        }
        $checkoutId = $parsed[1];
        $state = $this->state($checkoutId);
        $snapexId = null;
        foreach ($state['attempts'] as $id => $attempt) {
            if ($attempt['external_id'] === $externalId) {
                $snapexId = (string) $id;
            }
        }
        if ($snapexId === null) {
            return $this->redirect($this->link('failed'));
        }

        try {
            $remote = $this->api()->invoice($snapexId);
        } catch (ApiException $e) {
            $this->log('getInvoice', ['invoice_id' => $snapexId, 'event' => 'return'], $e->getMessage());

            return $this->waitingPage($state['attempts'][$snapexId], 'unreachable');
        }
        $found = $this->locate($remote);
        if (! $found) {
            return $this->redirect($this->link('failed'));
        }

        $result = $this->settle($checkoutId, $found[1], $remote);
        if (is_array($result)) {
            return $result;
        }
        $status = isset($remote['status']) ? (string) $remote['status'] : '';
        if ($result === 'recorded' && $status === 'paid') {
            return $this->redirect($this->link('successful'));
        }
        if ($status === 'expired' || $result === 'ignored' || $result === 'recorded') {
            return $this->redirect($this->link('failed'));
        }

        return $this->waitingPage($found[1], $status);
    }

    /**
     * Finds the checkout and the attempt a SnapEX invoice belongs to and checks it is the same payment.
     *
     * @return array{0:string,1:array}|null
     */
    private function locate(array $remote)
    {
        $snapexId = isset($remote['invoice_id']) ? (string) $remote['invoice_id'] : '';
        $externalId = isset($remote['external_id']) ? (string) $remote['external_id'] : '';
        $parsed = self::parseExternalId($externalId);
        if ($snapexId === '' || ! $parsed || $parsed[0] !== self::siteHash()) {
            return null;
        }
        $state = $this->state($parsed[1]);
        if (! isset($state['attempts'][$snapexId])) {
            return null;
        }
        $attempt = $state['attempts'][$snapexId] + ['invoice_id' => $snapexId];
        $remoteUsd = isset($remote['amount_usd']) ? self::money($remote['amount_usd']) : '';
        if ($attempt['external_id'] !== $externalId
            || (string) (isset($remote['environment']) ? $remote['environment'] : '') !== $attempt['environment']
            || $remoteUsd !== self::money($attempt['amount_usd'])) {
            $this->log('getInvoice', ['attempt' => $attempt], ['mismatch' => $remote]);

            return null;
        }

        return [$parsed[1], $attempt];
    }

    /**
     * Turns the SnapEX invoice into the WISECP payment result.
     *
     * @return array|string result for WISECP, or why there is none: 'waiting', 'recorded', 'ignored', 'busy'
     */
    private function settle($checkoutId, array $attempt, array $remote)
    {
        $snapexId = $attempt['invoice_id'];
        $status = isset($remote['status']) ? (string) $remote['status'] : '';
        $lock = self::lock($checkoutId);
        try {
            $state = $this->state($checkoutId);
            $row = $state['attempts'][$snapexId];
            $row['status'] = $status;
            $state['attempts'][$snapexId] = $row;
            $this->saveState($checkoutId, $state);

            if ($row['environment'] === 'test' && ! $this->testMode()) {
                $this->log('callback', ['invoice_id' => $snapexId], 'Test payment while test mode is off — not recorded');

                return 'ignored';
            }
            if (! in_array($status, ['paid', 'partially_paid'], true)) {
                return 'waiting'; // open, awaiting, underpaid, expired: nothing to record
            }
            if (! empty($row['recorded'])) {
                return 'recorded';
            }
            $checkout = $this->get_checkout($checkoutId, 'unpaid');
            if (! $checkout) {
                $row['recorded'] = true; // WISECP has it already
                $state['attempts'][$snapexId] = $row;
                $this->saveState($checkoutId, $state);

                return 'recorded';
            }
            // Webhook and return page can arrive together: only the request holding the claim reports the payment.
            if ((int) $row['claimed_at'] > time() - self::CLAIM_TTL) {
                return 'busy';
            }
            $row['claimed_at'] = time();
            if ($status === 'partially_paid') {
                $row['recorded'] = true; // reported once as pending; the admin decides
            }
            $state['attempts'][$snapexId] = $row;
            $this->saveState($checkoutId, $state);
        } finally {
            self::unlock($lock);
        }

        $this->set_checkout($checkout);
        $message = [$this->text('msg-invoice') => $snapexId];
        $payment = isset($remote['payment']) && is_array($remote['payment']) ? $remote['payment'] : [];
        if (isset($payment['received_amount']) && is_scalar($payment['received_amount'])) {
            $message[$this->text('msg-paid-with')] = trim($payment['received_amount'].' '.(isset($payment['symbol']) ? $payment['symbol'] : '')
                .(isset($payment['network']) && is_scalar($payment['network']) && $payment['network'] !== '' ? ' ('.$payment['network'].')' : ''));
        }
        if ($row['environment'] === 'test') {
            $message[$this->text('msg-environment')] = 'test';
        }
        $this->log('callback', ['invoice_id' => $snapexId, 'checkout' => $checkoutId], ['status' => $status]);

        // WISECP 5 stores transaction_id with the invoice payment (and dedupes by it); checkout_id names the checkout.
        $result = ['checkout_id' => self::checkoutKey($checkoutId), 'transaction_id' => $snapexId];
        if ($status === 'paid') {
            return $result + ['status' => 'successful', 'message' => $message];
        }
        $received = isset($message[$this->text('msg-paid-with')]) ? $message[$this->text('msg-paid-with')] : '?';
        $message['Note'] = sprintf($this->text('msg-partial'), $received);

        // Partially paid: pending, so the admin sees it and decides.
        return $result + ['status' => 'pending', 'message' => $message];
    }

    /** Status page for a client who came back before the payment was confirmed; it reloads itself. */
    private function waitingPage(array $attempt, $status)
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta http-equiv="refresh" content="10"><title>'.self::e($this->text('waiting-title')).'</title></head>'
            .'<body style="margin:0;background:#f3f4f6;color:#111827;font:16px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif">'
            .'<div style="max-width:480px;margin:12vh auto;padding:28px;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.12);text-align:center">'
            .'<h1 style="font-size:22px;margin:0 0 12px">'.self::e($this->text('waiting-title')).'</h1>'
            .'<p style="margin:0 0 12px">'.self::e($this->text('waiting-text')).'</p>'
            .'<p style="margin:0 0 4px;color:#4b5563;font-size:14px">'.self::e(sprintf($this->text('waiting-status'), $status !== '' ? $status : '—')).'</p>'
            .'<p style="margin:0 0 20px;color:#4b5563;font-size:14px">'.self::e(sprintf($this->text('waiting-checked'), gmdate('H:i:s'))).'</p>'
            .'<a href="'.self::e($attempt['pay_url']).'" style="display:inline-block;padding:10px 22px;background:#047857;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">'
            .self::e($this->text('waiting-open')).'</a>'
            .'</div></body></html>';

        return $this->finish(200, $html, ['Content-Type: text/html; charset=utf-8', 'Cache-Control: no-store']);
    }

    private function returnToken($externalId)
    {
        return hash_hmac('sha256', 'return|'.$externalId, $this->setting('api_key'));
    }

    // ------------------------------------------------------------------ checkout state

    /** @return array{attempts: array<string, array>} SnapEX invoices of a checkout, keyed by invoice ID */
    private function state($checkoutId)
    {
        $this->bind($checkoutId);
        $data = $this->get_custom_data($checkoutId);
        $state = is_array($data) && isset($data[self::DATA_KEY]) && is_array($data[self::DATA_KEY]) ? $data[self::DATA_KEY] : [];
        $attempts = [];
        foreach (isset($state['attempts']) && is_array($state['attempts']) ? $state['attempts'] : [] as $id => $row) {
            if (is_array($row) && isset($row['external_id'], $row['fingerprint'], $row['amount_usd'], $row['environment'], $row['pay_url'])) {
                $attempts[(string) $id] = $row + ['status' => 'open', 'expires_at' => 0, 'claimed_at' => 0, 'recorded' => false];
            }
        }

        return ['attempts' => $attempts];
    }

    private function saveState($checkoutId, array $state)
    {
        $this->bind($checkoutId);
        $data = $this->get_custom_data($checkoutId);
        $data = is_array($data) ? $data : [];
        $data[self::DATA_KEY] = $state;
        $this->save_custom_data($data, $checkoutId);
    }

    /**
     * Points the module at the checkout, freshly read. WISECP 5 keeps custom data on the module's current checkout
     * and saves it under $this->checkout_id, ignoring the ID argument: without this the webhook (no checkout set)
     * saved the state into checkout 0 and a parallel request read a stale copy. set_checkout() also takes the
     * checkout's own result pages (the invoice) for the client coming back.
     */
    private function bind($checkoutId)
    {
        $checkout = $this->get_checkout(self::checkoutKey($checkoutId));
        if (is_array($checkout) && $checkout) {
            $this->set_checkout($checkout);
        }
    }

    /** WISECP checkout IDs are numbers (an int property in WISECP 5). */
    private static function checkoutKey($checkoutId)
    {
        return ctype_digit((string) $checkoutId) ? (int) $checkoutId : (string) $checkoutId;
    }

    /** Serialises the read-check-write of a checkout's state between parallel requests on this server. */
    private static function lock($checkoutId)
    {
        $handle = @fopen(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'snapexpay-'.sha1(self::siteHash().'|'.$checkoutId).'.lock', 'c');
        if ($handle) {
            flock($handle, LOCK_EX);
        }

        return $handle;
    }

    private static function unlock($handle)
    {
        if ($handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function checkoutId()
    {
        if (isset($this->checkout_id) && is_scalar($this->checkout_id) && (string) $this->checkout_id !== '') {
            return (string) $this->checkout_id;
        }

        return isset($this->checkout['id']) && is_scalar($this->checkout['id']) ? (string) $this->checkout['id'] : '';
    }

    private function setting($name)
    {
        return isset($this->config['settings'][$name]) && is_scalar($this->config['settings'][$name]) ? (string) $this->config['settings'][$name] : '';
    }

    private function testMode()
    {
        return (bool) (int) $this->setting('test_mode');
    }

    /** An admin is logged in to the WISECP admin area in this browser. */
    private static function isAdmin()
    {
        if (! class_exists('UserManager') || ! method_exists('UserManager', 'LoginData')) {
            return false;
        }
        $admin = UserManager::LoginData('admin');

        return is_array($admin) && ! empty($admin['id']);
    }

    private function text($key)
    {
        return isset($this->lang[$key]) ? (string) $this->lang[$key] : $key;
    }

    private function link($name)
    {
        return isset($this->links[$name]) && is_string($this->links[$name]) ? $this->links[$name] : '';
    }

    private function api()
    {
        $url = trim($this->setting('api_url'));

        return new Api($url !== '' ? $url : self::DEFAULT_API_URL, $this->setting('api_key'));
    }

    /** Module log (WISECP: Tools → Logs → Module Log). Never gets the API key or the webhook secret. */
    private function log($action, $request, $response)
    {
        if (class_exists('Modules') && method_exists('Modules', 'save_log')) {
            Modules::save_log('Payment', __CLASS__, $action, $request, $response);
        }
    }

    private function redirect($url)
    {
        return $this->finish(302, '', ['Location: '.($url !== '' ? $url : '/')]);
    }

    /** Sends a response of our own and ends the request (WISECP has nothing to record). */
    private function finish($status, $body, array $headers = [])
    {
        if (self::$finish !== null) {
            return call_user_func(self::$finish, $status, $body, $headers);
        }
        if (! headers_sent()) {
            http_response_code($status);
            foreach ($headers as $header) {
                header($header);
            }
        }
        echo $body;
        exit;
    }

    /** @return array{0:array,1:string,2:array} query, raw body and lower-cased headers */
    private static function incoming()
    {
        if (self::$request !== null) {
            return [
                isset(self::$request['get']) ? (array) self::$request['get'] : [],
                isset(self::$request['body']) ? (string) self::$request['body'] : '',
                array_change_key_case(isset(self::$request['headers']) ? (array) self::$request['headers'] : [], CASE_LOWER),
            ];
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }

        return [$_GET, (string) file_get_contents('php://input'), $headers];
    }

    private static function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /** "12.5" → "12.50": the same amount always gives the same string. */
    public static function money($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private static function decimal($amount)
    {
        return number_format(round((float) $amount, 4), 4, '.', '');
    }

    private static function timestamp($iso)
    {
        $ts = is_string($iso) && $iso !== '' ? strtotime($iso) : false;

        return $ts === false ? 0 : $ts;
    }
}

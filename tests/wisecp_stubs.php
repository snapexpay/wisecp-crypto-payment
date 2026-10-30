<?php
/**
 * Minimal stand-ins for the WISECP classes the gateway touches, following the WISECP developer docs
 * (payment gateway development: module structure, third-party method, callback, checkout, keeping custom data)
 * and the official sample module github.com/wisecp-v3/Third-Party-Payment-Module.
 *
 * State lives in $GLOBALS['wisecp']: settings, checkouts (with custom data), payments WISECP recorded, module log.
 */

if (! defined('APP_URI')) {
    define('APP_URI', 'https://billing.host.example');
}

function wisecp_stub_boot()
{
    $GLOBALS['wisecp'] = [
        'settings' => [],
        'checkouts' => [],
        'recorded' => [],
        'log' => [],
        'admin' => false,
        'currencies' => [1 => ['id' => 1, 'code' => 'USD', 'rate' => 1.0], 2 => ['id' => 2, 'code' => 'EUR', 'rate' => 0.8], 3 => ['id' => 3, 'code' => 'TRY', 'rate' => 0.0]],
        'lang' => 'en',
    ];
}

/** Base class of payment modules (coremio/classes/PaymentGatewayModule.php): config, language, links, checkout. */
class PaymentGatewayModule
{
    public $name;
    public $config = [];
    public $lang = [];
    public $links = [];
    public $checkout_id;
    public $checkout;
    public $error;

    public function __construct()
    {
        $dir = dirname((new ReflectionClass($this))->getFileName());
        $this->config = include $dir.'/config.php';
        $this->config['settings'] = $GLOBALS['wisecp']['settings'] + $this->config['settings'];
        $file = $dir.'/lang/'.$GLOBALS['wisecp']['lang'].'.php';
        $this->lang = include(is_file($file) ? $file : $dir.'/lang/en.php');
        $this->links = [
            'successful' => APP_URI.'/pay/successful',
            'failed' => APP_URI.'/pay/failed',
            'return' => APP_URI.'/pay/return',
            'callback' => APP_URI.'/payment/'.$this->name.'/9f2c1d/callback',
        ];
    }

    /** Currency ID → ISO code. */
    public function currency($id)
    {
        return isset($GLOBALS['wisecp']['currencies'][$id]) ? $GLOBALS['wisecp']['currencies'][$id]['code'] : '';
    }

    /**
     * Checkout by ID; with 'unpaid' / 'paid' only a checkout in that state. As in WISECP 5, the first checkout read
     * becomes the module's current checkout.
     */
    public function get_checkout($id, $status = '')
    {
        $checkout = isset($GLOBALS['wisecp']['checkouts'][$id]) ? $GLOBALS['wisecp']['checkouts'][$id] : null;
        if (! $checkout || ($status !== '' && $checkout['status'] !== $status)) {
            return false;
        }
        if (! $this->checkout) {
            $this->checkout = $checkout;
        }

        return $checkout;
    }

    /** Makes the checkout current; its own result pages (data.redirect, the invoice) replace the generic ones. */
    public function set_checkout($checkout)
    {
        $this->checkout = $checkout;
        $this->checkout_id = $checkout['id'];
        $redirect = isset($checkout['data']['redirect']) ? $checkout['data']['redirect'] : [];
        foreach (['success' => 'successful', 'failed' => 'failed', 'return' => 'return'] as $from => $to) {
            if (isset($redirect[$from])) {
                $this->links[$to] = $redirect[$from];
            }
        }
    }

    /**
     * WISECP 5 semantics: custom data lives on the module's current checkout and is saved under $this->checkout_id;
     * the ID argument only loads a checkout when none is current (a module without set_checkout() saves into 0).
     */
    public function save_custom_data($data = [], $checkout_id = 0)
    {
        if (! $this->checkout) {
            $this->get_checkout($checkout_id);
        }
        $this->checkout['custom'] = $data;
        $GLOBALS['wisecp']['checkouts'][(int) $this->checkout_id]['custom'] = $data;
    }

    public function get_custom_data($checkout_id = 0)
    {
        if (! $this->checkout) {
            $this->get_checkout($checkout_id);
        }

        return isset($this->checkout['custom']) ? $this->checkout['custom'] : false;
    }
}

class Modules
{
    public static function save_log($type = '', $module = '', $action = '', $request = '', $response = '', $processed = '')
    {
        $GLOBALS['wisecp']['log'][] = [$type, $module, $action, json_encode($request), is_string($response) ? $response : json_encode($response)];
    }
}

class UserManager
{
    public static function LoginData($type = 'member')
    {
        return $type === 'admin' && $GLOBALS['wisecp']['admin'] ? ['id' => 1] : false;
    }
}

class Helper
{
    public static function Load($names)
    {
    }
}

class Money
{
    /** Currency record by ID or code. */
    public static function Currency($idOrCode)
    {
        foreach ($GLOBALS['wisecp']['currencies'] as $row) {
            if ($row['id'] === $idOrCode || $row['code'] === $idOrCode) {
                return $row;
            }
        }

        return false;
    }

    /** Converts between currency IDs with the stored rates (rate = units per 1 USD). */
    public static function exChange($amount, $from, $to)
    {
        $a = self::Currency($from);
        $b = self::Currency($to);
        if (! $a || ! $b || $a['rate'] <= 0 || $b['rate'] <= 0) {
            return 0;
        }

        return round($amount / $a['rate'] * $b['rate'], 2);
    }
}

/** Opens a checkout the way the cart / invoice payment does. */
function wisecp_checkout($id, array $data = [])
{
    $GLOBALS['wisecp']['checkouts'][$id] = ['id' => $id, 'status' => 'unpaid', 'data' => $data, 'custom' => []];
}

/** The payment step: WISECP builds the module for the checkout and renders area(). */
function wisecp_area($checkoutId, $amount, $currencyId = 1)
{
    $module = new SnapexPay;
    $module->set_checkout($GLOBALS['wisecp']['checkouts'][$checkoutId]);

    return $module->area(['amount' => $amount, 'currency' => $currencyId]);
}

class StubFinished extends Exception
{
    public $status;
    public $body;
    public $headers;

    public function __construct($status, $body, array $headers)
    {
        parent::__construct('finished '.$status);
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }
}

/**
 * The callback link: WISECP calls callback(); a returned array is applied to the module's current checkout, or to
 * the result's checkout_id ('successful' → paid, 'pending' → waiting for approval). A response the module sends
 * itself ends the request.
 *
 * @return array{status:int,body:string,headers:array,result:mixed}
 */
function wisecp_callback(array $request)
{
    SnapexPay::$request = $request;
    SnapexPay::$finish = function ($status, $body, array $headers) {
        throw new StubFinished($status, $body, $headers);
    };
    $module = new SnapexPay;
    try {
        $result = $module->callback();
    } catch (StubFinished $f) {
        SnapexPay::$request = null;

        return ['status' => $f->status, 'body' => $f->body, 'headers' => $f->headers, 'result' => null];
    }
    SnapexPay::$request = null;
    if (! is_array($result)) {
        return ['status' => 200, 'body' => (string) $module->error, 'headers' => [], 'result' => $result];
    }
    $id = ! empty($module->checkout['id']) ? $module->checkout['id'] : (isset($result['checkout_id']) ? $result['checkout_id'] : 0);
    $GLOBALS['wisecp']['checkouts'][$id]['status'] = $result['status'] === 'successful' ? 'paid' : 'pending';
    $GLOBALS['wisecp']['recorded'][] = ['checkout' => $id] + $result;
    if (isset($result['callback_message'])) {
        return ['status' => 200, 'body' => $result['callback_message'], 'headers' => [], 'result' => $result];
    }

    return ['status' => 302, 'body' => '', 'headers' => ['Location: '.APP_URI.($result['status'] === 'successful' ? '/pay/successful' : '/pay/failed')], 'result' => $result];
}

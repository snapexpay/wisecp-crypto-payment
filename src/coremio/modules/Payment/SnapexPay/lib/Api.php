<?php

namespace SnapexPayWisecp;

/** Minimal SnapEX Pay merchant API client (invoices only). */
class Api
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var callable|null test hook: fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    public static $transport;

    public function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = trim((string) $apiKey);
    }

    /** @return array invoice */
    public function createInvoice(array $data, $idempotencyKey)
    {
        return $this->request('POST', '/invoices', $data, ['Idempotency-Key: '.$idempotencyKey]);
    }

    /** @return array invoice */
    public function invoice($invoiceId)
    {
        return $this->request('GET', '/invoices/'.rawurlencode((string) $invoiceId));
    }

    private function request($method, $path, $body = null, array $extraHeaders = [])
    {
        if ($this->apiKey === '') {
            throw new ApiException('SnapEX Pay API key is not set in the gateway settings.');
        }
        $headers = array_merge([
            'Authorization: Bearer '.$this->apiKey,
            'Accept: application/json',
            'User-Agent: SnapEX-Pay-WISECP/'.\SnapexPay::VERSION,
        ], $extraHeaders);
        $json = null;
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $url = $this->baseUrl.$path;

        if (self::$transport !== null) {
            list($status, $raw) = call_user_func(self::$transport, $method, $url, $headers, $json);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($json !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new ApiException('SnapEX Pay is unreachable: '.$error);
            }
        }

        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            throw new ApiException(self::errorMessage($decoded, $status), $status);
        }
        if (! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            throw new ApiException('Unexpected SnapEX Pay response (HTTP '.$status.').', $status);
        }

        return $decoded['data'];
    }

    private static function errorMessage($decoded, $status)
    {
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $details = isset($decoded['error']['details']) && is_array($decoded['error']['details']) ? $decoded['error']['details'] : [];
            foreach ($details as $messages) {
                $first = is_array($messages) ? reset($messages) : $messages;
                if (is_string($first) && $first !== '') {
                    return $first;
                }
            }
            if (isset($decoded['error']['message'])) {
                return (string) $decoded['error']['message'];
            }
        }

        return 'SnapEX Pay returned HTTP '.$status.'.';
    }
}

class ApiException extends \RuntimeException
{
}

<?php
return [
    'meta' => [
        'name' => 'SnapEX Pay (Crypto)',
        'version' => '1.0.2',
        'logo' => 'logo.png',
    ],
    'settings' => [
        'api_key' => '',
        'webhook_secret' => '',
        'fee_on' => 'merchant',
        'expires_minutes' => '60',
        'test_mode' => 0,
        'partner_id' => '',
        'api_url' => 'https://snapex.pro/api/v1/merchant',
        'commission_rate' => 0,
        // SnapEX Pay invoices are in USD. Set this to the WISECP currency ID of USD to have WISECP convert
        // the amount before the payment page; otherwise the module converts with WISECP exchange rates.
        'force_convert_to' => 0,
        'accepted_countries' => [],
        'unaccepted_countries' => [],
    ],
];

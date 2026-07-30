<?php

return [

    'currency' => 'PKR',

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),

    'sandbox' => env('BILLING_SANDBOX', env('APP_ENV') === 'local'),

    'frontend_billing_url' => env('FRONTEND_URL', 'http://localhost:3000').'/billing.html',

    'plans' => [
        'monthly' => [
            'id' => 'monthly',
            'name' => 'Monthly',
            'amount' => 500,
            'interval' => 'month',
            'interval_count' => 1,
            'description' => 'Cash flow tracking & reports — billed monthly',
        ],
        'yearly' => [
            'id' => 'yearly',
            'name' => 'Yearly',
            'amount' => 5400,
            'interval' => 'year',
            'interval_count' => 1,
            'description' => 'Save 10% — PKR 5,400/year (vs PKR 6,000)',
        ],
        'receipt_addon' => [
            'id' => 'receipt_addon',
            'name' => 'Receipt scanner add-on',
            'amount' => 500,
            'interval' => 'month',
            'interval_count' => 1,
            'description' => 'Optional receipt OCR add-on (M8 scaffold)',
            'requires_base' => true,
        ],
    ],

    'providers' => [
        'jazzcash' => [
            'label' => 'JazzCash',
            'merchant_id' => env('JAZZCASH_MERCHANT_ID'),
            'password' => env('JAZZCASH_PASSWORD'),
            'integrity_salt' => env('JAZZCASH_INTEGRITY_SALT'),
            'return_url' => env('JAZZCASH_RETURN_URL', env('APP_URL').'/api/billing/jazzcash/return'),
            'ipn_url' => env('JAZZCASH_IPN_URL', env('APP_URL').'/api/billing/jazzcash/ipn'),
            'checkout_url' => env(
                'JAZZCASH_CHECKOUT_URL',
                'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform'
            ),
        ],
        'easypaisa' => [
            'label' => 'EasyPaisa',
            'store_id' => env('EASYPAISA_STORE_ID'),
            'hash_key' => env('EASYPAISA_HASH_KEY'),
            'return_url' => env('EASYPAISA_RETURN_URL', env('APP_URL').'/api/billing/easypaisa/return'),
            'ipn_url' => env('EASYPAISA_IPN_URL', env('APP_URL').'/api/billing/easypaisa/ipn'),
            'checkout_url' => env(
                'EASYPAISA_CHECKOUT_URL',
                'https://easypay.easypaisa.com.pk/easypay-service/rest/v4/initiate-ma-transaction'
            ),
        ],
    ],

];

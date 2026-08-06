<?php

return [

    // Paddle settles in USD and does not support PKR as a charge currency.
    // Switching away from the JazzCash/EasyPaisa wallets means switching the
    // product to international card payments — see docs/milestones/M23-paddle-billing.md.
    'currency' => env('BILLING_CURRENCY', 'USD'),

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),

    // Local test mode. When on, checkout skips Paddle entirely and the
    // authenticated test-activation endpoint marks payments complete, so local
    // dev and the Playwright harness never need live credentials. Must be
    // turned on explicitly: a publicly reachable instance with this on lets
    // anyone grant themselves a subscription.
    'sandbox' => (bool) env('BILLING_SANDBOX', false),

    'frontend_billing_url' => env('FRONTEND_URL', 'http://localhost:3000').'/billing.html',

    // Display amounts only. Paddle is the source of truth for what is actually
    // charged — these must be kept in step with the prices behind the price IDs
    // below, or the UI will quote a number the checkout does not honour.
    'plans' => [
        'monthly' => [
            'id' => 'monthly',
            'name' => 'Monthly',
            'amount' => (float) env('BILLING_AMOUNT_MONTHLY', 5),
            'interval' => 'month',
            'interval_count' => 1,
            'description' => 'Cash flow tracking & reports — billed monthly',
            'price_id' => env('PADDLE_PRICE_MONTHLY'),
        ],
        'yearly' => [
            'id' => 'yearly',
            'name' => 'Yearly',
            'amount' => (float) env('BILLING_AMOUNT_YEARLY', 54),
            'interval' => 'year',
            'interval_count' => 1,
            'description' => 'Save 10% — two months free versus monthly',
            'price_id' => env('PADDLE_PRICE_YEARLY'),
        ],
        'receipt_addon' => [
            'id' => 'receipt_addon',
            'name' => 'Receipt scanner add-on',
            'amount' => (float) env('BILLING_AMOUNT_RECEIPT_ADDON', 5),
            'interval' => 'month',
            'interval_count' => 1,
            'description' => 'Optional receipt OCR add-on (M8 scaffold)',
            'requires_base' => true,
            'price_id' => env('PADDLE_PRICE_RECEIPT_ADDON'),
        ],
    ],

    'paddle' => [
        'label' => 'Paddle',

        // 'sandbox' or 'production'. Drives which API host and which set of
        // price IDs Paddle expects — sandbox price IDs are not valid in prod.
        'environment' => env('PADDLE_ENV', 'sandbox'),

        'api_key' => env('PADDLE_API_KEY'),

        // Notification-setting secret (pdl_ntfset_…). Webhook verification
        // fails closed when this is unset.
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),

        // Reject webhooks whose signed timestamp is older than this, to blunt
        // replay attempts. Seconds.
        'webhook_tolerance' => (int) env('PADDLE_WEBHOOK_TOLERANCE', 300),

        // Approved payment-link domain. Leave null to use the default payment
        // link configured in the Paddle dashboard; Paddle appends ?_ptxn=<id>.
        'checkout_url' => env('PADDLE_CHECKOUT_URL'),

        'api_timeout' => (int) env('PADDLE_API_TIMEOUT', 15),
    ],

];

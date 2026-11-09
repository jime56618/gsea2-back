<?php

return [
    'prices' => [
        'pro' => env('STRIPE_PRICE_PRO'),
        'simple' => env('STRIPE_PRICE_SIMPLE'),
        'crm' => env('STRIPE_PRICE_CRM'),
    ],

    'manual_prices' => [
        'pro' => env('STRIPE_PRICE_MANUAL_PRO'),
        'simple' => env('STRIPE_PRICE_MANUAL_SIMPLE'),
        'crm' => env('STRIPE_PRICE_MANUAL_CRM'),
    ],
];
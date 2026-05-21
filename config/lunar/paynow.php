<?php

return [

    'api_key' => env('PAYNOW_API_KEY'),

    'signature_key' => env('PAYNOW_SIGNATURE_KEY'),

    'sandbox' => env('PAYNOW_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Notification Path
    |--------------------------------------------------------------------------
    |
    | The URL path where PayNow sends payment status notifications (webhooks).
    | You must configure this full URL in your PayNow merchant dashboard
    | under PoS settings → Notification URL.
    |
    | Example: https://yoursite.com/paynow/notification
    |
    */
    'notification_path' => env('PAYNOW_NOTIFICATION_PATH', 'paynow/notification'),

    /*
    |--------------------------------------------------------------------------
    | Payment Description
    |--------------------------------------------------------------------------
    |
    | Default description shown to the customer on the PayNow payment page.
    | Individual payments can override this via the `description` data key.
    |
    */
    'description' => env('PAYNOW_PAYMENT_DESCRIPTION', 'Order payment'),

    /*
    |--------------------------------------------------------------------------
    | Validity Time
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) a payment session remains valid before expiring.
    | Accepted range: 60–864000 (1 minute to 10 days). Default: 1 hour.
    |
    */
    'validity_time' => env('PAYNOW_VALIDITY_TIME', 3600),

    /*
    |--------------------------------------------------------------------------
    | Order Status Mapping
    |--------------------------------------------------------------------------
    |
    | Maps PayNow payment statuses to your Lunar order status slugs.
    | Adjust these to match the statuses configured in your Lunar installation.
    |
    */
    'status_mapping' => [
        'CONFIRMED' => env('PAYNOW_STATUS_CONFIRMED', 'payment-received'),
        'REJECTED' => env('PAYNOW_STATUS_REJECTED', 'payment-failed'),
        'ABANDONED' => env('PAYNOW_STATUS_ABANDONED', 'payment-failed'),
        'EXPIRED' => env('PAYNOW_STATUS_EXPIRED', 'payment-failed'),
        'ERROR' => env('PAYNOW_STATUS_ERROR', 'payment-failed'),
    ],

];

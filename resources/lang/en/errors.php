<?php

return [

    'admin' => [
        'verification_failed' => 'PayNow signature verification failed. Check your PAYNOW_SIGNATURE_KEY configuration.',
        'unauthorized' => 'PayNow API authentication failed. Check your PAYNOW_API_KEY configuration.',

        'system_temporarily_unavailable' => 'PayNow is temporarily unavailable. Please try again later.',

        'payment_amount_too_small' => 'Payment amount is below the minimum (1.00 PLN).',
        'payment_amount_too_large' => 'Payment amount exceeds the maximum allowed.',

        'payment_method_not_available' => 'The selected payment method is not available.',

        'authorization_code_expired' => 'BLIK code has expired.',
        'authorization_code_invalid' => 'Invalid BLIK code.',
        'authorization_code_used' => 'This BLIK code has already been used.',

        'refund_possibility_expired' => 'Refund not possible — payment is older than 6 months.',
        'insufficient_balance_funds' => 'Insufficient merchant balance for refund. Enable the awaiting refunds feature in the PayNow merchant panel.',
        'insufficient_card_balance_funds' => 'Insufficient card balance for refund. Enable the awaiting refunds feature in the PayNow merchant panel.',
        'refund_amount_too_small' => 'Refund amount is below the minimum allowed by PayNow.',
        'refund_amount_too_large' => 'Refund amount exceeds the available refundable balance.',

        'refund_record_not_found' => 'PayNow payment record not found for this transaction.',
        'refund_not_confirmed' => 'Refunds can only be issued against CONFIRMED payments (current status: :status).',
        'refund_exceeds_balance' => 'Refund amount (:amount) exceeds refundable balance (:available).',
        'refund_not_cancellable' => 'Refund :refund_id cannot be cancelled (status: :status). Only NEW refunds can be cancelled.',
        'missing_buyer_email' => 'Cannot create PayNow payment: no buyer email on billing address or customer account.',

        'not_found' => 'PayNow resource not found.',
        'validation_error' => 'PayNow request validation failed: :message',
        'generic' => 'PayNow API error: :message',
    ],

    'customer' => [
        'system_temporarily_unavailable' => 'Payment service is temporarily unavailable. Please try again in a few minutes.',
        'payment_amount_too_small' => 'The payment amount is too small.',
        'payment_amount_too_large' => 'The payment amount is too large.',
        'payment_method_not_available' => 'The selected payment method is not available. Please choose another.',
        'authorization_code_expired' => 'Your BLIK code has expired. Please generate a new code and try again.',
        'authorization_code_invalid' => 'Invalid BLIK code. Please check and try again.',
        'authorization_code_used' => 'This BLIK code has already been used. Please generate a new code.',
        'generic' => 'Payment failed. Please try again or choose a different payment method.',
    ],

];

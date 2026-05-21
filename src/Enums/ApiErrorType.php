<?php

namespace Bpotmalnik\LunarPaynow\Enums;

enum ApiErrorType: string
{
    case AuthorizationCodeExpired = 'AUTHORIZATION_CODE_EXPIRED';
    case AuthorizationCodeInvalid = 'AUTHORIZATION_CODE_INVALID';
    case AuthorizationCodeUsed = 'AUTHORIZATION_CODE_USED';
    case InsufficientBalanceFunds = 'INSUFFICIENT_BALANCE_FUNDS';
    case InsufficientCardBalanceFunds = 'INSUFFICIENT_CARD_BALANCE_FUNDS';
    case NotFound = 'NOT_FOUND';
    case PaymentAmountTooLarge = 'PAYMENT_AMOUNT_TOO_LARGE';
    case PaymentAmountTooSmall = 'PAYMENT_AMOUNT_TOO_SMALL';
    case PaymentMethodNotAvailable = 'PAYMENT_METHOD_NOT_AVAILABLE';
    case RefundAmountTooLarge = 'REFUND_AMOUNT_TOO_LARGE';
    case RefundAmountTooSmall = 'REFUND_AMOUNT_TOO_SMALL';
    case RefundPossibilityExpired = 'REFUND_POSSIBILITY_EXPIRED';
    case SystemTemporarilyUnavailable = 'SYSTEM_TEMPORARILY_UNAVAILABLE';
    case Unauthorized = 'UNAUTHORIZED';
    case ValidationError = 'VALIDATION_ERROR';
    case VerificationFailed = 'VERIFICATION_FAILED';

    public function isCustomerSafe(): bool
    {
        return match ($this) {
            self::SystemTemporarilyUnavailable,
            self::PaymentAmountTooSmall,
            self::PaymentAmountTooLarge,
            self::PaymentMethodNotAvailable,
            self::AuthorizationCodeExpired,
            self::AuthorizationCodeInvalid,
            self::AuthorizationCodeUsed => true,
            default => false,
        };
    }

    public function translationKey(): string
    {
        return strtolower($this->value);
    }

    public function customerMessage(): string
    {
        if (! $this->isCustomerSafe()) {
            return trans('lunar-paynow::errors.customer.generic');
        }

        return trans("lunar-paynow::errors.customer.{$this->translationKey()}");
    }

    public function adminMessage(string $rawMessage = ''): string
    {
        $key = "lunar-paynow::errors.admin.{$this->translationKey()}";

        return trans()->has($key)
            ? trans($key, ['message' => $rawMessage])
            : trans('lunar-paynow::errors.admin.generic', ['message' => $rawMessage ?: $this->value]);
    }
}

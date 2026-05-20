<?php

namespace Bpotmalnik\LunarPaynow\Enums;

enum PaymentStatus: string
{
    case New = 'NEW';
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case Abandoned = 'ABANDONED';
    case Expired = 'EXPIRED';
    case Error = 'ERROR';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed,
            self::Rejected,
            self::Abandoned,
            self::Expired,
            self::Error => true,
            default     => false,
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Confirmed;
    }
}

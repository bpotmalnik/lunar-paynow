<?php

namespace Bpotmalnik\LunarPaynow\Enums;

enum RefundReason: string
{
    case Rma = 'RMA';
    case RefundBefore14 = 'REFUND_BEFORE_14';
    case RefundAfter14 = 'REFUND_AFTER_14';
    case Other = 'OTHER';
}

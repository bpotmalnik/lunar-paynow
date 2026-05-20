<?php

namespace Bpotmalnik\LunarPaynow\Enums;

enum RefundStatus: string
{
    case New = 'NEW';
    case Pending = 'PENDING';
    case Successful = 'SUCCESSFUL';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}

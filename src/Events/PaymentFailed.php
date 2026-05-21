<?php

namespace Bpotmalnik\LunarPaynow\Events;

use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;

class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaynowPayment $paynowPayment,
        public readonly Order $order,
    ) {}
}

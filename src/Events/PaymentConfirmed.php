<?php

namespace Bpotmalnik\LunarPaynow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;

class PaymentConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaynowPayment $paynowPayment,
        public readonly Order $order,
    ) {}
}

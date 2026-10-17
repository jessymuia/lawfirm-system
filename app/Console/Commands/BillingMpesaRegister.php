<?php

namespace App\Console\Commands;

use App\Billing\BillingException;
use App\Billing\Collection;
use Illuminate\Console\Command;

/** PLAT-4: register the paybill's confirmation URL with Safaricom (once per shortcode, and after the platform host changes). */
class BillingMpesaRegister extends Command
{
    protected $signature = 'billing:mpesa-register';

    protected $description = 'Register the platform paybill C2B confirmation URL with Safaricom Daraja';

    public function handle(Collection $collection): int
    {
        try {
            $answer = $collection->registerC2B();
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Registered: '.($answer['ResponseDescription'] ?? 'OK'));

        return self::SUCCESS;
    }
}

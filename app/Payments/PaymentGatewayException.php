<?php

namespace App\Payments;

/** FUN-11: a provider refused or could not be reached; the message is safe to show the payer. */
class PaymentGatewayException extends \RuntimeException {}

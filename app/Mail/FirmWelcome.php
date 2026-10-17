<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Sent to the first administrator when the platform creates a firm: where to sign in, with which email, and the
 * initial password (only when one was generated; an existing person keeps the password they already have).
 */
class FirmWelcome extends Mailable
{
    public function __construct(
        public string $productName,
        public string $firmName,
        public string $adminName,
        public string $adminEmail,
        public string $loginUrl,
        public ?string $password,
        public string $planName,
        public ?string $trialEnds = null,
    ) {}

    public function build()
    {
        return $this->subject("Your {$this->productName} account for {$this->firmName} is ready")->view('emails.firm_welcome');
    }
}

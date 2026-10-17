<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForgetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public $username;

    public $token;

    /** Base URL of the firm the link should open (multi-tenant); null = APP_URL. */
    public $baseUrl;

    /**
     * Create a new message instance.
     */
    public function __construct($username, $token, $baseUrl = null)
    {
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->token = $token;

    }

    public function build()
    {
        return $this->subject('Password Reset')
            ->view('emails.forgotPassword');
    }
}

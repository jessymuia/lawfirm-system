<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssignedTask extends Mailable
{
    use Queueable, SerializesModels;

    public $task;
    /**
     * Create a new message instance.
     */
    public function __construct($task)
    {
        $this->task = $task;

    }

    public function build()
    {
        return $this->subject('Task')
            ->view('emails.taskNotification');
    }
}

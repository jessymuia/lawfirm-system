<?php

namespace App\Mail;

use App\Models\FirmExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** ENG-21: the firm-wide export you asked for is ready. No link in the mail: the download is in the app, behind sign-in. */
class FirmExportReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FirmExport $export) {}

    public function build()
    {
        return $this->subject('Your firm data export is ready')->view('emails.firm_export_ready');
    }
}

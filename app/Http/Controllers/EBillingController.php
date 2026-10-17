<?php

namespace App\Http\Controllers;

use App\EBilling\Ledes1998B;
use App\EBilling\Utbms;
use App\Http\Requests\EBilling\SettingsRequest;
use App\Models\Invoice;
use App\Security\SecurityPolicy;
use App\Support\FirmSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** FUN-13: UTBMS code lists for the forms, the firm's LEDES identifiers, and an invoice's LEDES 1998B e-bill. */
class EBillingController extends Controller
{
    public function __construct(private Ledes1998B $ledes) {}

    public function codes(): JsonResponse
    {
        return $this->response(true, 'success', Utbms::options(), 200);
    }

    public function settings(): JsonResponse
    {
        return $this->response(true, 'success', ['law_firm_id' => FirmSettings::get('ledes_law_firm_id')], 200);
    }

    public function saveSettings(SettingsRequest $r): JsonResponse
    {
        $old = FirmSettings::get('ledes_law_firm_id');
        $new = $r->validated('law_firm_id') ?: null;
        FirmSettings::set('ledes_law_firm_id', $new, (int) Auth::id());
        SecurityPolicy::audit('ledes_settings_changed', 'firm_setting', 0, ['law_firm_id' => $old], ['law_firm_id' => $new]);

        return $this->response(true, 'Saved.', ['law_firm_id' => $new], 200);
    }

    /** What an e-billing system would reject, before downloading. */
    public function check(int $invoice): JsonResponse
    {
        return $this->response(true, 'success', $this->ledes->check(Invoice::findOrFail($invoice)), 200);   // matter access applies
    }

    public function export(int $invoice)
    {
        $inv = Invoice::findOrFail($invoice);
        $check = $this->ledes->check($inv);
        if ($check['errors']) {
            return $this->response(false, 'This invoice cannot be e-billed yet.', $check, 422);
        }
        $name = 'LEDES1998B-'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $inv->invoice_number).'.txt';

        return response($this->ledes->render($inv), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Secrets\IntegrationCredentials;
use Illuminate\Http\Request;

/** SEC-15: the firm's own third-party credentials. Write-only: values are never returned. */
class IntegrationCredentialsController extends Controller
{
    public function __construct(private IntegrationCredentials $creds)
    {
    }

    public function show(string $provider)
    {
        abort_unless(isset(IntegrationCredentials::PROVIDERS[$provider]), 404);

        return response()->json(['data' => ['provider' => $provider, 'fields' => $this->creds->status($provider)] + $this->creds->readiness($provider)]);
    }

    /** Every provider at once, for the Integrations page. Which fields are set and whose they are; never the values. */
    public function index()
    {
        $out = [];
        foreach (array_keys(IntegrationCredentials::PROVIDERS) as $p) {
            $out[$p] = ['fields' => $this->creds->status($p)] + $this->creds->readiness($p);
        }

        return response()->json(['data' => $out, 'meta' => ['own_only' => (bool) config('tenancy.own_credentials')]]);
    }

    /** Sends a test message through the firm's mail settings to the signed-in user. */
    public function testMail(Request $r)
    {
        \App\Secrets\FirmMail::apply();
        try {
            \Illuminate\Support\Facades\Mail::raw('This is a test message from your firm\'s '.config('app.name').' account. If you can read it, your outgoing email settings work.', function ($m) use ($r) {
                $m->to($r->user()->email)->subject('Test email from '.config('app.name'));
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The test email could not be sent: '.\Illuminate\Support\Str::limit($e->getMessage(), 200)], 422);
        }

        return response()->json(['message' => 'Test email sent to '.$r->user()->email.'.']);
    }

    public function update(\App\Http\Requests\IntegrationCredentials\UpdateRequest $r, string $provider)
    {
        abort_unless(isset(IntegrationCredentials::PROVIDERS[$provider]), 404);
        $d = $r->validated();
        $this->creds->set($provider, $d, $r->user()->id);

        \App\Secrets\FirmMail::apply();

        return response()->json(['data' => ['provider' => $provider, 'fields' => $this->creds->status($provider)] + $this->creds->readiness($provider)]);
    }
}

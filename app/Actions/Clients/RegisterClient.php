<?php

namespace App\Actions\Clients;

use App\Actions\ActionException;
use App\Conflicts\ConflictChecks;
use App\Conflicts\ConflictException;
use App\Jobs\SendSmsJob;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** ENG-9: take on a client, against a cleared conflict check when given or required (FUN-9); the client gets a welcome SMS. */
class RegisterClient
{
    public function __construct(private ConflictChecks $conflicts) {}

    /** @param array{name: string, phone_number: string, extra_phone_number?: ?string, address: string, advocate_id: int, ebilling_client_id?: ?string} $data */
    public function handle(array $data, ?User $actor, ?int $conflictCheckId = null, bool $welcome = true): Client
    {
        try {
            $client = DB::transaction(function () use ($data, $actor, $conflictCheckId) {
                $check = $actor
                    ? $this->conflicts->clearanceFor('client', $conflictCheckId, (string) $data['name'], $actor)
                    : (ConflictChecks::required() ? throw new ConflictException('Run a conflict check for this client first.', 422, 'conflict_check_required') : null);
                $client = new Client;
                $client->fill([
                    'name' => $data['name'], 'phone_number' => $data['phone_number'], 'extra_phone_number' => $data['extra_phone_number'] ?? null,
                    'address' => $data['address'], 'advocate_id' => $data['advocate_id'], 'ebilling_client_id' => $data['ebilling_client_id'] ?? null,
                ]);
                $actor && $client->forceFill(['created_by' => $actor->getKey()]);
                $client->save();
                $this->conflicts->link($check, 'client', (int) $client->id);

                return $client;
            });
        } catch (ConflictException $e) {
            throw new ActionException($e->getMessage(), $e->status, $e->code_);
        }
        if ($welcome) {
            SendSmsJob::dispatch((string) $data['phone_number'], 'Welcome. You have been registered to the system');
        }

        return $client;
    }
}

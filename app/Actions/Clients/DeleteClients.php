<?php

namespace App\Actions\Clients;

use App\Actions\Concerns\Stamps;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** ENG-9: delete clients (soft), all or nothing (a client under legal hold refuses the batch, SEC-13), and undo that. */
class DeleteClients
{
    use Stamps;

    /** @param iterable<Client> $clients @return Collection<int, int> */
    public function handle(iterable $clients, ?User $actor): Collection
    {
        return DB::transaction(function () use ($clients, $actor) {
            $ids = collect();
            foreach ($clients as $client) {
                $this->softDelete($client, $actor);
                $ids->push((int) $client->id);
            }

            return $ids;
        });
    }

    public function restore(Client $client): Client
    {
        $client->restore();
        $client->forceFill(['deleted_by' => null])->save();

        return $client;
    }
}

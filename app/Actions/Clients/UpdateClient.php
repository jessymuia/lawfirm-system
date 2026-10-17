<?php

namespace App\Actions\Clients;

use App\Actions\Concerns\Stamps;
use App\Models\Client;
use App\Models\User;

/** ENG-9: change a client's details; fields left out stay as they are. */
class UpdateClient
{
    use Stamps;

    public const FIELDS = ['name', 'phone_number', 'extra_phone_number', 'address', 'advocate_id', 'ebilling_client_id'];

    /** @param array<string, mixed> $data */
    public function handle(Client $client, array $data, ?User $actor): Client
    {
        $client->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $this->stamp($client, $actor)->save();

        return $client;
    }
}

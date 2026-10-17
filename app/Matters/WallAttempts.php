<?php

namespace App\Matters;

use App\Models\EthicalScreenAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** SEC-11: records that a screened user tried to reach walled material. Never throws into the request that triggered it. */
class WallAttempts
{
    public static function record(User|int $user, string $surface, ?int $caseId, ?int $clientId = null, array $ctx = []): ?EthicalScreenAttempt
    {
        $userId = $user instanceof User ? $user->getKey() : $user;
        $wall = MatterAccess::wallFor($userId, $caseId, $clientId);
        if (! $wall) {
            return null;
        }

        try {
            Log::channel(config('logging.default'))->warning('ethical_screen.attempt', ['user_id' => $userId, 'screen_id' => $wall['screen_id'], 'surface' => $surface, 'case_id' => $wall['case_id'], 'client_id' => $wall['client_id']]);

            return EthicalScreenAttempt::create([
                'screen_id' => $wall['screen_id'], 'user_id' => $userId, 'case_id' => $wall['case_id'], 'client_id' => $wall['client_id'],
                'surface' => $surface, 'method' => $ctx['method'] ?? null, 'path' => isset($ctx['path']) ? mb_substr($ctx['path'], 0, 255) : null,
                'status' => $ctx['status'] ?? null, 'ip' => $ctx['ip'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}

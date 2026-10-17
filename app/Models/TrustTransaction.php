<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Enums\TrustEntryType;
use App\Matters\MatterScoped;
use App\Models\Concerns\ConstrainedToEnums;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class TrustTransaction extends DefaultAppModel
{
    use ConstrainedToEnums;

    /** DATA-12: columns that only ever hold one of their enum's values */
    public const ENUM_COLUMNS = ['type' => TrustEntryType::class];

    use MatterScoped;

    protected $fillable = [
        'client_id',
        'case_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'reference_number',
        'ledger_number',
        'recorded_by',
        'voided_by',
        'voided_at',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'voided_at' => 'datetime',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /**
     * Current trust balance for a client: sum of deposits minus
     * disbursements, excluding voided entries. This ledger is append-only
     * (see the migration's comment) so this is always derivable from the
     * transaction history, not just from the cached balance_after column.
     */
    public static function balanceForClient(int $clientId): float
    {
        $deposits = self::where('client_id', $clientId)
            ->whereNull('voided_at')
            ->where('type', 'deposit')
            ->sum('amount');

        $disbursements = self::where('client_id', $clientId)
            ->whereNull('voided_at')
            ->where('type', 'disbursement')
            ->sum('amount');

        return round((float) $deposits - (float) $disbursements, 2);
    }

    /**
     * Post a new trust transaction. Wrapped in a locking transaction so
     * concurrent disbursements against the same client can never both read
     * a stale balance and jointly overdraw the trust account — the classic
     * race condition a naive "check then insert" is vulnerable to.
     *
     * @throws \RuntimeException if a disbursement would overdraw the client's trust balance.
     */
    public static function post(int $clientId, ?int $caseId, string $type, float $amount, string $description, ?string $referenceNumber, int $recordedBy): self
    {
        return DB::transaction(function () use ($clientId, $caseId, $type, $amount, $description, $referenceNumber, $recordedBy) {
            // Lock existing rows for this client so concurrent posts serialize.
            self::where('client_id', $clientId)->lockForUpdate()->get();

            $currentBalance = self::balanceForClient($clientId);
            $newBalance = $type === 'deposit'
                ? $currentBalance + $amount
                : $currentBalance - $amount;

            if ($newBalance < 0) {
                throw new \RuntimeException(
                    "Disbursement of {$amount} would overdraw client's trust balance (current: {$currentBalance})."
                );
            }

            return self::create([
                'client_id' => $clientId,
                'case_id' => $caseId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference_number' => $referenceNumber,
                'ledger_number' => app(SequenceService::class)->nextFormatted('trust', 'TRT', 'trust_transactions', 'ledger_number'),
                'recorded_by' => $recordedBy,
                'created_by' => $recordedBy,
            ]);
        });
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    /** @return BelongsTo<Cases, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Cases::class, 'case_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}

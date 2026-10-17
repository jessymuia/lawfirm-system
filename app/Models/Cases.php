<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Enums\MatterStatus;
use App\Matters\MatterScoped;
use App\Models\Concerns\ConstrainedToEnums;
use App\Models\Concerns\HasCaseType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cases extends DefaultAppModel
{
    use ConstrainedToEnums;
    use HasCaseType;

    /** @return BelongsTo<CaseType, $this> */
    public function caseType(): BelongsTo
    {
        return $this->belongsTo(CaseType::class);
    }

    /** DATA-12: columns that only ever hold one of their enum's values */
    public const ENUM_COLUMNS = ['lifecycle_status' => MatterStatus::class];

    use HasPublicId;
    use MatterScoped;

    public static function matterColumn(): string
    {
        return 'id';
    }

    protected $fillable = [
        'client_matter_id',   // FUN-13
        'case_number',
        'description',
        'client_id',
        'assigned_to',
        'start_date',
        'end_date',
        'case_type',
        'police_station',
        'court_id',
        'opposing_party',
        'lifecycle_status',
        'office_id',
        'practice_group_id',
        'updated_by',
        'deleted_by',
    ];

    // Valid forward transitions. 'open' is the only entry state; from there
    // a case can move to any of the three end states, and 'appeal' can
    // return to 'open' (case reopened on appeal) or move on to 'settled'.
    /** The allowed lifecycle moves, from App\Enums\MatterStatus (DATA-12): status => statuses it may move to. */
    public static function transitions(): array
    {
        return collect(MatterStatus::cases())->mapWithKeys(fn ($s) => [$s->value => array_map(fn ($n) => $n->value, $s->next())])->all();
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::transitions()[$this->lifecycle_status] ?? [], true);
    }

    // withTrashed() so cases that reference a since-deleted client/attorney/
    // court still resolve a name instead of crashing (see DATA-6).
    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function attorney(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    /**
     * FUN-18
     *
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * FUN-18
     *
     * @return BelongsTo<PracticeGroup, $this>
     */
    public function practiceGroup(): BelongsTo
    {
        return $this->belongsTo(PracticeGroup::class);
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class, 'court_id')->withTrashed();
    }
}

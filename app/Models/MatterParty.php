<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Conflicts\Names;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FUN-9: someone a matter is against or about. Read through matter access like the matter itself. */
class MatterParty extends DefaultAppModel
{
    use \App\Matters\MatterScoped;

    public const ROLES = ['adverse', 'co_party', 'related', 'opposing_counsel'];

    protected $fillable = ['case_id', 'role', 'name', 'identifier', 'notes', 'from_opposing_party'];

    protected $casts = ['from_opposing_party' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (MatterParty $p) {
            $p->name_normalized = Names::normalize($p->name);
            $p->identifier_normalized = $p->identifier ? Names::identifier($p->identifier) : null;
        });
    }

    /** @return BelongsTo<Cases, $this> */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Cases::class, 'case_id');
    }

    /** FUN-9 dual write: cases.opposing_party is mirrored as the matter's first adverse party. */
    public static function syncOpposingParty(Cases $case): void
    {
        $name = trim((string) $case->opposing_party);
        $row = static::withoutGlobalScopes()->where('case_id', $case->id)->where('from_opposing_party', true)->first();
        if ($name === '') {
            $row?->delete();

            return;
        }
        if ($row) {
            if ($row->name !== $name) {
                $row->update(['name' => $name]);
            }

            return;
        }
        static::create(['case_id' => $case->id, 'role' => 'adverse', 'name' => $name, 'from_opposing_party' => true]);
    }
}

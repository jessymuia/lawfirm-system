<?php

namespace App\Matters;

use App\Models\CaseType;
use Illuminate\Support\Collection;

/**
 * DATA-12: the firm's matter types. A type typed on a matter is found in the list (ignoring letter case and spacing)
 * or added to it, so "Civil", "civil " and "CIVIL" are one type and the list grows only with genuinely new types.
 */
class CaseTypes
{
    public static function normalise(string $name): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', trim($name)) ?? '', 0, 100);
    }

    public function resolve(string $name, ?int $by = null): ?CaseType
    {
        $name = self::normalise($name);
        if ($name === '') {
            return null;
        }
        $found = CaseType::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $found ?? CaseType::create(['name' => $name, 'created_by' => $by]);
    }

    /** @return Collection<int, array{id: int, name: string}> the types to choose from (archived ones are not offered) */
    public function options(): Collection
    {
        return CaseType::whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->map(fn (CaseType $t) => ['id' => (int) $t->id, 'name' => $t->name]);
    }
}

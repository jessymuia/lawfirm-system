<?php

namespace App\Search;

use App\Matters\MatterAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-19: what a person may see, as a filter the engine applies while it searches (not afterwards):
 *  - ethical walls (SEC-11): never the walled matters or clients;
 *  - restricted matter access (SEC-10): only matters the person works on (indexed as `access_users`) or holds an
 *    unexpired grant for, unless they may see all matters.
 */
class SearchFilter
{
    /** @var array<string, list<int>> field => ids that must not match */
    public array $exclude = [];

    /** @var array{0: string, 1: int, 2: list<int>}|null [matter field, user id, granted matter ids] */
    public ?array $restrict = null;

    /** @param string $matterField the field holding the matter id (`id` for matters, `case_id` for documents); null for clients */
    public static function for(User $user, ?string $matterField): self
    {
        $f = new self;
        $walls = MatterAccess::walls()->for((int) $user->getKey());
        if ($walls['clients']) {
            $f->exclude[$matterField === null ? 'id' : 'client_id'] = array_map('intval', array_keys($walls['clients']));
        }
        if ($matterField === null) {
            return $f;
        }
        if ($walls['cases']) {
            $f->exclude[$matterField] = array_map('intval', array_keys($walls['cases']));
        }
        if (MatterAccess::accessRuleApplies($user)) {
            $granted = DB::table('matter_access_grants')->where('user_id', $user->getKey())->whereNull('deleted_at')
                ->where(fn ($g) => $g->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('case_id')->map(fn ($v) => (int) $v)->all();
            $f->restrict = [$matterField, (int) $user->getKey(), $granted];
        }

        return $f;
    }

    /** The filter in Meilisearch's syntax, or null for none. */
    public function toMeilisearch(): ?string
    {
        $parts = [];
        foreach ($this->exclude as $field => $ids) {
            $parts[] = "{$field} NOT IN [".implode(', ', $ids).']';
        }
        if ($this->restrict) {
            [$field, $user, $granted] = $this->restrict;
            $parts[] = $granted ? "(access_users = {$user} OR {$field} IN [".implode(', ', $granted).'])' : "access_users = {$user}";
        }

        return $parts ? implode(' AND ', $parts) : null;
    }
}

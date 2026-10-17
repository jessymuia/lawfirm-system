<?php

namespace App\Compliance;

use App\Compliance\RecordClasses\RecordClass;
use App\Security\SecurityPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SEC-13: retention schedules, one per record class (config compliance.classes, docs/COMPLIANCE.md). Nothing is
 * disposed of unless the firm has set a period for the class AND turned auto-purge on for it (or an operator runs the
 * command with --execute against such a policy). Records under legal hold are never touched: each class leaves them
 * out of what is due and checks the hold again right before disposing. Financial records and the audit trail are
 * deliberately outside this job.
 *
 * Every disposal leaves a `retention_disposals` record (what, when, under which period) and an audit entry.
 */
class Retention
{
    public const CLASS_DOCS = 'case_documents';

    public function __construct(private LegalHolds $holds) {}

    public function policies(): array
    {
        $rows = DB::table('retention_policies')->get()->keyBy('record_class');
        $out = [];
        foreach (config('compliance.classes') as $class => $meta) {
            $p = $rows[$class] ?? null;
            unset($meta['handler']);
            $out[] = $meta + ['record_class' => $class, 'retain_years' => $p?->retain_years, 'auto_purge' => (bool) ($p->auto_purge ?? false)];
        }

        return $out;
    }

    public function policy(string $class = self::CLASS_DOCS): ?object
    {
        return DB::table('retention_policies')->where('record_class', $class)->first();
    }

    /** @return list<string> the classes the job can dispose of */
    public function purgeableClasses(): array
    {
        return array_keys(array_filter((array) config('compliance.classes'), fn ($m) => $m['purgeable'] ?? false));
    }

    public function set(string $class, int $years, bool $autoPurge, ?int $actor): object
    {
        if (! in_array($class, $this->purgeableClasses(), true)) {
            throw new ComplianceException('That record class is never disposed of by retention.');
        }
        $floor = (int) config('compliance.minimum_retention_years');
        if ($years < max(1, $floor)) {
            throw new ComplianceException("Retention must be at least {$floor} year(s).");
        }
        $before = $this->policy($class);
        DB::table('retention_policies')->updateOrInsert(['record_class' => $class], [
            'retain_years' => $years, 'auto_purge' => $autoPurge, 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => $before->created_at ?? now(),
        ]);
        SecurityPolicy::audit('retention_policy_changed', 'retention_policy', (int) $this->policy($class)->id,
            ($before ? ['retain_years' => $before->retain_years, 'auto_purge' => (bool) $before->auto_purge] : []) + ['record_class' => $class],
            ['retain_years' => $years, 'auto_purge' => $autoPurge]);

        return $this->policy($class);
    }

    private function handler(string $class): RecordClass
    {
        return app(config("compliance.classes.{$class}.handler"));
    }

    /**
     * Records of a class past their period. Held ones are counted separately and never listed as due.
     *
     * @return array{due: Collection<int, int>, held: int, cutoff: ?string}
     */
    public function due(string $class = self::CLASS_DOCS): array
    {
        $p = $this->policy($class);
        if (! $p || ! in_array($class, $this->purgeableClasses(), true)) {
            return ['due' => collect(), 'held' => 0, 'cutoff' => null];
        }
        $cutoff = now()->subYears((int) $p->retain_years)->toDateString();

        return ['cutoff' => $cutoff] + $this->handler($class)->due($cutoff, $this->holds->heldCaseIds());
    }

    /** @return array{disposed:int,held_skipped:int,errors:int,dry_run:bool} */
    public function run(bool $execute, ?int $actor = null, string $class = self::CLASS_DOCS): array
    {
        $p = $this->policy($class);
        $r = $this->due($class);
        $result = ['disposed' => 0, 'held_skipped' => $r['held'], 'errors' => 0, 'dry_run' => ! $execute];
        if (! $p || ! $execute) {
            $result['disposed'] = $execute ? 0 : $r['due']->count();   // a dry run reports what would go

            return $result;
        }
        $handler = $this->handler($class);
        foreach ($r['due'] as $id) {
            try {
                $what = $handler->dispose((int) $id, (int) $p->retain_years, $actor);
                SecurityPolicy::audit('retention_disposed', $handler->type() === 'document' ? 'Document' : $handler->type(), (int) $id,
                    array_intersect_key($what, ['title' => 1]), ['years' => (int) $p->retain_years, 'record_class' => $class] + array_diff_key($what, ['title' => 1]));
                $result['disposed']++;
            } catch (LegalHoldException $e) {
                $e->recordAudit();
                $result['held_skipped']++;   // placed between listing and disposal: the hold wins
            } catch (\Throwable $e) {
                $result['errors']++;
                report($e);
            }
        }

        return $result;
    }
}

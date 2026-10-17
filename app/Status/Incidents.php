<?php

namespace App\Status;

use Illuminate\Support\Carbon;

/**
 * PLAT-12: incidents and planned maintenance shown on the public status page. Written by platform staff (or `status:incident`
 * on a self-hosted install); the text is public, so it must never carry anything about a specific firm.
 * A resolved incident is closed for good: a recurrence is a new incident.
 */
class Incidents
{
    public const IMPACTS = ['none', 'minor', 'major', 'maintenance'];

    public const STATUSES = ['scheduled', 'investigating', 'identified', 'monitoring', 'resolved'];

    public function __construct(private Health $health)
    {
    }

    private function db()
    {
        return $this->health->store();
    }

    /** @param array{title:string,impact:string,message:string,components?:array,status?:string,starts_at?:string,ends_at?:string} $d */
    public function open(array $d, ?int $staffId = null): object
    {
        $impact = $d['impact'];
        if (! in_array($impact, self::IMPACTS, true)) {
            throw new StatusException('Unknown impact.');
        }
        $components = array_values(array_unique((array) ($d['components'] ?? [])));
        if (array_diff($components, $this->health->components())) {
            throw new StatusException('Unknown component.');
        }
        $maint = $impact === 'maintenance';
        $status = $d['status'] ?? ($maint ? 'scheduled' : 'investigating');
        if (! in_array($status, self::STATUSES, true) || $status === 'resolved' || ($status === 'scheduled' && ! $maint)) {
            throw new StatusException('That status is not valid for a new '.($maint ? 'maintenance' : 'incident').'.');
        }
        $start = isset($d['starts_at']) ? Carbon::parse($d['starts_at']) : now();
        $end = isset($d['ends_at']) ? Carbon::parse($d['ends_at']) : null;
        if ($maint && (! $end || $end->lte($start))) {
            throw new StatusException('Maintenance needs an end time after its start.');
        }
        if (! $maint && ($start->isFuture() || $end)) {
            throw new StatusException('Only maintenance can be scheduled.');
        }

        return $this->db()->transaction(function () use ($d, $impact, $components, $status, $start, $end, $staffId) {
            $id = $this->db()->table('status_incidents')->insertGetId([
                'title' => mb_substr(trim($d['title']), 0, 160), 'impact' => $impact, 'status' => $status, 'components' => json_encode($components),
                'started_at' => $start, 'ends_at' => $end, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->addUpdate($id, $status, $d['message'], $staffId);

            return $this->find($id);
        });
    }

    public function update(int $id, string $status, string $message, ?int $staffId = null): object
    {
        $i = $this->db()->table('status_incidents')->find($id) ?? throw new StatusException('No such incident.', 404);
        if ($i->status === 'resolved') {
            throw new StatusException('A resolved incident is closed; open a new one.', 409);
        }
        if (! in_array($status, self::STATUSES, true) || ($status === 'scheduled' && $i->status !== 'scheduled')) {
            throw new StatusException('That status is not valid here.');
        }
        if ($i->impact !== 'maintenance' && $status === 'scheduled') {
            throw new StatusException('That status is not valid here.');
        }

        return $this->db()->transaction(function () use ($i, $status, $message, $staffId) {
            $this->db()->table('status_incidents')->where('id', $i->id)->update(['status' => $status, 'resolved_at' => $status === 'resolved' ? now() : null, 'updated_at' => now()]);
            $this->addUpdate($i->id, $status, $message, $staffId);

            return $this->find($i->id);
        });
    }

    private function addUpdate(int $id, string $status, string $message, ?int $staffId): void
    {
        $message = trim($message);
        if ($message === '') {
            throw new StatusException('A message is required.');
        }
        $this->db()->table('status_incident_updates')->insert(['incident_id' => $id, 'status' => $status, 'message' => mb_substr($message, 0, 2000), 'staff_id' => $staffId, 'created_at' => now()]);
    }

    public function find(int $id): object
    {
        $i = $this->db()->table('status_incidents')->find($id) ?? throw new StatusException('No such incident.', 404);

        return $this->shape($i, $this->db()->table('status_incident_updates')->where('incident_id', $id)->orderBy('id')->get());
    }

    private function shape(object $i, $updates): object
    {
        $i->components = (array) json_decode((string) $i->components, true);
        $i->updates = $updates->map(fn ($u) => (object) ['status' => $u->status, 'message' => $u->message, 'at' => Carbon::parse($u->created_at)->toIso8601String()])->values()->all();

        return $i;
    }

    /** Open (unresolved) items first, then the resolved ones from the last $days days. Staff ids never leave the platform. */
    public function recent(int $days = 90): array
    {
        $rows = $this->db()->table('status_incidents')
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDays($days)))
            ->orderByRaw('case when resolved_at is null then 0 else 1 end')->orderByDesc('started_at')->limit(100)->get();
        $updates = $this->db()->table('status_incident_updates')->whereIn('incident_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('incident_id');

        return $rows->map(fn ($i) => $this->shape($i, $updates->get($i->id, collect())))->all();
    }
}

<?php

namespace App\Http\Controllers;

use App\Status\Health;
use App\Status\Incidents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Response;

/**
 * PLAT-12: public health endpoints and status page. Reachable on every host (a load balancer probes by address) and independent of
 * any firm: nothing here reads a tenant database or reveals which firms exist.
 */
class HealthController extends Controller
{
    public function __construct(private Health $health, private Incidents $incidents)
    {
    }

    /** Liveness: the process answers. Touches nothing, so a database outage never gets the app restarted in a loop. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }

    /** Readiness: what a request needs right now. 503 takes the instance out of rotation. Statuses only, no detail. */
    public function ready(): JsonResponse
    {
        $r = $this->health->ready();

        return response()->json(['status' => $r['status'], 'cell' => config('status.cell'), 'components' => $r['components']], $r['status'] === Health::DOWN ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    private function snapshot(): array
    {
        $current = $this->health->current();
        // availability is a heavy aggregate; a minute of staleness is fine for a public page
        $avail = Cache::remember('status:availability:30', 60, fn () => $this->health->availability(30));
        $components = [];
        foreach ($current as $name => $c) {
            $components[] = ['id' => $name, 'name' => $this->health->label($name), 'status' => $c['status'], 'availability_30d' => $avail[$name]['availability'] ?? null];
        }
        $statuses = array_map(fn ($c) => $c['status'], $current);

        return [
            'cell' => config('status.cell'),
            'status' => $this->health->overall($statuses),
            'updated_at' => now()->toIso8601String(),
            'components' => $components,
            'incidents' => array_map(fn ($i) => [
                'id' => $i->id, 'title' => $i->title, 'impact' => $i->impact, 'status' => $i->status, 'components' => $i->components,
                'started_at' => \Illuminate\Support\Carbon::parse($i->started_at)->toIso8601String(),
                'ends_at' => $i->ends_at ? \Illuminate\Support\Carbon::parse($i->ends_at)->toIso8601String() : null,
                'resolved_at' => $i->resolved_at ? \Illuminate\Support\Carbon::parse($i->resolved_at)->toIso8601String() : null,
                'updates' => array_map(fn ($u) => (array) $u, $i->updates),
            ], $this->incidents->recent(90)),
        ];
    }

    public function json(): JsonResponse
    {
        return response()->json($this->snapshot(), 200, ['Cache-Control' => 'public, max-age=30']);
    }

    public function page(): Response
    {
        $s = $this->snapshot();
        $e = fn ($v) => e((string) $v);
        $label = ['operational' => 'Operational', 'degraded' => 'Degraded', 'down' => 'Outage', 'unknown' => 'No recent data'];
        $banner = ['operational' => 'All systems operational', 'degraded' => 'Some systems are degraded', 'down' => 'Service outage', 'unknown' => 'Status unavailable'];

        $rows = '';
        foreach ($s['components'] as $c) {
            $a = $c['availability_30d'] === null ? '—' : rtrim(rtrim(number_format($c['availability_30d'], 3), '0'), '.').'%';
            $rows .= "<tr><td>{$e($c['name'])}</td><td class=\"s {$e($c['status'])}\">{$e($label[$c['status']] ?? $c['status'])}</td><td class=\"n\">{$e($a)}</td></tr>";
        }
        $inc = '';
        foreach ($s['incidents'] as $i) {
            $ups = '';
            foreach (array_reverse($i['updates']) as $u) {
                $ups .= "<li><b>{$e(ucfirst($u['status']))}</b> <time>{$e($u['at'])}</time><br>{$e($u['message'])}</li>";
            }
            $when = $i['impact'] === 'maintenance' && $i['ends_at'] ? " · until {$e($i['ends_at'])}" : '';
            $inc .= "<section><h3>{$e($i['title'])}</h3><p class=\"m\">{$e(ucfirst($i['impact']))} · {$e(ucfirst($i['status']))} · from {$e($i['started_at'])}{$when}</p><ul>{$ups}</ul></section>";
        }
        $inc = $inc ?: '<p class="m">No incidents in the last 90 days.</p>';

        $html = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>System status</title>
<style>
:root{--bg:#fff;--fg:#1a1d21;--mut:#5b6572;--line:#dfe3e8;--ok:#117a3d;--deg:#a15c00;--down:#b3261e}
@media(prefers-color-scheme:dark){:root{--bg:#14171b;--fg:#e8eaed;--mut:#9aa4af;--line:#2b3138;--ok:#4cc27d;--deg:#e0a040;--down:#f26b60}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,sans-serif}main{max-width:44rem;margin:0 auto;padding:1.5rem 1rem}
h1{font-size:1.4rem;margin:0 0 .25rem}h2{font-size:1.1rem;margin:2rem 0 .5rem}h3{font-size:1rem;margin:0}
.banner{font-weight:600;font-size:1.1rem;padding:.75rem 1rem;border:1px solid var(--line);border-left-width:6px;border-radius:6px;margin:1rem 0}
.banner.operational{border-left-color:var(--ok)}.banner.degraded,.banner.unknown{border-left-color:var(--deg)}.banner.down{border-left-color:var(--down)}
table{width:100%;border-collapse:collapse}td,th{padding:.5rem .25rem;border-bottom:1px solid var(--line);text-align:left}th{color:var(--mut);font-weight:500;font-size:.85rem}
.n{text-align:right;font-variant-numeric:tabular-nums}.s.operational{color:var(--ok)}.s.degraded,.s.unknown{color:var(--deg)}.s.down{color:var(--down)}
.m,time{color:var(--mut);font-size:.85rem;margin:.1rem 0}section{margin:1rem 0;padding-bottom:.5rem;border-bottom:1px solid var(--line)}ul{padding-left:1.1rem}
</style></head><body><main>
<h1>System status</h1><p class="m">Updated {$e($s['updated_at'])}</p>
<div class="banner {$e($s['status'])}">{$e($banner[$s['status']] ?? $s['status'])}</div>
<table><thead><tr><th>Component</th><th>Now</th><th class="n">Last 30 days</th></tr></thead><tbody>{$rows}</tbody></table>
<h2>Incidents and maintenance</h2>{$inc}
<p class="m">Availability is measured by checks run every minute from inside the service; minutes in announced maintenance are excluded.</p>
</main></body></html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=30', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff']);
    }
}

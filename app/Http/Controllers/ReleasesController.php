<?php

namespace App\Http\Controllers;

use App\Releases\ReleaseNotes;
use App\Releases\Semver;
use App\Support\ReleaseLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PLAT-10: the customer changelog and per-version upgrade guides, for any signed-in user. Written for customers: see docs/upgrade/README.md. */
class ReleasesController extends Controller
{
    private function shape(array $r, ReleaseNotes $n, bool $guide = false): array
    {
        $out = [
            'version' => $r['version'], 'date' => $r['date'], 'prerelease' => Semver::isPrerelease($r['version']), 'summary' => $r['summary'],
            'sections' => $r['sections'], 'breaking' => $r['breaking'], 'has_upgrade_guide' => $n->guide($r['version']) !== null,
        ];
        if ($guide) {
            $out['upgrade_guide'] = $n->guide($r['version']);
        }

        return $out;
    }

    public function index(\App\Http\Requests\Releases\IndexRequest $r): JsonResponse
    {
        $d = $r->validated();
        $n = ReleaseNotes::fromApp();
        $since = $d['since'] ?? null;
        abort_if($since !== null && ! Semver::valid($since), 422, 'since must be a semantic version.');
        $list = array_values(array_filter($n->releases(), fn ($x) => $since === null || Semver::compare($x['version'], $since) > 0));

        return response()->json(['data' => [
            'current' => (string) config('app.version'),
            'installed' => ReleaseLog::current(),
            'releases' => array_map(fn ($x) => $this->shape($x, $n), $list),
        ]]);
    }

    public function show(string $version): JsonResponse
    {
        $n = ReleaseNotes::fromApp();
        // only versions that the changelog lists as released are looked up, so the path parameter never reaches the file system
        $r = collect($n->releases())->first(fn ($x) => $x['version'] === $version) ?? abort(404);

        return response()->json(['data' => $this->shape($r, $n, true)]);
    }
}

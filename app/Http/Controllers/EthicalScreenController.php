<?php

namespace App\Http\Controllers;

use App\Models\EthicalScreen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SEC-11: create, lift and report ethical screens. Reporting uses the query builder on purpose: a compliance officer must be
 * able to see which matters are walled without being granted access to their content.
 * A user can never create or lift a screen that applies to themselves.
 */
class EthicalScreenController extends Controller
{
    private function screenRows(Request $request)
    {
        $q = DB::table('ethical_screens as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('cases as c', 'c.id', '=', 's.case_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 's.client_id')
            ->whereNull('s.deleted_at')
            ->orderByDesc('s.id')
            ->select('s.id', 's.user_id', 'u.name as user_name', 's.case_id', 'c.case_number', 's.client_id', 'cl.name as client_name',
                's.reason', 's.created_by', 's.created_at', 's.lifted_at', 's.lifted_by', 's.lift_reason');

        match ($request->input('status', 'active')) {
            'active' => $q->whereNull('s.lifted_at'),
            'lifted' => $q->whereNotNull('s.lifted_at'),
            default => null,
        };
        foreach (['user_id', 'case_id', 'client_id'] as $f) {
            if ($request->filled($f)) {
                $q->where("s.$f", $request->input($f));
            }
        }

        return $q;
    }

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $rows = $this->screenRows($request)->get();

        return $request->input('format') === 'csv'
            ? $this->csv('ethical-screens.csv', ['ID', 'Screened user', 'Case', 'Client', 'Reason', 'Created', 'Lifted', 'Lift reason'], $rows->map(fn ($r) => [
                $r->id, $r->user_name, $r->case_number, $r->client_name, $r->reason, $r->created_at, $r->lifted_at, $r->lift_reason]))
            : $this->response(true, 'success', $rows, 200);
    }

    public function attempts(Request $request): JsonResponse|StreamedResponse
    {
        $q = DB::table('ethical_screen_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('cases as c', 'c.id', '=', 'a.case_id')
            ->orderByDesc('a.id')
            ->select('a.id', 'a.screen_id', 'a.user_id', 'u.name as user_name', 'a.case_id', 'c.case_number', 'a.client_id', 'a.surface', 'a.method', 'a.path', 'a.status', 'a.ip', 'a.created_at');
        foreach (['user_id', 'screen_id', 'case_id'] as $f) {
            if ($request->filled($f)) {
                $q->where("a.$f", $request->input($f));
            }
        }
        if ($request->filled('from')) {
            $q->where('a.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('a.created_at', '<=', $request->input('to'));
        }
        $rows = $q->get();

        return $request->input('format') === 'csv'
            ? $this->csv('ethical-screen-attempts.csv', ['ID', 'Screen', 'User', 'Case', 'Surface', 'Method', 'Path', 'Status', 'IP', 'When'], $rows->map(fn ($r) => [
                $r->id, $r->screen_id, $r->user_name, $r->case_number, $r->surface, $r->method, $r->path, $r->status, $r->ip, $r->created_at]))
            : $this->response(true, 'success', $rows, 200);
    }

    public function store(\App\Http\Requests\EthicalScreen\StoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ((int) $data['user_id'] === Auth::id()) {
            abort(403, 'You cannot screen yourself.');
        }

        $existing = EthicalScreen::active()->where('user_id', $data['user_id'])
            ->where('case_id', $data['case_id'] ?? null)->where('client_id', $data['client_id'] ?? null)->first();
        if ($existing) {
            return $this->response(true, 'already screened', $existing, 200);
        }

        $screen = EthicalScreen::create([
            'user_id' => $data['user_id'], 'case_id' => $data['case_id'] ?? null, 'client_id' => $data['client_id'] ?? null, 'reason' => $data['reason'],
        ]);

        return $this->response(true, 'success', $screen, 201);
    }

    public function lift(\App\Http\Requests\EthicalScreen\LiftRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $screen = EthicalScreen::active()->findOrFail($id);
        if ($screen->user_id === Auth::id()) {
            abort(403, 'You cannot lift a screen that applies to you.');
        }
        $screen->update(['lifted_at' => now(), 'lifted_by' => Auth::id(), 'lift_reason' => $data['reason']]);

        return $this->response(true, 'success', $screen->fresh(), 200);
    }

    private function csv(string $name, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                // Neutralise spreadsheet formula injection from free-text fields.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $r));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}

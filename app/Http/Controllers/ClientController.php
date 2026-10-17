<?php

namespace App\Http\Controllers;

use App\Actions\Clients\DeleteClients;
use App\Actions\Clients\RegisterClient;
use App\Actions\Clients\UpdateClient;
use App\Constants\ModulePermissions;
use App\Http\Requests\Client\DestroyManyRequest;
use App\Http\Requests\Client\StoreRequest;
use App\Http\Requests\Client\UpdateRequest;
use App\Http\Resources\V1\ClientResource;
use App\Models\Cases;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CLIENTS)) {
            abort(403);
        }

        $query = Client::with('advocate')->orderBy('id', 'desc');

        $clients = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => ClientResource::make($row)->resolve(),
            25,
            ['name', 'phone_number', 'address'],
            ['name', 'phone_number', 'address']
        );

        return $this->response(true, 'success', $clients, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_CLIENTS)) {
            abort(403);
        }

        // ENG-9: conflict clearance (FUN-9) and the welcome SMS live in the action; a refused check answers with its reason
        $client = app(RegisterClient::class)->handle([
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'extra_phone_number' => $request->extra_phone_number ?? null,
            'address' => $request->address,
            'advocate_id' => $request->advocate,
            'ebilling_client_id' => $request->input('ebilling_client_id'),   // FUN-13
        ], Auth::user(), $request->integer('conflict_check_id') ?: null);

        return $this->response(true, 'success', ClientResource::make($client->load('advocate'))->resolve(), 201);
    }

    /**
     * Display the specified resource.
     *
     * @return JsonResponse
     */
    public function show($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CLIENTS)) {
            abort(403);
        }

        // DATA-9: the client page URL carries the public id; the SPA's own calls may still use the integer id.
        $client = Client::isPublicId($id)
            ? Client::with('advocate')->wherePublicId($id)->firstOrFail()
            : Client::with('advocate')->findOrFail($id);

        $cases = Cases::where('client_id', $client->id)
            ->orderByDesc('id')
            ->get(['id', 'case_number', 'description', 'lifecycle_status', 'created_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'case_number' => $c->case_number,
                'title' => $c->case_number,
                'description' => $c->description,
                'status' => $c->lifecycle_status,
                'created_at' => $c->created_at,
            ]);

        return $this->response(true, 'success', [
            'id' => $client->id,
            'public_id' => $client->publicId(),
            'name' => $client->name,
            'phone_number' => $client->phone_number,
            'extra_phone_number' => $client->extra_phone_number,
            'address' => $client->address,
            'advocate' => $client->advocate->name ?? 'Unknown user',
            'advocate_id' => $client->advocate_id,
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
            'cases' => $cases,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return JsonResponse
     */
    public function update(UpdateRequest $request, $id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_CLIENTS)) {
            abort(403);
        }

        $client = app(UpdateClient::class)->handle(Client::findOrFail($id), [   // ENG-9
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'extra_phone_number' => $request->extra_phone_number,
            'address' => $request->address,
            'advocate_id' => $request->advocate,
        ] + ($request->exists('ebilling_client_id') ? $request->only('ebilling_client_id') : []), Auth::user());   // FUN-13: only when sent

        return $this->response(true, 'success', ClientResource::make($client->fresh('advocate'))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_CLIENTS)) {
            abort(403);
        }

        app(DeleteClients::class)->handle([Client::findOrFail($id)], Auth::user());   // ENG-9

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * UI-20: bulk delete — see CasesController::destroyMany for the
     * general pattern this mirrors.
     */
    public function destroyMany(DestroyManyRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_CLIENTS)) {
            abort(403);
        }

        // ENG-9: all or nothing, a client under a legal hold (SEC-13) refuses the whole batch
        $deleted = app(DeleteClients::class)->handle(Client::whereIn('id', $request->input('ids'))->get(), Auth::user());

        return $this->response(true, 'success', ['deleted' => $deleted], 200);
    }

    /**
     * UI-24: undo for the toast shown right after destroy().
     */
    public function restore($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_CLIENTS)) {
            abort(403);
        }

        $client = app(DeleteClients::class)->restore(Client::withTrashed()->findOrFail($id));

        return $this->response(true, 'success', $client, 200);
    }

    public function clientsDropDown(Request $request): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CLIENTS)) {
            abort(403);
        }

        // UI-16: typeahead-friendly — filter server-side and cap results
        // instead of shipping every client to the browser on modal open.
        $query = Client::query()->orderBy('name');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('name', 'like', '%'.$search.'%');
        }

        $clients = $query->limit((int) $request->input('per_page', 20))->get();
        $clients = $clients->map(function ($row) {
            return [
                'id' => $row->id,
                'name' => $row->name,
            ];
        });

        return $this->response(true, 'success', $clients, 200);
    }

    /**
     * UI-17: debounced existence check the frontend calls while typing,
     * shown as a non-blocking warning hint before submit — the DB-level
     * unique constraint on (phone_number, deleted_at) (see DATA-5
     * migration) remains the actual authority; this is purely a UX
     * shortcut so the person finds out before they submit, not after.
     */
    public function checkPhoneExists(Request $request): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CLIENTS)) {
            abort(403);
        }

        $phone = $request->input('phone_number');
        if (! $phone) {
            return $this->response(true, 'success', ['exists' => false], 200);
        }

        $query = Client::where('phone_number', $phone);
        if ($request->filled('exclude_id')) {
            $query->where('id', '!=', $request->input('exclude_id'));
        }

        return $this->response(true, 'success', ['exists' => $query->exists()], 200);
    }
}

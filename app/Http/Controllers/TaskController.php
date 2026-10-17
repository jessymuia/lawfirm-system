<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Jobs\SendSmsJob;
use App\Mail\AssignedTask;
use App\Mail\ReassignedTask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_TASK)) {
            abort(403);
        }

        $query = Task::with('assignee')->orderBy('id', 'desc');

        if (! Auth::user()->checkPermissionTo('create-roles')) {
            $query->where('assigned_to', auth()->user()->id);
        }

        $tasks = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => \App\Http\Resources\V1\TaskResource::make($row)->resolve(),
            25,
            ['title', 'due_date', 'priority'],
            ['title', 'description']
        );

        return $this->response(true, 'success', $tasks, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(\App\Http\Requests\Task\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_TASK)) {
            abort(403);
        }

        $task = Task::create([
            'description' => $request->description,
            'title' => $request->title,
            'assigned_to' => $request->assigned_to,
            'due_date' => Carbon::createFromFormat('d/m/Y', $request->due_date)->format('Y-m-d'),
            'priority' => $request->priority,
            'task_status' => $request->status,
        ]);

        $user = User::withTrashed()->find($request->assigned_to);
        if ($user) {
            SendSmsJob::dispatch($user->phone_number, "You have been assigned task:\nTitle : ".$task->title.".\nDescription : ".$task->description);
            if ($user->email) {
                Mail::to($user->email)->queue(new AssignedTask($task));
            }
        }

        return $this->response(true, 'success', \App\Http\Resources\V1\TaskResource::make($task->fresh('assignee'))->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     *
     */
    public function update(\App\Http\Requests\Task\UpdateRequest $request, $id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_TASK)) {
            abort(403);
        }

        $task = Task::findOrFail($id);
        $orig = $task->getOriginal();
        $task->update([
            'description' => $request->description,
            'title' => $request->title,
            'assigned_to' => $request->assigned_to,
            'due_date' => Carbon::createFromFormat('d/m/Y', $request->due_date)->format('Y-m-d'),
            'priority' => $request->priority,
            'task_status' => $request->status,
            'updated_by' => auth()->user()->id,
        ]);

        if ($orig['assigned_to'] != $task->assigned_to) {
            $newAssignee = User::withTrashed()->find($request->assigned_to);
            if ($newAssignee) {
                SendSmsJob::dispatch($newAssignee->phone_number, "You have been assigned task:\nTitle : ".$task->title.".\nDescription : ".$task->description);
                if ($newAssignee->email) {
                    Mail::to($newAssignee->email)->queue(new AssignedTask($task));
                }
            }

            $previousAssignee = User::withTrashed()->find($orig['assigned_to']);
            if ($previousAssignee) {
                SendSmsJob::dispatch($previousAssignee->phone_number, "Someone else has been assigned task:\nTitle : ".$task->title.".\n.Description : ".$task->description);
                if ($previousAssignee->email) {
                    Mail::to($previousAssignee->email)->queue(new ReassignedTask($task));
                }
            }
        }

        return $this->response(true, 'success', \App\Http\Resources\V1\TaskResource::make($task->fresh('assignee'))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_TASK)) {
            abort(403);
        }

        $task = Task::findOrFail($id);
        $task->deleted_by = auth()->user()->id;
        $task->save();

        $task->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * UI-20: bulk delete — see CasesController::destroyMany for the
     * general pattern this mirrors.
     */
    public function destroyMany(\App\Http\Requests\Task\DestroyManyRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_TASK)) {
            abort(403);
        }

        $tasks = Task::whereIn('id', $request->input('ids'))->get();
        foreach ($tasks as $task) {
            $task->deleted_by = auth()->user()->id;
            $task->save();
            $task->delete();
        }

        return $this->response(true, 'success', ['deleted' => $tasks->pluck('id')], 200);
    }

    /**
     * UI-24: undo for the toast shown right after destroy().
     */
    public function restore($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_TASK)) {
            abort(403);
        }

        $task = Task::withTrashed()->findOrFail($id);
        $task->restore();
        $task->deleted_by = null;
        $task->save();

        return $this->response(true, 'success', $task, 200);
    }

    public function assigned(): JsonResponse
    {
        $query = Task::with('assignee')->where('assigned_to', Auth::user()->id)->orderBy('id', 'desc');

        $tasks = $this->paginatedOrFull(request(), $query, function ($row) {
            return [
                'id' => $row->id,
                'description' => $row->description,
                'title' => $row->title,
                'assigned_to' => $row->assigned_to,
                'advocate' => $row->assignee->name ?? 'Unknown user',
                'due_date' => Carbon::parse($row->due_date)->format('d/m/Y'),
                'priority' => $row->priority,
                'status' => $row->status,
                'task_status' => $row->task_status,
            ];
        });

        return $this->response(true, 'success', $tasks, 200);
    }
}

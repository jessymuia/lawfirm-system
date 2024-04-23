<?php

namespace App\Http\Controllers;

use App\Mail\AssignedTask;
use App\Mail\ReassignedTask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class TaskController extends Controller
{
    private function response(bool $success, string $message,$data , int $statusCode):JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message'=>$message,
            'data' => $data
        ], $statusCode);
    }
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        if(Auth::user()->hasPermissionTo('create-roles')) {
            $tasks = Task::all();
        } else {
            $tasks = Task::where("assigned_to" , \auth()->user()->id)->get();
        }

        $tasks = $tasks->map(function ($row) {
           return [
               "id" => $row->id,
               "description" => $row->description,
               "title" => $row->title,
               "assigned_to" => $row->assigned_to,
               "advocate" => User::find($row->assigned_to)->name,
               "due_date" => Carbon::parse($row->due_date)->format('d/m/Y'),
               "priority" => $row->priority,
               "status" => $row->status
           ];
        });

        return $this->response(true,'success',$tasks, 200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $task = Task::create([
            "description" => $request->description,
            "title" => $request->title,
            "assigned_to" => $request->assigned_to,
            "due_date" => Carbon::createFromFormat('d/m/Y', $request->due_date)->format('Y-m-d'),
            "priority" => $request->priority,
            "status" => $request->status
        ]);

        if($task) {
            $user = User::find($request->assigned_to);

            Mail::to($user->email)->send(new AssignedTask($task));
        }

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\Response
     */
    public function show(Task $task)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\Response
     */
    public function edit(Task $task)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Task  $task
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $task = Task::find($id);
        $orig = $task->assigned_to;
        $task = $task->update([
            "description" => $request->description,
            "title" => $request->title,
            "assigned_to" => $request->assigned_to,
            "due_date" => Carbon::createFromFormat('d/m/Y', $request->due_date)->format('Y-m-d'),
            "priority" => $request->priority,
            "status" => $request->status
        ]);

        if($task && $orig != $task->assigned_to) {
            $user = User::find($request->assigned_to);

            Mail::to($user->email)->send(new AssignedTask($task));
            $user = User::find($orig);
            Mail::to($user->email)->send(new ReassignedTask($task));
        }


        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\Response
     */
    public function destroy(Task $task)
    {
        //
    }

    public function assigned(): JsonResponse
    {
        $tasks = Task::where("assigned_to", Auth::user()->id)->get();
        $tasks = $tasks->map(function ($row) {
            return [
                "id" => $row->id,
                "description" => $row->description,
                "title" => $row->title,
                "assigned_to" => $row->assigned_to,
                "advocate" => User::find($row->assigned_to)->name,
                "due_date" => Carbon::parse($row->due_date)->format('d/m/Y'),
                "priority" => $row->priority,
                "status" => $row->status
            ];
        });

        return $this->response(true,'success',$tasks, 200);
    }
}

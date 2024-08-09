<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DocumentController extends Controller
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
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

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

        if(!Auth::user()->hasPermissionTo('create-cases'))
            abort(403);

        $request->validate([
            'file' => 'required|mimes:pdf'
        ]);

        $file = $request->file('file');

        // Generate a random name for the file
        $filename = $file->getClientOriginalName();
        $randomName = uniqid() . '_' . $filename;

        // Store the file in storage/app/cases/docs
        $filePath = $file->storeAs('cases/docs', $randomName);

        // Save the document record in the database
        $document = Document::create([
            'title' => $request->input('title'),
            'case_id' => $request->input('case_id'),
            'filename' => $filename,
            'filepath' => $filePath,
            'full_path' => $filePath,
            'mimetype' => $file->getClientMimeType(),
            'filesize' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
            "created_by" => auth()->user()->id
        ]);

        return $this->response(true,'success',$document,200);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Document  $document
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $documents = Document::where("case_id",$id)->get();
        $documents = $documents->map(function($row) {
            return [
                "id" => $row->id,
                "title" => $row->title,
                "filename" => $row->filename,
                "full_path" => $row->full_path
            ];
        });

        return  $this->response(true,'success',$documents,200);

    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\Response
     */
    public function edit(Document $document)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Document $document)
    {
        //
    }

    public function destroy($id)
    {
        if(!Auth::user()->hasPermissionTo('delete-cases'))
            abort(403);

        $document = Document::find($id);
        $document->deleted_by = \auth()->user()->id;
        $document->save();

        $document->delete();


        return $this->response(true,'success',null,200);
    }
}

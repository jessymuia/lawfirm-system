<?php

namespace App\Http\Controllers;

use App\Actions\Documents\StoreDocument;
use App\Documents\DocxTemplate;
use App\Documents\TemplateFields;
use App\Http\Requests\DocumentTemplate\AssembleRequest;
use App\Http\Requests\DocumentTemplate\StoreRequest;
use App\Models\Cases;
use App\Models\Document;
use App\Security\SecurityPolicy;
use App\Tenancy\FirmStorage;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** FUN-14: the firm's document templates, and matter documents made from them. */
class DocumentTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('document_templates')->whereNull('archived_at')->orderBy('category')->orderBy('name')
            ->get(['id', 'name', 'category', 'description', 'filename', 'fields', 'version', 'updated_at'])
            ->map(function ($t) {
                $fields = json_decode((string) $t->fields, true) ?: [];

                return ['fields' => $fields, 'questions' => TemplateFields::questions($fields), 'unknown_fields' => TemplateFields::unknown($fields)] + (array) $t;
            });

        return $this->response(true, 'success', ['templates' => $rows, 'known_fields' => TemplateFields::KNOWN], 200);
    }

    public function store(StoreRequest $r): JsonResponse
    {
        [$disk, $path, $fields] = $this->keep($r->file('file'));
        $id = DB::table('document_templates')->insertGetId([
            'name' => $r->validated('name'), 'category' => $r->validated('category'), 'description' => $r->validated('description'),
            'disk' => $disk, 'path' => $path, 'filename' => $r->file('file')->getClientOriginalName(), 'fields' => json_encode($fields),
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('document_template_created', 'document_template', $id, [], ['name' => $r->validated('name')]);

        return $this->response(true, 'Template added.', ['id' => $id, 'fields' => $fields, 'unknown_fields' => TemplateFields::unknown($fields)], 201);
    }

    public function update(StoreRequest $r, int $template): JsonResponse
    {
        $t = DB::table('document_templates')->where('id', $template)->whereNull('archived_at')->first();
        abort_if(! $t, 404);
        $set = ['name' => $r->validated('name'), 'category' => $r->validated('category'), 'description' => $r->validated('description'),
            'updated_by' => Auth::id(), 'updated_at' => now()];
        $fields = json_decode((string) $t->fields, true);
        if ($r->hasFile('file')) {
            [$disk, $path, $fields] = $this->keep($r->file('file'));
            // documents already made keep their own copy; the old template file is kept for the record
            $set += ['disk' => $disk, 'path' => $path, 'filename' => $r->file('file')->getClientOriginalName(), 'fields' => json_encode($fields), 'version' => $t->version + 1];
        }
        DB::table('document_templates')->where('id', $t->id)->update($set);
        SecurityPolicy::audit('document_template_updated', 'document_template', (int) $t->id, ['version' => $t->version], ['version' => $set['version'] ?? $t->version]);

        return $this->response(true, 'Template saved.', ['id' => (int) $t->id, 'fields' => $fields, 'unknown_fields' => TemplateFields::unknown($fields)], 200);
    }

    public function archive(int $template): JsonResponse
    {
        $n = DB::table('document_templates')->where('id', $template)->whereNull('archived_at')->update(['archived_at' => now(), 'updated_by' => Auth::id()]);
        abort_if($n === 0, 404);
        SecurityPolicy::audit('document_template_archived', 'document_template', $template, [], []);

        return $this->response(true, 'Template archived.', null, 200);
    }

    public function download(int $template)
    {
        $t = DB::table('document_templates')->where('id', $template)->first();
        abort_if(! $t, 404);

        return Storage::disk($t->disk)->download($t->path, $t->filename);
    }

    /** A new matter document from a template, filled with the matter's details and the answers given. */
    public function assemble(AssembleRequest $r, TemplateFields $values, int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);   // matter access applies
        $t = DB::table('document_templates')->where('id', $r->validated('template_id'))->whereNull('archived_at')->first();
        abort_if(! $t, 404);
        $fields = json_decode((string) $t->fields, true) ?: [];
        $answers = (array) ($r->validated('answers') ?? []);
        $missing = array_values(array_filter(TemplateFields::questions($fields), fn ($q) => trim((string) ($answers[$q] ?? '')) === ''));
        if ($missing) {
            return $this->response(false, 'Please answer every question.', ['missing' => $missing], 422);
        }

        $src = tempnam(sys_get_temp_dir(), 'tpl');
        $out = tempnam(sys_get_temp_dir(), 'doc');
        try {
            file_put_contents($src, Storage::disk($t->disk)->get($t->path));
            DocxTemplate::fill($src, $out, $values->for($m, Auth::user(), $answers));
            $title = $r->validated('title') ?: $t->name.' – '.$m->case_number;
            $filename = Str::slug(Str::limit($title, 80, ''), '_').'.docx';
            $doc = app(StoreDocument::class)->handle((int) $m->id, (string) file_get_contents($out), ['title' => $title, 'filename' => $filename,
                'mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], Auth::user());
        } finally {
            @unlink($src);
            @unlink($out);
        }
        SecurityPolicy::audit('document_assembled', 'document', (int) $doc->id, [], ['template_id' => (int) $t->id, 'template_version' => (int) $t->version, 'case_id' => (int) $m->id]);

        return $this->response(true, 'Document created.', ['id' => (int) $doc->id, 'title' => $doc->title, 'filename' => $filename], 201);
    }

    /** @return array{0: string, 1: string, 2: list<string>} */
    private function keep(UploadedFile $file): array
    {
        try {
            $fields = DocxTemplate::fields($file->getRealPath());
        } catch (\RuntimeException $e) {
            abort(response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422));
        }
        $disk = FirmStorage::disk();
        $path = $file->storeAs(app(TenantContext::class)->storagePrefix('templates'), uniqid().'_'.$file->getClientOriginalName(), $disk);

        return [$disk, (string) $path, $fields];
    }
}

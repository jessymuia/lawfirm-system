<?php

namespace App\Http\Requests\DocumentTemplate;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-14 a Word (.docx) template; on update the file is optional (a new file makes a new version). */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $file = $this->isMethod('post') ? 'required' : 'nullable';

        return [
            'name' => 'required|string|max:191',
            'category' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:500',
            'file' => $file.'|file|extensions:docx|mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/octet-stream|max:20000',
        ];
    }
}

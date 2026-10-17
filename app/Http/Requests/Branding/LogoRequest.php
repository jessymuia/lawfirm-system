<?php

namespace App\Http\Requests\Branding;

use App\Branding\Branding;
use Illuminate\Foundation\Http\FormRequest;

/** UI-13: the firm's logo (PNG, JPEG or WebP; SVG is refused: it can carry script). */
class LogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['logo' => 'required|file|mimetypes:'.implode(',', array_keys(Branding::LOGO_TYPES)).'|max:'.Branding::LOGO_MAX_KB];
    }
}

<?php

namespace App\Http\Requests\Principal;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveBrandReportIdentityRequest extends FormRequest
{
    public const TEXT_FIELDS = ['report_display_name', 'report_address', 'report_contact'];

    public const LONG_TEXT_FIELDS = ['report_header', 'report_footer'];

    public static function languages(): array
    {
        return (array) config('lba.reports.locales', [Brand::REPORT_LANGUAGE_DEFAULT]);
    }

    public function rules(): array
    {
        return [
            ...array_fill_keys(self::TEXT_FIELDS, ['present', 'nullable', 'string', 'max:255']),
            ...array_fill_keys(self::LONG_TEXT_FIELDS, ['present', 'nullable', 'string', 'max:16000']),
            'report_website' => ['present', 'nullable', 'string', 'max:255', 'url:http,https'],
            'report_language' => ['required', 'string', Rule::in(self::languages())],
        ];
    }
}

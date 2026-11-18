<?php

namespace App\Http\Requests\Principal;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveBrandRequest extends FormRequest
{
    public function rules(): array
    {
        $brand = $this->route('brand');
        $uniqueSlug = Rule::unique(Brand::class, 'slug');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', $brand ? $uniqueSlug->ignore($brand) : $uniqueSlug],
            'status' => [$brand ? 'prohibited' : 'required', Rule::in([Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED])],
        ];
    }
}

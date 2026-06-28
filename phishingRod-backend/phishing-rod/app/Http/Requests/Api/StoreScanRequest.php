<?php

namespace App\Http\Requests\Api;

use App\Services\Security\UrlValidatorService;
use Illuminate\Foundation\Http\FormRequest;

class StoreScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'url',
                'starts_with:http://,https://',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $error = app(UrlValidatorService::class)->validate($value);

                    if ($error !== null) {
                        $fail($error);
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'url.starts_with' => 'The URL must begin with http:// or https://.',
        ];
    }
}

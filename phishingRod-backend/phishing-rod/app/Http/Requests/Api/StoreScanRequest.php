<?php

namespace App\Http\Requests\Api;

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
                    $host = parse_url($value, PHP_URL_HOST);

                    if (! $host) {
                        $fail('The URL host could not be parsed.');
                        return;
                    }

                    $host = strtolower($host);

                    if (in_array($host, ['localhost', 'localhost.localdomain'])) {
                        $fail('The URL must not target internal or local addresses.');
                        return;
                    }

                    // If the host is a literal IP, reject private/reserved ranges.
                    $ip = filter_var($host, FILTER_VALIDATE_IP);
                    if ($ip !== false) {
                        $isPublic = filter_var(
                            $ip,
                            FILTER_VALIDATE_IP,
                            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                        );

                        if ($isPublic === false) {
                            $fail('The URL must not target private or reserved IP addresses.');
                        }
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

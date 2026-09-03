<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartExecutionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('startExecution', $this->route('project')) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'configuration_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Debe enviar la cabecera Idempotency-Key.',
            'idempotency_key.regex' => 'La Idempotency-Key contiene caracteres no permitidos.',
        ];
    }
}

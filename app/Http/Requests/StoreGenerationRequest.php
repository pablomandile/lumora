<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    // La seed tiene que entrar en un número de JavaScript sin perder precisión.
    public const MAX_SEED = 9007199254740991;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var array<string, mixed> $aspects */
        $aspects = config('lumora.aspects');
        $max = (int) config('lumora.admin_max_side', 2048);

        return [
            'prompt' => ['required', 'string', 'max:2000'],
            // Solo en una conversación propia; sin ella se abre una nueva.
            'conversation_id' => [
                'nullable', 'string',
                Rule::exists('conversations', 'id')->where('user_id', $this->user()?->id),
            ],
            'model' => ['nullable', 'string', 'max:64'],
            'aspect' => ['nullable', 'string', Rule::in([...array_keys($aspects), 'custom'])],
            'width' => ['nullable', 'required_if:aspect,custom', 'integer', 'min:256', "max:{$max}"],
            'height' => ['nullable', 'required_if:aspect,custom', 'integer', 'min:256', "max:{$max}"],
            'seed' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_SEED],
            'steps' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'prompt' => 'descripción',
            'aspect' => 'relación de aspecto',
            'width' => 'ancho',
            'height' => 'alto',
            'seed' => 'semilla',
            'steps' => 'pasos',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('prompt'))) {
            $this->merge(['prompt' => trim($this->input('prompt'))]);
        }
    }
}

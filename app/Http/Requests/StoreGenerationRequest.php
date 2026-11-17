<?php

namespace App\Http\Requests;

use App\Models\Attachment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGenerationRequest extends FormRequest
{
    // La seed tiene que entrar en un número de JavaScript sin perder precisión.
    public const MAX_SEED = 9007199254740991;

    /** Fotos de celular: unos 10 MB como mucho. Se reducen al guardarlas. */
    public const MAX_UPLOAD_KB = 15 * 1024;

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
            // Imágenes para editar, en este orden: de la galería, ya subidas
            // (otra variante, reintentar) y fotos nuevas. Entre todas, hasta 3.
            'image_ids' => ['nullable', 'array', 'max:'.Attachment::MAX_PER_GENERATION],
            'image_ids.*' => [
                'string', 'distinct',
                Rule::exists('images', 'id')->where('user_id', $this->user()?->id)->whereNull('deleted_at'),
            ],
            'attachment_ids' => ['nullable', 'array', 'max:'.Attachment::MAX_PER_GENERATION],
            'attachment_ids.*' => [
                'string', 'distinct',
                Rule::exists('attachments', 'id')->where('user_id', $this->user()?->id),
            ],
            'images' => ['nullable', 'array', 'max:'.Attachment::MAX_PER_GENERATION],
            'images.*' => ['file', 'image', 'mimes:jpeg,png,webp', 'max:'.self::MAX_UPLOAD_KB],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->referenceCount() > Attachment::MAX_PER_GENERATION) {
                    $validator->errors()->add('images', 'Podés usar hasta '.Attachment::MAX_PER_GENERATION.' imágenes por pedido.');
                }
            },
        ];
    }

    /** Cuántas imágenes de entrada trae el pedido, sumando las tres fuentes. */
    public function referenceCount(): int
    {
        return count((array) $this->input('image_ids', []))
            + count((array) $this->input('attachment_ids', []))
            + count((array) $this->file('images', []));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'images.*.image' => 'Ese archivo no es una imagen.',
            'images.*.mimes' => 'Las imágenes tienen que ser JPG, PNG o WebP.',
            'images.*.max' => 'Cada imagen puede pesar hasta '.(self::MAX_UPLOAD_KB / 1024).' MB.',
            'image_ids.*.exists' => 'Esa imagen ya no está disponible.',
            'attachment_ids.*.exists' => 'Una de las imágenes ya no está disponible.',
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

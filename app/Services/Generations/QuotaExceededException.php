<?php

namespace App\Services\Generations;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public function __construct(public readonly int $quota)
    {
        parent::__construct(
            $quota === 0
                ? 'Tu cuenta no tiene cuota para generar imágenes.'
                : "Llegaste al límite de {$quota} imágenes por hoy. Mañana podés seguir.",
        );
    }
}

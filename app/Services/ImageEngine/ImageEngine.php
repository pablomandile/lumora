<?php

namespace App\Services\ImageEngine;

use App\Services\ImageEngine\Exceptions\GenerationRejectedException;
use App\Services\ImageEngine\Exceptions\GpuUnavailableException;
use App\Services\ImageEngine\Exceptions\RemoteJobNotFoundException;

/**
 * Motor que genera las imágenes. Hoy es el gateway de la PC GPU; el día que
 * Ollama (u otro) sirva, alcanza con otra implementación.
 */
interface ImageEngine
{
    /** Estado de la placa para el indicador. Nunca tira excepciones. */
    public function health(): GpuStatus;

    /**
     * Sube una imagen de entrada (para editar) y devuelve el nombre con que
     * se la referencia en `submit(['images' => [...]])`. Subir la misma
     * imagen dos veces devuelve el mismo nombre.
     *
     * @throws GpuUnavailableException
     * @throws GenerationRejectedException si la placa no la acepta como imagen
     */
    public function uploadInput(string $bytes): string;

    /**
     * Encola un pedido y devuelve el trabajo remoto.
     *
     * @param  array{model: string, prompt: string, negative_prompt?: string|null, width: int, height: int, seed?: int|null, steps?: int|null, images?: list<string>}  $request
     *
     * @throws GpuUnavailableException si la placa no responde (se reintenta más tarde)
     * @throws GenerationRejectedException si el pedido es inválido (no tiene sentido reintentar)
     * @throws RemoteJobNotFoundException si la URL no apunta al gateway (responde 404)
     */
    public function submit(array $request): RemoteJob;

    /**
     * @throws GpuUnavailableException
     * @throws RemoteJobNotFoundException si el gateway se reinició y perdió el trabajo
     */
    public function status(string $remoteId): RemoteJob;

    /**
     * Bytes del PNG terminado.
     *
     * @throws GpuUnavailableException
     * @throws RemoteJobNotFoundException
     */
    public function image(string $remoteId): string;

    /** Avisa que la imagen ya se guardó, para que la PC la borre. No tira excepciones. */
    public function forget(string $remoteId): void;
}

<?php

namespace App\Services\ImageEngine;

use Carbon\CarbonImmutable;

/**
 * Foto del estado de la placa. Se guarda en caché y la lee el indicador.
 */
final readonly class GpuStatus
{
    public const AVAILABLE = 'available';

    public const BUSY = 'busy';

    public const OFFLINE = 'offline';

    /** Responde pero algo anda mal (token, ComfyUI caído, sin configurar). */
    public const ERROR = 'error';

    public function __construct(
        public string $state,
        public string $message,
        public ?int $latencyMs = null,
        public int $queuePending = 0,
        public ?float $avgSeconds = null,
        public ?string $device = null,
        public ?float $vramFreeGb = null,
        public ?float $vramTotalGb = null,
        public ?CarbonImmutable $checkedAt = null,
    ) {}

    public function isOnline(): bool
    {
        return $this->state === self::AVAILABLE || $this->state === self::BUSY;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'online' => $this->isOnline(),
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
            'queue_pending' => $this->queuePending,
            'avg_seconds' => $this->avgSeconds,
            'device' => $this->device,
            'vram_free_gb' => $this->vramFreeGb,
            'vram_total_gb' => $this->vramTotalGb,
            'checked_at' => $this->checkedAt?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            state: (string) ($data['state'] ?? self::OFFLINE),
            message: (string) ($data['message'] ?? ''),
            latencyMs: $data['latency_ms'] ?? null,
            queuePending: (int) ($data['queue_pending'] ?? 0),
            avgSeconds: $data['avg_seconds'] ?? null,
            device: $data['device'] ?? null,
            vramFreeGb: $data['vram_free_gb'] ?? null,
            vramTotalGb: $data['vram_total_gb'] ?? null,
            checkedAt: isset($data['checked_at']) ? CarbonImmutable::parse($data['checked_at']) : null,
        );
    }
}

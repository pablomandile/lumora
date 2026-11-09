<?php

namespace App\Services\ImageEngine;

/**
 * Estado de un trabajo en el gateway (GET /jobs/{id}).
 */
final readonly class RemoteJob
{
    public function __construct(
        public string $id,
        /** queued | running | done | failed */
        public string $status,
        public ?int $seed = null,
        public ?int $steps = null,
        public ?float $progress = null,
        public ?int $position = null,
        public ?string $error = null,
        public ?float $runSeconds = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            status: (string) $data['status'],
            seed: isset($data['seed']) ? (int) $data['seed'] : null,
            steps: isset($data['steps']) ? (int) $data['steps'] : null,
            progress: isset($data['progress']) ? (float) $data['progress'] : null,
            position: isset($data['position']) ? (int) $data['position'] : null,
            error: $data['error'] ?? null,
            runSeconds: isset($data['run_seconds']) ? (float) $data['run_seconds'] : null,
        );
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}

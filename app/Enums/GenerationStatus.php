<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case WaitingGpu = 'waiting_gpu';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::WaitingGpu => 'Esperando la placa',
            self::Running => 'Generando',
            self::Done => 'Lista',
            self::Failed => 'Falló',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}

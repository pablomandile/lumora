<?php

namespace App\Services\ImageEngine\Exceptions;

use RuntimeException;

/** La placa no responde o no está disponible (se reintenta más tarde). */
class GpuUnavailableException extends RuntimeException {}

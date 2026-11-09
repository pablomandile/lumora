<?php

namespace App\Services\ImageEngine\Exceptions;

use RuntimeException;

/** El gateway rechazó el pedido: no tiene sentido reintentarlo. */
class GenerationRejectedException extends RuntimeException {}

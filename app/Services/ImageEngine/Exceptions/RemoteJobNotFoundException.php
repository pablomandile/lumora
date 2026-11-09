<?php

namespace App\Services\ImageEngine\Exceptions;

use RuntimeException;

/** El gateway no conoce el trabajo (por ejemplo, porque se reinició). */
class RemoteJobNotFoundException extends RuntimeException {}

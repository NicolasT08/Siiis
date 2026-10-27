<?php

declare(strict_types=1);

namespace Siiis\Services;

use RuntimeException;

/**
 * Fallo de Cloudinary (configuración, red o API). El mensaje no incluye credenciales.
 */
final class CloudinaryException extends RuntimeException
{
}

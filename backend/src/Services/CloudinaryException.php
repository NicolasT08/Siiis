<?php

declare(strict_types=1);

namespace Siiis\Services;

use RuntimeException;

/**
 * Fallo de Cloudinary (configuración, red o API). El mensaje no incluye credenciales. Si Cloudinary rechaza el
 * archivo por inválido o dañado, se lanza la subclase ArchivoRechazadoException (415, no 502).
 */
class CloudinaryException extends RuntimeException
{
}

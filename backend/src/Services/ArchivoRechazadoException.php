<?php

declare(strict_types=1);

namespace Siiis\Services;

/**
 * Cloudinary rechazó el archivo porque está dañado o no es del formato que dice ser (p. ej. "Invalid PDF file").
 * Es un problema del archivo del usuario, no de Cloudinary: la API responde 415 UNSUPPORTED_MEDIA_TYPE en lugar
 * de 502 CLOUDINARY_UNAVAILABLE.
 */
final class ArchivoRechazadoException extends CloudinaryException
{
}

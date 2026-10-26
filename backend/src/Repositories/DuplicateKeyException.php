<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use RuntimeException;

/**
 * Violación de un índice único (código 11000 de MongoDB), p. ej. `Credenciales.correo`.
 */
final class DuplicateKeyException extends RuntimeException
{
}

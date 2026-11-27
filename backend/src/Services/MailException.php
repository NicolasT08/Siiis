<?php

declare(strict_types=1);

namespace Siiis\Services;

use RuntimeException;

/**
 * Fallo del proveedor de correo (SMTP). El mensaje no incluye credenciales.
 */
final class MailException extends RuntimeException
{
}

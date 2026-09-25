<?php

declare(strict_types=1);

namespace Siiis\Security;

use RuntimeException;

/**
 * JWT con firma inválida, expirado, mal formado o con claims incompletos.
 */
final class InvalidTokenException extends RuntimeException
{
}

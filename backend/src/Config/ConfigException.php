<?php

declare(strict_types=1);

namespace Siiis\Config;

use RuntimeException;

/**
 * Configuración inválida o incompleta al arrancar. El mensaje nunca incluye valores secretos.
 */
final class ConfigException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Logger mínimo a archivo: storage/logs/app-YYYY-MM-DD.log (spec 10.13.10).
 * Nunca registrar contraseñas, tokens ni contenido sensible.
 */
class Logger
{
    public function __construct(private readonly string $logDirectory)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $line = sprintf('[%s] %s: %s', $now->format(DATE_ATOM), $level, $message);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if (!is_dir($this->logDirectory)) {
            @mkdir($this->logDirectory, 0775, true);
        }
        $file = sprintf('%s/app-%s.log', $this->logDirectory, $now->format('Y-m-d'));
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }
}

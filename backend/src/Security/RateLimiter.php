<?php

declare(strict_types=1);

namespace Siiis\Security;

use Siiis\Support\Clock;
use Siiis\Support\Logger;

/**
 * Rate limiting de ventana fija con contadores en archivos de storage/ratelimit/ (spec 7.2).
 * Un archivo por (acción, clave); la clave suele ser la IP. Si el disco falla, deja pasar
 * la petición y lo registra (fail-open) para no tumbar el login.
 */
class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly Clock $clock,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Registra un intento y devuelve 0 si está permitido, o los segundos que faltan para
     * reintentar si se superó el límite.
     */
    public function hit(string $action, string $key, int $max, int $windowSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->logger?->error('RateLimiter: no se pudo crear el directorio', ['dir' => $this->directory]);

            return 0;
        }

        $file = sprintf('%s/%s.json', $this->directory, hash('sha256', $action . '|' . $key));
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            $this->logger?->error('RateLimiter: no se pudo abrir el archivo de contador', ['action' => $action]);

            return 0;
        }

        try {
            flock($handle, LOCK_EX);
            $data = json_decode((string) stream_get_contents($handle), true);
            $start = is_array($data) && is_int($data['start'] ?? null) ? $data['start'] : $now;
            $count = is_array($data) && is_int($data['count'] ?? null) ? $data['count'] : 0;

            if ($now - $start >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }

            if ($count >= $max) {
                return max(1, $start + $windowSeconds - $now);
            }

            $count++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['start' => $start, 'count' => $count]));
            fflush($handle);

            return 0;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Borra contadores con ventana vencida (para un cron de mantenimiento).
     */
    public function purge(int $olderThanSeconds): int
    {
        $deleted = 0;
        $limit = $this->clock->now()->getTimestamp() - $olderThanSeconds;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $limit && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}

<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Siiis\Services\CloudinaryException;
use Siiis\Services\CloudinaryService;

/**
 * Cloudinary en memoria: registra subidas y borrados, y puede simular fallos.
 */
final class FakeCloudinaryService extends CloudinaryService
{
    /** @var list<array{path: string, folder: string, public_id: string}> */
    public array $subidas = [];

    /** @var list<string> */
    public array $borrados = [];

    public bool $fallarSubida = false;
    public bool $fallarBorrado = false;

    public function __construct()
    {
    }

    public function upload(string $path, string $folder): array
    {
        if ($this->fallarSubida) {
            throw new CloudinaryException('Fallo simulado de subida');
        }

        $publicId = $folder . '/' . bin2hex(random_bytes(6));
        $this->subidas[] = ['path' => $path, 'folder' => $folder, 'public_id' => $publicId];

        return ['url' => 'https://res.cloudinary.com/demo/image/upload/' . $publicId . '.png', 'public_id' => $publicId];
    }

    public function delete(string $publicId): void
    {
        if ($this->fallarBorrado) {
            throw new CloudinaryException('Fallo simulado de borrado');
        }
        $this->borrados[] = $publicId;
    }
}

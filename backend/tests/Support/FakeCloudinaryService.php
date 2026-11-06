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
    /** @var list<array{path: string, folder: string, public_id: string, formatos: list<string>, transformacion: array<string, int|string>, privado: bool}> */
    public array $subidas = [];

    /** @var list<string> */
    public array $borrados = [];

    /** @var list<string> public_id borrados como recurso privado */
    public array $borradosPrivados = [];

    public bool $fallarSubida = false;
    public bool $fallarBorrado = false;

    /** Si es mayor que 0, la subida número N (empezando en 1) falla; las demás funcionan. */
    public int $fallarEnSubida = 0;

    public function __construct()
    {
    }

    public function upload(string $path, string $folder, array $formatos = self::FORMATOS_IMAGEN, array $transformacion = []): array
    {
        return $this->registrar($path, $folder, $formatos, $transformacion, false);
    }

    public function uploadPrivado(string $path, string $folder, array $formatos, array $transformacion = []): array
    {
        return $this->registrar($path, $folder, $formatos, $transformacion, true);
    }

    public function enlacePrivado(string $publicId, string $formato, int $expira): string
    {
        return sprintf(
            'https://api.cloudinary.com/v1_1/demo/image/download?public_id=%s&format=%s&expires_at=%d&signature=firma',
            rawurlencode($publicId),
            $formato,
            $expira,
        );
    }

    public function delete(string $publicId, bool $privado = false): void
    {
        if ($this->fallarBorrado) {
            throw new CloudinaryException('Fallo simulado de borrado');
        }
        $this->borrados[] = $publicId;
        if ($privado) {
            $this->borradosPrivados[] = $publicId;
        }
    }

    /**
     * @param list<string>              $formatos
     * @param array<string, int|string> $transformacion
     *
     * @return array{url: string, public_id: string}
     */
    private function registrar(string $path, string $folder, array $formatos, array $transformacion, bool $privado): array
    {
        if ($this->fallarSubida || ($this->fallarEnSubida > 0 && count($this->subidas) + 1 === $this->fallarEnSubida)) {
            throw new CloudinaryException('Fallo simulado de subida');
        }

        $publicId = $folder . '/' . bin2hex(random_bytes(6));
        $this->subidas[] = [
            'path' => $path,
            'folder' => $folder,
            'public_id' => $publicId,
            'formatos' => $formatos,
            'transformacion' => $transformacion,
            'privado' => $privado,
        ];
        $extension = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'pdf',
            'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
        $tipo = $privado ? 'authenticated' : 'upload';

        return ['url' => 'https://res.cloudinary.com/demo/image/' . $tipo . '/' . $publicId . '.' . $extension, 'public_id' => $publicId];
    }
}

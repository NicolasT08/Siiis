<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Support\ApiException;
use Siiis\Tests\Support\TestSettings;
use Siiis\Validation\MultimediaValidator;

final class MultimediaValidatorTest extends TestCase
{
    private function v(): MultimediaValidator
    {
        return new MultimediaValidator(TestSettings::make());
    }

    /**
     * @return array<string, string>
     */
    private function errores(callable $accion): array
    {
        try {
            $accion();
        } catch (ApiException $e) {
            return $e->fields + ['_codigo' => $e->errorCode];
        }

        return [];
    }

    public function testCrearConOrdenComoTextoDelFormulario(): void
    {
        self::assertSame(
            ['seccion' => 'video_presentacion', 'descripcion' => 'Video del semillero', 'orden' => 3],
            $this->v()->crear(['seccion' => 'video_presentacion', 'descripcion' => " Video del\r\nsemillero ", 'orden' => '3']),
        );
        self::assertNull($this->v()->crear(['seccion' => 'slider_principal', 'descripcion' => 'x', 'orden' => ''])['orden']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function ordenesInvalidos(): iterable
    {
        yield 'cero' => ['0', 'orden'];
        yield 'negativo' => ['-1', 'orden'];
        yield 'decimal' => ['1.5', 'orden'];
        yield 'demasiado alto' => ['1001', 'orden'];
        yield 'arreglo (inyección)' => [['$gt' => 0], 'orden'];
    }

    #[DataProvider('ordenesInvalidos')]
    public function testOrdenInvalido(mixed $orden, string $campo): void
    {
        self::assertArrayHasKey($campo, $this->errores(fn () => $this->v()->crear(['seccion' => 'slider_principal', 'descripcion' => 'x', 'orden' => $orden])));
    }

    public function testActualizarSoloCamposPermitidos(): void
    {
        self::assertSame(['orden' => 2], $this->v()->actualizar(['orden' => 2, 'url' => 'https://evil.example', 'public_id' => 'x']));
        self::assertSame('VALIDATION_ERROR', $this->errores(fn () => $this->v()->actualizar(['seccion' => 'galeria']))['_codigo']);
        self::assertArrayHasKey('descripcion', $this->errores(fn () => $this->v()->actualizar(['descripcion' => ''])));
        self::assertArrayHasKey('orden', $this->errores(fn () => $this->v()->actualizar(['orden' => 2.0])));
    }

    public function testReordenar(): void
    {
        $ids = ['650f1c2e8b3a4a0012345678', '650f1c2e8b3a4a0012345679'];
        $r = $this->v()->reordenar(['seccion' => 'slider_principal', 'ids' => $ids]);
        self::assertSame($ids, array_map('strval', $r['ids']));

        foreach ([[], ['no-es-id'], [$ids[0], $ids[0]], ['a' => $ids[0]], [['$ne' => 1]]] as $malos) {
            self::assertArrayHasKey('ids', $this->errores(fn () => $this->v()->reordenar(['seccion' => 'slider_principal', 'ids' => $malos])));
        }
        self::assertArrayHasKey('seccion', $this->errores(fn () => $this->v()->reordenar(['seccion' => 'galeria', 'ids' => $ids])));
    }
}

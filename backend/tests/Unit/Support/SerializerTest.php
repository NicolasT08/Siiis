<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\TestCase;
use Siiis\Support\Serializer;

final class SerializerTest extends TestCase
{
    public function testConvierteTiposBsonYRenombraId(): void
    {
        $id = new ObjectId('650f1c2e8b3a4a0012345678');
        $fecha = new UTCDateTime(new \DateTimeImmutable('2026-09-23T15:00:00Z'));

        $result = Serializer::serialize([
            '_id' => $id,
            'usuario_id' => $id,
            'fecha' => $fecha,
            'lista' => [['_id' => $id]],
            'activo' => true,
            'orden' => 3,
        ]);

        self::assertSame([
            'id' => '650f1c2e8b3a4a0012345678',
            'usuario_id' => '650f1c2e8b3a4a0012345678',
            'fecha' => '2026-09-23T15:00:00Z',
            'lista' => [['id' => '650f1c2e8b3a4a0012345678']],
            'activo' => true,
            'orden' => 3,
        ], $result);
    }

    public function testEliminaCamposSensiblesEnCualquierNivel(): void
    {
        $result = Serializer::serialize([
            'correo' => 'a@b.co',
            'password' => '$2y$10$hash',
            'token' => 'abc',
            'token_recuperacion' => 'def',
            'token_expiracion' => new UTCDateTime(),
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/x.jpg', 'public_id' => 'x'],
        ]);

        self::assertSame([
            'correo' => 'a@b.co',
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/x.jpg'],
        ], $result);
    }

    public function testFormateaFechasEnUtc(): void
    {
        $bogota = new \DateTimeImmutable('2026-09-23 10:00:00', new \DateTimeZone('America/Bogota'));

        self::assertSame('2026-09-23T15:00:00Z', Serializer::serialize($bogota));
    }
}

<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use Psr\Http\Message\ResponseInterface;

/**
 * GET /api/v1/home/director (DEC-B67): director del semillero buscado por DIRECTOR_CORREO, sin exponer el correo.
 */
final class HomeDirectorApiTest extends AuthTestCase
{
    private const DIRECTOR = 'director.semillero@uptc.edu.co';

    private function director(string $correo): ResponseInterface
    {
        return $this->authApp(['DIRECTOR_CORREO' => $correo])->handle($this->request('GET', '/api/v1/home/director'));
    }

    public function testDevuelveLaVistaPublicaDelDirector(): void
    {
        [, $usuarioId] = $this->db->crearCuenta(self::DIRECTOR, self::PASSWORD, [], [
            'nombre' => 'Edmundo Arturo',
            'apellido' => 'Junco',
            'rol' => 'docente_director',
        ]);

        // El correo de la variable se normaliza (espacios y mayúsculas).
        $response = $this->director('  Director.Semillero@UPTC.edu.co ');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'id' => (string) $usuarioId,
            'nombre' => 'Edmundo Arturo',
            'apellido' => 'Junco',
            'foto_perfil' => ['url' => 'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/ana.jpg'],
        ], $this->json($response)['director']);
    }

    public function testNoExponeElCorreoNiDatosPrivados(): void
    {
        $this->db->crearCuenta(self::DIRECTOR, self::PASSWORD, [], ['telefono' => '3001234567']);

        $body = (string) $this->director(self::DIRECTOR)->getBody();

        foreach (['correo', self::DIRECTOR, 'uptc.edu.co', 'password', 'public_id', 'telefono', '3001234567', 'estado'] as $privado) {
            self::assertStringNotContainsString($privado, $body);
        }
    }

    public function testVariableVaciaResponde404(): void
    {
        $this->db->crearCuenta(self::DIRECTOR, self::PASSWORD);

        $this->assertError($this->director(''), 404, 'NOT_FOUND');
        self::assertSame([], $this->credenciales->consultas, 'Con la variable vacía no se consulta la base de datos.');
    }

    public function testCorreoSinCuentaResponde404(): void
    {
        $this->db->crearCuenta(self::CORREO, self::PASSWORD);

        $this->assertError($this->director(self::DIRECTOR), 404, 'NOT_FOUND');
    }

    public function testUsuarioInactivoResponde404(): void
    {
        $this->db->crearCuenta(self::DIRECTOR, self::PASSWORD, [], ['estado' => false]);

        $this->assertError($this->director(self::DIRECTOR), 404, 'NOT_FOUND');
    }

    public function testCuentaBloqueadaResponde404(): void
    {
        $this->db->crearCuenta(self::DIRECTOR, self::PASSWORD, ['estado_cuenta' => 'bloqueada']);

        $this->assertError($this->director(self::DIRECTOR), 404, 'NOT_FOUND');
    }
}

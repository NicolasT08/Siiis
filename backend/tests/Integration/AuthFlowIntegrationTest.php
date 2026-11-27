<?php

declare(strict_types=1);

namespace Siiis\Tests\Integration;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Siiis\Config\Application;
use Siiis\Database\Collections;
use Siiis\Database\Mongo;
use Siiis\Services\MailService;
use Siiis\Tests\Support\FakeMailService;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Flujo completo de autenticación contra Atlas (base de pruebas), con el correo simulado.
 */
final class AuthFlowIntegrationTest extends IntegrationTestCase
{
    private const PASSWORD = 'Clave-Integracion-2026';

    private string $correo;
    private ObjectId $credId;
    private ObjectId $usuarioId;
    private FakeMailService $mail;

    /** @var App<\Psr\Container\ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->correo = self::correoUnico();
        $this->credId = new ObjectId();
        $this->usuarioId = new ObjectId();
        $this->mongo()->collection(Collections::CREDENCIALES)->insertOne([
            '_id' => $this->credId, 'correo' => $this->correo,
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'usuario_id' => $this->usuarioId, 'estado_cuenta' => 'activa',
            'token_recuperacion' => null, 'token_expiracion' => null, 'intentos_fallidos' => 0,
            'fecha_creacion' => new UTCDateTime(), 'ultimo_acceso' => null,
        ]);
        $this->mongo()->collection(Collections::USUARIOS)->insertOne([
            '_id' => $this->usuarioId, 'credencial_id' => $this->credId, 'nombre' => 'Integración',
            'apellido' => 'SIIIS', 'rol' => 'estudiante', 'foto_perfil' => null, 'estado' => true,
            'fecha_ingreso' => new UTCDateTime(),
        ]);

        $this->mail = new FakeMailService();
        $this->app = Application::create($this->settings(), [
            Mongo::class => $this->mongo(),
            MailService::class => $this->mail,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->usuarioId)) {
            return;
        }
        $this->mongo()->collection(Collections::SESIONES)->deleteMany(['usuario_id' => $this->usuarioId]);
        $this->mongo()->collection(Collections::USUARIOS)->deleteOne(['_id' => $this->usuarioId]);
        $this->mongo()->collection(Collections::CREDENCIALES)->deleteOne(['_id' => $this->credId]);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null, ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, "/api/v1$path", ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)]);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer $token");
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testHealth(): void
    {
        self::assertSame(['status' => 'ok', 'db' => 'ok'], self::json($this->call('GET', '/health')));
    }

    public function testLoginMeLogoutMe(): void
    {
        $login = $this->call('POST', '/auth/login', ['correo' => strtoupper($this->correo), 'password' => self::PASSWORD]);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $token = self::json($login)['token'];
        self::assertIsString($token);

        $sesion = $this->mongo()->collection(Collections::SESIONES)->findOne(['usuario_id' => $this->usuarioId]);
        self::assertIsArray($sesion);
        self::assertTrue($sesion['activa']);
        self::assertSame(64, strlen($sesion['token']));

        $me = $this->call('GET', '/auth/me', null, $token);
        self::assertSame(200, $me->getStatusCode());
        self::assertSame((string) $this->usuarioId, self::json($me)['usuario']['id']);

        self::assertSame(204, $this->call('POST', '/auth/logout', null, $token)->getStatusCode());
        self::assertSame(401, $this->call('GET', '/auth/me', null, $token)->getStatusCode());
    }

    public function testForgotYResetDePuntaAPunta(): void
    {
        $token = self::json($this->call('POST', '/auth/login', ['correo' => $this->correo, 'password' => self::PASSWORD]))['token'];

        self::assertSame(200, $this->call('POST', '/auth/forgot-password', ['correo' => $this->correo])->getStatusCode());
        $resetToken = $this->mail->ultimoToken();

        $cred = $this->mongo()->collection(Collections::CREDENCIALES)->findOne(['_id' => $this->credId]);
        self::assertIsArray($cred);
        self::assertSame(hash('sha256', $resetToken), $cred['token_recuperacion']);

        $reset = $this->call('POST', '/auth/reset-password', ['token' => $resetToken, 'password' => 'Nueva-Clave-2026']);
        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());

        self::assertSame(401, $this->call('GET', '/auth/me', null, $token)->getStatusCode());
        self::assertSame(401, $this->call('POST', '/auth/login', ['correo' => $this->correo, 'password' => self::PASSWORD])->getStatusCode());
        self::assertSame(200, $this->call('POST', '/auth/login', ['correo' => $this->correo, 'password' => 'Nueva-Clave-2026'])->getStatusCode());
        self::assertSame(400, $this->call('POST', '/auth/reset-password', ['token' => $resetToken, 'password' => 'Otra-Clave-2026'])->getStatusCode());
    }
}

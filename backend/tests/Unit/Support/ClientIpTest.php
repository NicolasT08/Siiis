<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Siiis\Middleware\ClientIpMiddleware;
use Siiis\Support\ClientIp;
use Siiis\Tests\Support\RecordingHandler;
use Siiis\Tests\Support\TestSettings;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpTest extends TestCase
{
    public function testSinProxiesIgnoraXForwardedFor(): void
    {
        $request = $this->request('203.0.113.10', '198.51.100.1');

        self::assertSame('203.0.113.10', ClientIp::resolve($request, []));
        self::assertSame('203.0.113.10', ClientIp::from($request));
    }

    public function testRemoteAddrInvalidaEsDesconocida(): void
    {
        self::assertSame(ClientIp::DESCONOCIDA, ClientIp::resolve($this->request('no-es-ip'), ['10.0.0.1']));
        self::assertSame(ClientIp::DESCONOCIDA, ClientIp::resolve($this->request(null), []));
    }

    public function testSiRemoteAddrNoEsDeConfianzaIgnoraLaCabecera(): void
    {
        $request = $this->request('203.0.113.10', '198.51.100.1');

        self::assertSame('203.0.113.10', ClientIp::resolve($request, ['10.0.0.0/8']));
    }

    public function testDesdeUnProxyDeConfianzaUsaLaIpDelCliente(): void
    {
        $request = $this->request('10.1.2.3', '198.51.100.1');

        self::assertSame('198.51.100.1', ClientIp::resolve($request, ['10.0.0.0/8']));
    }

    public function testRecorreDeDerechaAIzquierdaYNoCreeLoQueAgregaElCliente(): void
    {
        // El cliente envió "1.1.1.1" falso; el proxy de borde (10.0.0.5) agregó la IP real y el interno, 10.1.2.3.
        $request = $this->request('10.1.2.3', '1.1.1.1, 198.51.100.1, 10.0.0.5');

        self::assertSame('198.51.100.1', ClientIp::resolve($request, ['10.0.0.0/8']));
    }

    public function testCabeceraVaciaOMalformadaUsaLaIpDelProxy(): void
    {
        self::assertSame('10.1.2.3', ClientIp::resolve($this->request('10.1.2.3'), ['10.0.0.0/8']));
        self::assertSame('10.1.2.3', ClientIp::resolve($this->request('10.1.2.3', 'basura, 10.0.0.5'), ['10.0.0.0/8']));
        self::assertSame('10.1.2.3', ClientIp::resolve($this->request('10.1.2.3', '10.0.0.5'), ['10.0.0.0/8']));
    }

    public function testIpExactaEIpv6(): void
    {
        self::assertSame('198.51.100.1', ClientIp::resolve($this->request('10.0.0.1', '198.51.100.1'), ['10.0.0.1']));
        self::assertSame('10.0.0.2', ClientIp::resolve($this->request('10.0.0.2', '198.51.100.1'), ['10.0.0.1']));
        self::assertSame(
            '2001:db8:ffff::9',
            ClientIp::resolve($this->request('2001:db8::1', '2001:db8:ffff::9'), ['2001:db8::/48']),
        );
    }

    public function testMascaraQueNoCaeEnUnByteCompleto(): void
    {
        self::assertSame('198.51.100.1', ClientIp::resolve($this->request('172.31.0.1', '198.51.100.1'), ['172.16.0.0/12']));
        self::assertSame('172.32.0.1', ClientIp::resolve($this->request('172.32.0.1', '198.51.100.1'), ['172.16.0.0/12']));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rangos(): iterable
    {
        yield 'ipv4' => ['203.0.113.7', true];
        yield 'cidr ipv4' => ['10.0.0.0/8', true];
        yield 'cidr /0' => ['0.0.0.0/0', true];
        yield 'ipv6' => ['2001:db8::1', true];
        yield 'cidr ipv6' => ['2001:db8::/32', true];
        yield 'prefijo ipv4 > 32' => ['10.0.0.0/33', false];
        yield 'prefijo ipv6 > 128' => ['2001:db8::/129', false];
        yield 'prefijo no numérico' => ['10.0.0.0/x', false];
        yield 'prefijo vacío' => ['10.0.0.0/', false];
        yield 'nombre' => ['proxy.hostinger', false];
        yield 'ip incompleta' => ['10.0.0', false];
    }

    #[DataProvider('rangos')]
    public function testValidaRangos(string $rango, bool $valido): void
    {
        self::assertSame($valido, ClientIp::esRangoValido($rango));
    }

    public function testElMiddlewareDejaLaIpEnElAtributo(): void
    {
        $handler = new RecordingHandler();
        $middleware = new ClientIpMiddleware(TestSettings::make(['TRUSTED_PROXIES' => '10.0.0.0/8']));

        $middleware->process($this->request('10.1.2.3', '198.51.100.1'), $handler);

        self::assertNotNull($handler->received);
        self::assertSame('198.51.100.1', $handler->received->getAttribute(ClientIp::ATTRIBUTE));
        self::assertSame('198.51.100.1', ClientIp::from($handler->received));
    }

    private function request(?string $remote, ?string $forwardedFor = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/health',
            $remote === null ? [] : ['REMOTE_ADDR' => $remote],
        );

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }
}

<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Siiis\Security\GoogleJwksProvider;
use Siiis\Security\GoogleTokenVerifier;
use Siiis\Support\ApiException;
use Siiis\Support\Logger;
use Siiis\Tests\Support\FixedClock;
use Siiis\Tests\Support\GoogleTokens;
use Siiis\Tests\Support\TestSettings;

final class GoogleTokenVerifierTest extends TestCase
{
    private string $dir;
    private FixedClock $clock;
    private int $descargas = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/siiis-google-' . bin2hex(random_bytes(4));
        $this->clock = new FixedClock();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function proveedor(?\Closure $descargar = null): GoogleJwksProvider
    {
        return new GoogleJwksProvider($this->dir . '/jwks.json', $this->clock, new Logger($this->dir), $descargar ?? GoogleTokens::descargador($this->descargas));
    }

    /**
     * @param array<string, string> $env
     */
    private function verificador(array $env = []): GoogleTokenVerifier
    {
        return new GoogleTokenVerifier($this->proveedor(), TestSettings::make($env + ['GOOGLE_CLIENT_ID' => GoogleTokens::CLIENT_ID]), $this->clock);
    }

    private function ahora(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function codigo(callable $accion): string
    {
        try {
            $accion();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return 'SIN_ERROR';
    }

    public function testDevuelveElCorreoNormalizado(): void
    {
        self::assertSame('ana.perez@uptc.edu.co', $this->verificador()->verificar(GoogleTokens::token(' Ana.Perez@UPTC.edu.co ', $this->ahora())));
    }

    public function testAceptaEmisorSinEsquemaYEmailVerifiedComoTexto(): void
    {
        $token = GoogleTokens::token('ana@uptc.edu.co', $this->ahora(), ['iss' => 'accounts.google.com', 'email_verified' => 'true']);

        self::assertSame('ana@uptc.edu.co', $this->verificador()->verificar($token));
    }

    public function testElMargenDeRelojEsDeUnMinuto(): void
    {
        $v = $this->verificador();
        $base = ['iat' => $this->ahora() - 3600];
        self::assertSame('ana@uptc.edu.co', $v->verificar(GoogleTokens::token('ana@uptc.edu.co', $this->ahora(), $base + ['exp' => $this->ahora() - 30])));
        self::assertSame('INVALID_GOOGLE_TOKEN', $this->codigo(fn () => $v->verificar(GoogleTokens::token('ana@uptc.edu.co', $this->ahora(), $base + ['exp' => $this->ahora() - 120]))));
    }

    public function testDominioConfigurable(): void
    {
        $v = $this->verificador(['GOOGLE_DOMINIO_PERMITIDO' => 'ejemplo.edu.co']);

        self::assertSame('GOOGLE_DOMAIN_NOT_ALLOWED', $this->codigo(fn () => $v->verificar(GoogleTokens::token('ana@uptc.edu.co', $this->ahora()))));
        self::assertSame('ana@ejemplo.edu.co', $v->verificar(GoogleTokens::token('ana@ejemplo.edu.co', $this->ahora(), ['hd' => 'ejemplo.edu.co'])));
    }

    public function testHdCorrectoPeroCorreoDeOtroDominio(): void
    {
        self::assertSame('GOOGLE_DOMAIN_NOT_ALLOWED', $this->codigo(fn () => $this->verificador()->verificar(GoogleTokens::token('ana@gmail.com', $this->ahora()))));
    }

    public function testTokenMalFormadoOAlgoritmoDistinto(): void
    {
        $v = $this->verificador();
        $hs256 = \Firebase\JWT\JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => GoogleTokens::CLIENT_ID, 'exp' => $this->ahora() + 60], str_repeat('k', 32), 'HS256', GoogleTokens::KID);

        self::assertSame('INVALID_GOOGLE_TOKEN', $this->codigo(fn () => $v->verificar('a.b.c')));
        self::assertSame('INVALID_GOOGLE_TOKEN', $this->codigo(fn () => $v->verificar($hs256)));
    }

    public function testProveedorUsaLaCopiaHastaQueVenceYLuegoLaRenueva(): void
    {
        $p = $this->proveedor();
        $p->claves();
        $p->claves();
        self::assertSame(1, $this->descargas);

        $this->clock->advance(3601);
        $p->claves();
        self::assertSame(2, $this->descargas);

        $p->claves(true);
        self::assertSame(3, $this->descargas);
    }

    public function testSiGoogleNoRespondeSeUsaLaCopiaVencida(): void
    {
        $this->proveedor()->claves();
        $this->clock->advance(90000);
        $sinRed = $this->proveedor(static fn (): array => throw new \RuntimeException('sin red'));

        self::assertArrayHasKey(GoogleTokens::KID, $sinRed->claves());
    }

    public function testRespuestaSinLlavesNoSeGuarda(): void
    {
        $vacia = $this->proveedor(static fn (): array => ['{"keys": []}', 3600]);

        $this->expectException(\RuntimeException::class);
        $vacia->claves();
    }
}

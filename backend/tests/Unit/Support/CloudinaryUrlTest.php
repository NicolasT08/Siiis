<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siiis\Support\CloudinaryUrl;

/**
 * DEC-B42: la API entrega las imágenes de Cloudinary con f_auto,q_auto y deja todo lo demás igual.
 */
final class CloudinaryUrlTest extends TestCase
{
    private const BASE = 'https://res.cloudinary.com/demo/image/upload/';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'secure_url con versión' => [
            self::BASE . 'v1793933563/siiis/perfiles/abc.png',
            self::BASE . 'f_auto,q_auto/v1793933563/siiis/perfiles/abc.png',
        ];
        yield 'sin versión' => [self::BASE . 'ana.jpg', self::BASE . 'f_auto,q_auto/ana.jpg'];
        yield 'carpeta con guion bajo' => [
            self::BASE . 'siiis_test/perfiles/abc.webp',
            self::BASE . 'f_auto,q_auto/siiis_test/perfiles/abc.webp',
        ];
        yield 'conserva la query' => [self::BASE . 'v1/a.jpg?_a=x', self::BASE . 'f_auto,q_auto/v1/a.jpg?_a=x'];
        yield 'pdf como image' => [self::BASE . 'v1/siiis/documentos/x.pdf', self::BASE . 'v1/siiis/documentos/x.pdf'];
        yield 'pdf en mayúsculas' => [self::BASE . 'v1/x.PDF', self::BASE . 'v1/x.PDF'];
        yield 'ya optimizada' => [self::BASE . 'f_auto,q_auto/v1/a.jpg', self::BASE . 'f_auto,q_auto/v1/a.jpg'];
        yield 'con otra transformación' => [self::BASE . 'c_fill,w_100/v1/a.jpg', self::BASE . 'c_fill,w_100/v1/a.jpg'];
        yield 'video con q_auto' => [
            'https://res.cloudinary.com/demo/video/upload/v1/dog.mp4',
            'https://res.cloudinary.com/demo/video/upload/q_auto/v1/dog.mp4',
        ];
        yield 'video ya optimizado' => [
            'https://res.cloudinary.com/demo/video/upload/q_auto/v1/dog.mp4',
            'https://res.cloudinary.com/demo/video/upload/q_auto/v1/dog.mp4',
        ];
        yield 'raw' => [
            'https://res.cloudinary.com/demo/raw/upload/v1/siiis/documentos/a.pdf',
            'https://res.cloudinary.com/demo/raw/upload/v1/siiis/documentos/a.pdf',
        ];
        yield 'otro dominio' => ['https://evil.example/image/upload/a.jpg', 'https://evil.example/image/upload/a.jpg'];
        yield 'http sin cifrar' => ['http://res.cloudinary.com/demo/image/upload/a.jpg', 'http://res.cloudinary.com/demo/image/upload/a.jpg'];
        yield 'sin recurso' => [self::BASE, self::BASE];
        yield 'vacía' => ['', ''];
    }

    #[DataProvider('urls')]
    public function testEntrega(string $url, string $esperada): void
    {
        self::assertSame($esperada, CloudinaryUrl::entrega($url));
    }

    public function testEntregaNoTocaLosRecursosPrivados(): void
    {
        $url = 'https://res.cloudinary.com/demo/image/authenticated/v1/siiis/inscripciones/a.png';

        self::assertSame($url, CloudinaryUrl::entrega($url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urlsFirmadas(): iterable
    {
        $base = 'https://res.cloudinary.com/demo/image/authenticated/';
        yield 'con firma' => [$base . 's--Q0umMEBC--/v1793969882/siiis/inscripciones/a.pdf', $base . 'v1793969882/siiis/inscripciones/a.pdf'];
        yield 'firma con guion y guion bajo' => [$base . 's--a_B-9xYz--/v1/a.png', $base . 'v1/a.png'];
        yield 'sin firma' => [$base . 'v1/a.png', $base . 'v1/a.png'];
        yield 'pública' => [self::BASE . 'v1/a.png', self::BASE . 'v1/a.png'];
    }

    #[DataProvider('urlsFirmadas')]
    public function testSinFirma(string $url, string $esperada): void
    {
        self::assertSame($esperada, CloudinaryUrl::sinFirma($url));
    }

    public function testFormato(): void
    {
        self::assertSame('pdf', CloudinaryUrl::formato('https://res.cloudinary.com/demo/image/authenticated/v1/a/b.PDF'));
        self::assertSame('jpg', CloudinaryUrl::formato('https://res.cloudinary.com/demo/image/upload/v1/a.jpg?_a=1'));
        self::assertSame('', CloudinaryUrl::formato('https://res.cloudinary.com/demo/image/upload/v1/sin-extension'));
    }
}

<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Siiis\Support\CorreoInstitucional;

final class CorreoInstitucionalTest extends TestCase
{
    private const LOGO = 'https://res.cloudinary.com/demo/image/upload/siiis/logo.png';

    public function testHtmlConEncabezadoBotonEnlaceYPieAutomatico(): void
    {
        $correo = CorreoInstitucional::componer(
            self::LOGO,
            CorreoInstitucional::saludo('Nicolás'),
            ['Texto del correo.'],
            boton: ['Restablecer contraseña', 'https://siiis.com.co/restablecer?token=abc&x=1'],
            avisos: ['Vence en 30 minutos.'],
        );
        $html = $correo['html'];

        self::assertStringContainsString('max-width:600px', $html);
        self::assertStringContainsString('#0B2A4A', $html);
        self::assertStringContainsString('#B7862F', $html);
        self::assertStringContainsString('<img src="' . self::LOGO . '"', $html);
        self::assertStringContainsString('Semillero de Investigación SIIIS', $html);
        self::assertStringContainsString('Universidad Pedagógica y Tecnológica de Colombia', $html);
        self::assertStringContainsString('Estimado(a) Nicolás:', $html);
        // Botón y enlace completo debajo, escapado.
        self::assertSame(2, substr_count($html, 'href="https://siiis.com.co/restablecer?token=abc&amp;x=1"'));
        self::assertStringContainsString('Si el botón no funciona', $html);
        self::assertStringContainsString('Escuela de Ingeniería de Sistemas y Computación', $html);
        self::assertStringContainsString('UPTC Seccional Sogamoso', $html);
        self::assertStringContainsString('href="https://siiis.com.co"', $html);
        self::assertStringContainsString('Este es un correo automático, por favor no lo responda.', $html);
    }

    public function testTextoPlanoConLasMismasPartes(): void
    {
        $texto = CorreoInstitucional::componer(
            '',
            CorreoInstitucional::saludo(''),
            ['Primer párrafo.'],
            detalles: [['Nombre', 'Ana']],
            boton: ['Restablecer contraseña', 'https://siiis.com.co/restablecer?token=abc'],
        )['text'];

        self::assertStringStartsWith("Semillero de Investigación SIIIS\nUniversidad Pedagógica y Tecnológica de Colombia", $texto);
        self::assertStringContainsString('Estimado(a) usuario(a):', $texto);
        self::assertStringContainsString("Restablecer contraseña:\nhttps://siiis.com.co/restablecer?token=abc", $texto);
        self::assertStringContainsString('Nombre: Ana', $texto);
        self::assertStringContainsString('https://siiis.com.co', $texto);
        self::assertStringContainsString('por favor no lo responda', $texto);
        self::assertStringNotContainsString('<', $texto);
    }

    public function testSinLogoOConLogoInvalidoSoloTexto(): void
    {
        foreach (['', 'http://inseguro.test/logo.png', 'javascript:alert(1)'] as $logo) {
            $html = CorreoInstitucional::componer($logo, 'Hola:', ['x'])['html'];
            self::assertStringNotContainsString('<img', $html, $logo);
            self::assertStringContainsString('Semillero de Investigación SIIIS', $html);
        }
    }

    public function testEscapaElContenidoYPermiteResponder(): void
    {
        $correo = CorreoInstitucional::componer(
            '',
            'Estimado equipo:',
            ['<script>x</script>'],
            detalles: [['Nombre', '<b>Ana</b>']],
            mensaje: "Línea 1\n<i>Línea 2</i>",
            respuesta: 'Responda este correo.',
        );

        self::assertStringNotContainsString('<script>', $correo['html']);
        self::assertStringNotContainsString('<b>Ana</b>', $correo['html']);
        self::assertStringContainsString('Línea 1<br />', $correo['html']);
        self::assertStringContainsString('Responda este correo.', $correo['html']);
        self::assertStringNotContainsString('no lo responda', $correo['html']);
        self::assertStringNotContainsString('no lo responda', $correo['text']);
    }
}

<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Plantilla única de los correos del semillero (DEC-B68): HTML con tablas y estilos en línea (Gmail y Outlook),
 * máximo 600 px y adaptable al celular, más su versión en texto plano. Todo el texto que recibe es plano y se
 * escapa aquí; nunca se le pasa HTML.
 */
final class CorreoInstitucional
{
    public const SITIO = 'https://siiis.com.co';

    private const AZUL = '#0B2A4A';
    private const DORADO = '#B7862F';
    private const TEXTO = '#1F2933';
    private const GRIS = '#6B7280';
    private const FONDO = '#F2F4F7';
    private const PIE = '#EEF0F3';
    private const FUENTE = "Arial, 'Helvetica Neue', Helvetica, sans-serif";

    private const NOMBRE = 'Semillero de Investigación SIIIS';
    private const UNIVERSIDAD = 'Universidad Pedagógica y Tecnológica de Colombia';
    private const PIE_TEXTO = 'Semillero de Investigación SIIIS · Escuela de Ingeniería de Sistemas y Computación · '
        . 'UPTC Seccional Sogamoso';
    private const NO_RESPONDER = 'Este es un correo automático, por favor no lo responda.';

    private function __construct()
    {
    }

    /**
     * @param list<string>                $parrafos  párrafos de texto plano (los saltos de línea se respetan)
     * @param list<array{string, string}> $detalles  pares etiqueta/valor (p. ej. datos del remitente)
     * @param string|null                 $mensaje   texto largo citado en un recuadro (p. ej. el mensaje de contacto)
     * @param array{string, string}|null  $boton     [texto, url] del botón de acción, con el enlace completo debajo
     * @param list<string>                $avisos    notas en letra pequeña después del botón
     * @param string|null                 $respuesta texto del pie cuando el correo sí se puede responder
     *
     * @return array{html: string, text: string}
     */
    public static function componer(
        string $logoUrl,
        string $saludo,
        array $parrafos,
        array $detalles = [],
        ?string $mensaje = null,
        ?array $boton = null,
        array $avisos = [],
        ?string $respuesta = null,
    ): array {
        return [
            'html' => self::html($logoUrl, $saludo, $parrafos, $detalles, $mensaje, $boton, $avisos, $respuesta),
            'text' => self::texto($saludo, $parrafos, $detalles, $mensaje, $boton, $avisos, $respuesta),
        ];
    }

    /** "Estimado(a) Nicolás:"; sin nombre, "Estimado(a) usuario(a):". */
    public static function saludo(string $nombre): string
    {
        $nombre = trim($nombre);

        return 'Estimado(a) ' . ($nombre !== '' ? $nombre : 'usuario(a)') . ':';
    }

    /**
     * @param list<string>                $parrafos
     * @param list<array{string, string}> $detalles
     * @param array{string, string}|null  $boton
     * @param list<string>                $avisos
     */
    private static function html(
        string $logoUrl,
        string $saludo,
        array $parrafos,
        array $detalles,
        ?string $mensaje,
        ?array $boton,
        array $avisos,
        ?string $respuesta,
    ): string {
        $f = self::FUENTE;
        $cuerpo = '<p style="margin:0 0 18px;font-family:' . $f . ';font-size:16px;line-height:24px;color:'
            . self::TEXTO . ';font-weight:bold;">' . self::e($saludo) . '</p>';
        foreach ($parrafos as $parrafo) {
            $cuerpo .= self::parrafo($parrafo);
        }
        if ($detalles !== []) {
            $cuerpo .= self::detalles($detalles);
        }
        if ($mensaje !== null) {
            $cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                . 'style="margin:0 0 18px;"><tr><td style="padding:14px 16px;background:#F8F6F1;border-left:4px solid '
                . self::DORADO . ';font-family:' . $f . ';font-size:15px;line-height:23px;color:' . self::TEXTO . ';">'
                . nl2br(self::e($mensaje)) . '</td></tr></table>';
        }
        if ($boton !== null) {
            $cuerpo .= self::boton($boton[0], $boton[1]);
        }
        foreach ($avisos as $aviso) {
            $cuerpo .= '<p style="margin:0 0 10px;font-family:' . $f . ';font-size:13px;line-height:20px;color:'
                . self::GRIS . ';">' . self::e($aviso) . '</p>';
        }
        $cuerpo .= '<p style="margin:22px 0 0;font-family:' . $f . ';font-size:15px;line-height:23px;color:'
            . self::TEXTO . ';">Cordialmente,<br><strong>' . self::e(self::NOMBRE) . '</strong></p>';

        $logo = self::logoValido($logoUrl)
            ? '<img src="' . self::e($logoUrl) . '" width="64" alt="SIIIS" style="display:block;width:64px;'
                . 'max-width:64px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto 12px;">'
            : '';
        $pieRespuesta = self::e($respuesta ?? self::NO_RESPONDER);
        $sitio = self::e(self::SITIO);
        $titulo = self::e(self::NOMBRE);
        $universidad = self::e(self::UNIVERSIDAD);
        $pieTexto = self::e(self::PIE_TEXTO);
        $previa = self::e($parrafos[0] ?? $saludo);
        $azul = self::AZUL;
        $fondo = self::FONDO;
        $pie = self::PIE;
        $gris = self::GRIS;

        return <<<HTML
            <!DOCTYPE html>
            <html lang="es" xmlns="http://www.w3.org/1999/xhtml">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="x-apple-disable-message-reformatting">
            <title>{$titulo}</title>
            <style>
            @media only screen and (max-width: 620px) {
              .contenedor { width: 100% !important; }
              .relleno { padding-left: 20px !important; padding-right: 20px !important; }
            }
            </style>
            </head>
            <body style="margin:0;padding:0;background:{$fondo};">
            <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{$fondo};">{$previa}</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$fondo}" style="background:{$fondo};">
            <tr><td align="center" style="padding:24px 12px;">
            <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" class="contenedor" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#FFFFFF;border-radius:8px;overflow:hidden;">
            <tr><td align="center" bgcolor="{$azul}" class="relleno" style="background:{$azul};padding:28px 32px;border-bottom:4px solid #B7862F;">
            {$logo}<p style="margin:0;font-family:{$f};font-size:20px;line-height:26px;font-weight:bold;color:#FFFFFF;">{$titulo}</p>
            <p style="margin:6px 0 0;font-family:{$f};font-size:13px;line-height:18px;color:#D6DEE8;">{$universidad}</p>
            </td></tr>
            <tr><td class="relleno" style="padding:32px;">
            {$cuerpo}
            </td></tr>
            <tr><td align="center" bgcolor="{$pie}" class="relleno" style="background:{$pie};padding:20px 32px;">
            <p style="margin:0 0 8px;font-family:{$f};font-size:12px;line-height:18px;color:{$gris};">{$pieTexto}</p>
            <p style="margin:0 0 8px;font-family:{$f};font-size:12px;line-height:18px;"><a href="{$sitio}" style="color:{$azul};text-decoration:underline;">siiis.com.co</a></p>
            <p style="margin:0;font-family:{$f};font-size:12px;line-height:18px;color:{$gris};">{$pieRespuesta}</p>
            </td></tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
            </td></tr>
            </table>
            </body>
            </html>
            HTML;
    }

    /**
     * @param list<string>                $parrafos
     * @param list<array{string, string}> $detalles
     * @param array{string, string}|null  $boton
     * @param list<string>                $avisos
     */
    private static function texto(
        string $saludo,
        array $parrafos,
        array $detalles,
        ?string $mensaje,
        ?array $boton,
        array $avisos,
        ?string $respuesta,
    ): string {
        $bloques = [self::NOMBRE . "\n" . self::UNIVERSIDAD, $saludo, ...$parrafos];
        if ($detalles !== []) {
            $bloques[] = implode("\n", array_map(static fn (array $d): string => "{$d[0]}: {$d[1]}", $detalles));
        }
        if ($mensaje !== null) {
            $bloques[] = $mensaje;
        }
        if ($boton !== null) {
            $bloques[] = "{$boton[0]}:\n{$boton[1]}";
        }
        array_push($bloques, ...$avisos);
        $bloques[] = "Cordialmente,\n" . self::NOMBRE;
        $bloques[] = "--\n" . self::PIE_TEXTO . "\n" . self::SITIO . "\n" . ($respuesta ?? self::NO_RESPONDER);

        return implode("\n\n", $bloques);
    }

    private static function parrafo(string $texto): string
    {
        return '<p style="margin:0 0 16px;font-family:' . self::FUENTE . ';font-size:15px;line-height:23px;color:'
            . self::TEXTO . ';">' . nl2br(self::e($texto)) . '</p>';
    }

    /**
     * @param list<array{string, string}> $detalles
     */
    private static function detalles(array $detalles): string
    {
        $filas = '';
        foreach ($detalles as [$etiqueta, $valor]) {
            $filas .= '<tr><td valign="top" style="padding:8px 12px;width:34%;background:#F8F9FB;border-bottom:1px solid '
                . '#E5E7EB;font-family:' . self::FUENTE . ';font-size:14px;line-height:20px;font-weight:bold;color:'
                . self::AZUL . ';">' . self::e($etiqueta) . '</td><td valign="top" style="padding:8px 12px;'
                . 'border-bottom:1px solid #E5E7EB;font-family:' . self::FUENTE . ';font-size:14px;line-height:20px;'
                . 'color:' . self::TEXTO . ';word-break:break-word;">' . self::e($valor) . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="margin:0 0 18px;border:1px solid #E5E7EB;border-collapse:collapse;">' . $filas . '</table>';
    }

    private static function boton(string $texto, string $url): string
    {
        $u = self::e($url);
        $f = self::FUENTE;
        $d = self::DORADO;

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" '
            . 'style="margin:8px auto 18px;"><tr><td align="center" bgcolor="' . $d . '" style="border-radius:6px;'
            . 'background:' . $d . ';"><a href="' . $u . '" target="_blank" style="display:inline-block;padding:'
            . '14px 30px;font-family:' . $f . ';font-size:16px;line-height:20px;font-weight:bold;color:#FFFFFF;'
            . 'text-decoration:none;border-radius:6px;">' . self::e($texto) . '</a></td></tr></table>'
            . '<p style="margin:0 0 18px;font-family:' . $f . ';font-size:13px;line-height:20px;color:' . self::GRIS
            . ';">Si el botón no funciona, copie y pegue este enlace en su navegador:<br><a href="' . $u . '" '
            . 'style="color:' . self::AZUL . ';word-break:break-all;">' . $u . '</a></p>';
    }

    /** Solo URL https: un valor mal escrito en MAIL_LOGO_URL no rompe el correo, se omite el logo. */
    private static function logoValido(string $url): bool
    {
        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

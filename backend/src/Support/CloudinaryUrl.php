<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * URL de entrega de las imágenes de Cloudinary (DEC-B42, PROPUESTA). MongoDB guarda el `secure_url` tal
 * como lo devuelve Cloudinary; la API agrega `f_auto,q_auto` al responder, para que cada navegador reciba el
 * formato y la calidad que le convienen (WebP o AVIF, por ejemplo) sin guardar copias.
 */
final class CloudinaryUrl
{
    /** Transformación de entrega: formato y calidad automáticos. */
    public const ENTREGA = 'f_auto,q_auto';

    private const PATRON = '#^(https://res\.cloudinary\.com/[^/?\#]+/image/upload/)([^?\#]*)(.*)$#';

    /** Un segmento de transformación: `w_400`, `c_fill,g_auto`, `f_auto,q_auto`... */
    private const SEGMENTO_TRANSFORMACION = '#^[a-z]{1,3}_[^,/]+(,[a-z]{1,3}_[^,/]+)*$#';

    private function __construct()
    {
    }

    /**
     * Agrega `f_auto,q_auto` a una imagen de Cloudinary (`/image/upload/`). No toca los PDF (se entregan tal
     * cual, DEC-B40), los videos, los recursos `raw`, las URL de otros dominios ni las que ya traen una
     * transformación.
     */
    public static function entrega(string $url): string
    {
        if (preg_match(self::PATRON, $url, $m) !== 1) {
            return $url;
        }
        [, $base, $ruta, $resto] = $m;
        if ($ruta === '' || preg_match('#\.pdf$#i', $ruta) === 1) {
            return $url;
        }
        $primero = explode('/', $ruta, 2)[0];
        if (preg_match(self::SEGMENTO_TRANSFORMACION, $primero) === 1) {
            return $url;
        }

        return $base . self::ENTREGA . '/' . $ruta . $resto;
    }
}

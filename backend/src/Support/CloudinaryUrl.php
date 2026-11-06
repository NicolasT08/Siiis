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
     * Quita el segmento de firma `/s--XXXXXXXX--/` de una URL de Cloudinary. La `secure_url` de un recurso
     * privado (`authenticated`) ya trae una firma que nunca vence; sin ella la URL responde 401 (DEC-B50).
     */
    public static function sinFirma(string $url): string
    {
        return (string) preg_replace('#/s--[A-Za-z0-9_-]+--/#', '/', $url, 1);
    }

    /**
     * Extensión del archivo al final de la ruta (`pdf`, `png`…), en minúsculas; '' si no tiene.
     */
    public static function formato(string $url): string
    {
        $ruta = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#\.([A-Za-z0-9]{2,5})$#', $ruta, $m) === 1 ? strtolower($m[1]) : '';
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

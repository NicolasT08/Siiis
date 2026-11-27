<?php

declare(strict_types=1);

namespace Siiis\Support;

use MongoDB\BSON\ObjectId;

/**
 * Formas JSON de un documento (DEC-B41). Lista blanca de campos: nunca copia el documento entero ni
 * expone el `public_id` de Cloudinary. El autor y los integrantes se muestran con su vista pública mínima
 * (DEC-B19), aunque estén inactivos (DEC-B38).
 */
final class DocumentoPresenter
{
    private function __construct()
    {
    }

    /**
     * Ids de autor e integrantes de varios documentos, sin repetidos, para resolverlos en una sola consulta.
     *
     * @param list<array<string, mixed>> $documentos
     *
     * @return list<ObjectId>
     */
    public static function usuarioIds(array $documentos): array
    {
        $ids = [];
        foreach ($documentos as $documento) {
            if (($documento['autor_id'] ?? null) instanceof ObjectId) {
                $ids[(string) $documento['autor_id']] = $documento['autor_id'];
            }
            foreach (self::integranteIds($documento) as $id) {
                $ids[(string) $id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed>                $documento
     * @param array<string, array<string, mixed>> $usuarios  resúmenes indexados por id
     *
     * @return array<string, mixed>
     */
    public static function resumen(array $documento, array $usuarios): array
    {
        $autorId = $documento['autor_id'] ?? null;
        $integrantes = [];
        foreach (self::integranteIds($documento) as $id) {
            if (isset($usuarios[(string) $id])) {
                $integrantes[] = self::persona($usuarios[(string) $id]);
            }
        }

        $data = Serializer::serialize([
            '_id' => $documento['_id'] ?? null,
            'categoria' => $documento['categoria'] ?? null,
            'titulo' => $documento['titulo'] ?? null,
            'descripcion' => $documento['descripcion'] ?? null,
            'estado' => $documento['estado'] ?? null,
            'visible' => $documento['visible'] ?? null,
            'autor' => $autorId instanceof ObjectId && isset($usuarios[(string) $autorId])
                ? self::persona($usuarios[(string) $autorId])
                : null,
            'integrantes' => $integrantes,
            'fecha_creacion' => $documento['fecha_creacion'] ?? null,
            'fecha_actualizacion' => $documento['fecha_actualizacion'] ?? null,
        ]);

        return is_array($data) ? $data : [];
    }

    /**
     * Resumen + `contenido` (Markdown), adjuntos y lo que el actor puede hacer con el documento.
     *
     * @param array<string, mixed>                $documento
     * @param array<string, array<string, mixed>> $usuarios
     * @param array{editar: bool, borrar: bool, publicar: bool} $permisos
     *
     * @return array<string, mixed>
     */
    public static function detalle(array $documento, array $usuarios, array $permisos): array
    {
        return self::resumen($documento, $usuarios) + [
            'contenido' => is_string($documento['contenido'] ?? null) ? $documento['contenido'] : null,
            'archivos_adjuntos' => self::adjuntos($documento),
            'permisos' => $permisos,
        ];
    }

    /**
     * Cada adjunto sale con `archivo` (identificador para borrarlo), `url` y `nombre_archivo`. `archivo` es
     * el último segmento del `public_id`; el `public_id` completo nunca sale. Las imágenes salen con
     * `f_auto,q_auto` y los PDF tal cual (DEC-B42).
     *
     * @param array<string, mixed> $documento
     *
     * @return list<array{archivo: string, url: string, nombre_archivo: string|null}>
     */
    public static function adjuntos(array $documento): array
    {
        $adjuntos = [];
        foreach (is_array($documento['archivos_adjuntos'] ?? null) ? $documento['archivos_adjuntos'] : [] as $adjunto) {
            if (!is_array($adjunto) || !is_string($adjunto['url'] ?? null) || !is_string($adjunto['public_id'] ?? null)) {
                continue;
            }
            $adjuntos[] = [
                'archivo' => self::archivoId($adjunto['public_id']),
                'url' => CloudinaryUrl::entrega($adjunto['url']),
                'nombre_archivo' => is_string($adjunto['nombre_archivo'] ?? null) ? $adjunto['nombre_archivo'] : null,
            ];
        }

        return $adjuntos;
    }

    public static function archivoId(string $publicId): string
    {
        $pos = strrpos($publicId, '/');

        return $pos === false ? $publicId : substr($publicId, $pos + 1);
    }

    /**
     * @param array<string, mixed> $documento
     *
     * @return list<ObjectId>
     */
    public static function integranteIds(array $documento): array
    {
        $ids = [];
        foreach (is_array($documento['integrantes'] ?? null) ? $documento['integrantes'] : [] as $id) {
            if ($id instanceof ObjectId) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $usuario
     *
     * @return array<string, mixed>
     */
    private static function persona(array $usuario): array
    {
        return [
            '_id' => $usuario['_id'] ?? null,
            'nombre' => $usuario['nombre'] ?? null,
            'apellido' => $usuario['apellido'] ?? null,
            'rol' => $usuario['rol'] ?? null,
            'foto_perfil' => UsuarioPresenter::foto($usuario),
        ];
    }
}

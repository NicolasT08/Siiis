<?php

declare(strict_types=1);

namespace Siiis\Support;

/**
 * Módulos de contenido sobre la colección unificada `Documentos` (spec 8.2, 10.9 y 11.3; DEC-B30).
 * Cada ruta sirve un subconjunto fijo de categorías: `/projects` solo proyectos y `/articles` artículos y
 * noticias. Estados por categoría (DEC-B31): los proyectos registran su avance; artículos y noticias,
 * solo si ya se publicaron.
 */
enum ModuloDocumentos: string
{
    case PROYECTOS = 'projects';
    case ARTICULOS = 'articles';

    public const CATEGORIA_PROYECTO = 'proyecto';
    public const CATEGORIA_ARTICULO = 'articulo';
    public const CATEGORIA_NOTICIA = 'noticia';

    public const ESTADO_EN_REVISION = 'en_revision';
    public const ESTADO_EJECUCION = 'ejecucion';
    public const ESTADO_FINALIZADO = 'finalizado';
    public const ESTADO_PUBLICADO = 'publicado';

    /**
     * @return list<string>
     */
    public function categorias(): array
    {
        return match ($this) {
            self::PROYECTOS => [self::CATEGORIA_PROYECTO],
            self::ARTICULOS => [self::CATEGORIA_ARTICULO, self::CATEGORIA_NOTICIA],
        };
    }

    /**
     * Estados válidos para cualquier categoría del módulo (para validar filtros y cuerpos).
     *
     * @return list<string>
     */
    public function estados(): array
    {
        $estados = [];
        foreach ($this->categorias() as $categoria) {
            $estados = [...$estados, ...self::estadosDe($categoria)];
        }

        return array_values(array_unique($estados));
    }

    public function tieneCategoria(string $categoria): bool
    {
        return in_array($categoria, $this->categorias(), true);
    }

    /**
     * Si el cuerpo debe traer `categoria`: solo cuando el módulo tiene más de una.
     */
    public function eligeCategoria(): bool
    {
        return count($this->categorias()) > 1;
    }

    /**
     * Nombre en singular para los mensajes de error.
     */
    public function nombre(): string
    {
        return match ($this) {
            self::PROYECTOS => 'proyecto',
            self::ARTICULOS => 'artículo o noticia',
        };
    }

    /**
     * @return list<string>
     */
    public static function estadosDe(string $categoria): array
    {
        return match ($categoria) {
            self::CATEGORIA_PROYECTO => [self::ESTADO_EN_REVISION, self::ESTADO_EJECUCION, self::ESTADO_FINALIZADO],
            self::CATEGORIA_ARTICULO, self::CATEGORIA_NOTICIA => [self::ESTADO_EN_REVISION, self::ESTADO_PUBLICADO],
            default => [],
        };
    }
}

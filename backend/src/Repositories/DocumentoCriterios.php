<?php

declare(strict_types=1);

namespace Siiis\Repositories;

use MongoDB\BSON\ObjectId;

/**
 * Criterios ya validados para listar `Documentos`. El repositorio los convierte en el filtro de MongoDB;
 * ningún valor llega sin pasar por una lista blanca o por ObjectId.
 */
final readonly class DocumentoCriterios
{
    /** Solo lo público: `visible = true` y estado distinto de `en_revision` (DEC-B31). */
    public const ALCANCE_PUBLICO = 'publico';

    /** Lo público más lo del actor como autor o integrante (DEC-B36). */
    public const ALCANCE_PROPIO = 'propio';

    /** Todo (revisores, DEC-B36). */
    public const ALCANCE_TODO = 'todo';

    /**
     * @param list<string> $categorias categorías del módulo (o la elegida en `?categoria=`)
     * @param string       $alcance    una de las constantes ALCANCE_*
     * @param ObjectId|null $actor     obligatorio con ALCANCE_PROPIO
     */
    public function __construct(
        public array $categorias,
        public string $alcance,
        public ?ObjectId $actor = null,
        public ?string $estado = null,
        public ?bool $visible = null,
        public ?ObjectId $autor = null,
        public ?ObjectId $integrante = null,
    ) {
    }
}

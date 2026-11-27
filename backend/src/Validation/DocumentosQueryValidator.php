<?php

declare(strict_types=1);

namespace Siiis\Validation;

use MongoDB\BSON\ObjectId;
use Siiis\Config\Settings;
use Siiis\Support\ApiException;
use Siiis\Support\ModuloDocumentos;

/**
 * Query string de GET /projects y GET /articles (spec 10.13.4; DEC-B41): `page`, `per_page`, `categoria`
 * (solo en módulos con varias categorías), `estado`, `autor`, `integrante` y, solo para revisores,
 * `visible`. Todo pasa por listas blancas o se convierte a ObjectId antes de llegar al repositorio.
 */
final class DocumentosQueryValidator
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param array<mixed> $query      normalmente $request->getQueryParams()
     * @param bool         $verVisible si el actor es revisor; si no, `visible` se ignora
     *
     * @return array{page: int, per_page: int, categoria: string|null, estado: string|null, visible: bool|null,
     *               autor: ObjectId|null, integrante: ObjectId|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function validate(array $query, ModuloDocumentos $modulo, bool $verVisible): array
    {
        $errors = [];

        $page = QueryReader::entero($query, 'page', 1, QueryReader::MAX_PAGE, 1, $errors);
        $perPage = QueryReader::entero(
            $query,
            'per_page',
            1,
            $this->settings->documentosPerPageMax,
            $this->settings->documentosPerPageDefault,
            $errors,
        );
        $categoria = $modulo->eligeCategoria()
            ? QueryReader::opcion($query, 'categoria', $modulo->categorias(), $errors)
            : null;
        $estados = $categoria !== null ? ModuloDocumentos::estadosDe($categoria) : $modulo->estados();
        $estado = QueryReader::opcion($query, 'estado', $estados, $errors);
        $visible = $verVisible ? QueryReader::booleano($query, 'visible', $errors) : null;
        $autor = QueryReader::objectId($query, 'autor', $errors);
        $integrante = QueryReader::objectId($query, 'integrante', $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors, 'Los parámetros de la consulta no son válidos.');
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'categoria' => $categoria,
            'estado' => $estado,
            'visible' => $visible,
            'autor' => $autor,
            'integrante' => $integrante,
        ];
    }
}

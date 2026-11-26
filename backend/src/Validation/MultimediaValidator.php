<?php

declare(strict_types=1);

namespace Siiis\Validation;

use MongoDB\BSON\ObjectId;
use Siiis\Config\Settings;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Support\ApiException;
use Siiis\Support\ObjectIdValidator;

/**
 * Entrada de la administración de fotos y videos del inicio (DEC-B62).
 */
final class MultimediaValidator
{
    /** Secciones que se administran desde la página → tipo del archivo. */
    public const SECCIONES = [
        MultimediaRepository::SECCION_SLIDER => 'imagen',
        MultimediaRepository::SECCION_VIDEOS => 'video',
    ];

    public const ORDEN_MAX = 1000;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Campos del formulario de subida (multipart: todo llega como texto).
     *
     * @return array{seccion: string, descripcion: string, orden: int|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function crear(mixed $body): array
    {
        $errors = [];
        $seccion = InputReader::opcion($body, 'seccion', array_keys(self::SECCIONES), $errors);
        $descripcion = InputReader::texto($body, 'descripcion', $this->settings->multimediaDescripcionMaxLength, $errors, true);
        $orden = null;
        $valor = is_array($body) ? ($body['orden'] ?? null) : null;
        if ($valor !== null && $valor !== '') {
            $orden = self::orden($valor, $errors);
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['seccion' => $seccion, 'descripcion' => $descripcion, 'orden' => $orden];
    }

    /**
     * PATCH: `descripcion` y/o `orden` (JSON).
     *
     * @return array{descripcion?: string, orden?: int}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function actualizar(mixed $body): array
    {
        $errors = [];
        $cambios = [];
        if (is_array($body) && array_key_exists('descripcion', $body)) {
            $cambios['descripcion'] = InputReader::texto($body, 'descripcion', $this->settings->multimediaDescripcionMaxLength, $errors, true);
        }
        if (is_array($body) && array_key_exists('orden', $body)) {
            $orden = self::orden($body['orden'], $errors);
            if ($orden !== null) {
                $cambios['orden'] = $orden;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if ($cambios === []) {
            throw ApiException::validation([], 'Envía al menos uno de estos campos: descripcion, orden.');
        }

        return $cambios;
    }

    /**
     * PUT /multimedia/orden: `seccion` y la lista completa de ids en el orden deseado.
     *
     * @return array{seccion: string, ids: list<ObjectId>}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function reordenar(mixed $body): array
    {
        $errors = [];
        $seccion = InputReader::opcion($body, 'seccion', array_keys(self::SECCIONES), $errors);
        $lista = is_array($body) ? ($body['ids'] ?? null) : null;
        $ids = [];
        if (!is_array($lista) || !array_is_list($lista) || $lista === [] || count($lista) > 100) {
            $errors['ids'] = 'Envía la lista de ids de la sección en el orden deseado.';
        } else {
            foreach ($lista as $valor) {
                $id = ObjectIdValidator::toObjectId($valor);
                if ($id === null) {
                    $errors['ids'] = 'Cada id debe tener 24 caracteres hexadecimales.';
                    break;
                }
                $ids[(string) $id] = $id;
            }
            if (!isset($errors['ids']) && count($ids) !== count($lista)) {
                $errors['ids'] = 'La lista tiene ids repetidos.';
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['seccion' => $seccion, 'ids' => array_values($ids)];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function orden(mixed $valor, array &$errors): ?int
    {
        // Multipart manda texto ("3"); JSON, número (3). Nunca arreglos ni decimales.
        $texto = is_int($valor) ? (string) $valor : (is_string($valor) ? trim($valor) : null);
        if ($texto === null || !ctype_digit($texto) || (int) $texto < 1 || (int) $texto > self::ORDEN_MAX) {
            $errors['orden'] = sprintf('Debe ser un número entero entre 1 y %d.', self::ORDEN_MAX);

            return null;
        }

        return (int) $texto;
    }
}

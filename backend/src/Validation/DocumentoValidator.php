<?php

declare(strict_types=1);

namespace Siiis\Validation;

use MongoDB\BSON\ObjectId;
use Siiis\Config\Settings;
use Siiis\Support\ApiException;
use Siiis\Support\ModuloDocumentos;
use Siiis\Support\ObjectIdValidator;

/**
 * Cuerpo de POST y PATCH en /projects y /articles (spec 8.2; DEC-B30, B31, B37 y B38). Valida forma,
 * longitudes y listas blancas; los permisos (quién puede tocar `estado` y `visible`) y la existencia de
 * los integrantes los decide DocumentosService. `autor_id`, `archivos_adjuntos` y las fechas no se aceptan
 * del cliente: se ignoran como cualquier otro campo desconocido.
 */
final class DocumentoValidator
{
    /** Campos de texto editables, en el orden en que se validan. */
    public const CAMPOS_TEXTO = ['titulo', 'descripcion', 'contenido'];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * POST: `titulo` y `descripcion` obligatorios; `categoria` obligatoria solo en módulos con varias.
     *
     * @return array{campos: array<string, mixed>, categoria: string, estado: string|null, visible: bool|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function crear(mixed $body, ModuloDocumentos $modulo): array
    {
        $body = is_array($body) ? $body : [];
        $errors = [];

        $campos = $this->campos($body, false, $errors);
        $categoria = $this->categoria($body, $modulo, true, $errors) ?? $modulo->categorias()[0];
        $estado = $this->estado($body, ModuloDocumentos::estadosDe($categoria), $errors);
        $visible = self::visible($body, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return ['campos' => $campos, 'categoria' => $categoria, 'estado' => $estado, 'visible' => $visible];
    }

    /**
     * PATCH: edición parcial. `estado` se valida aquí contra los estados del módulo y en el servicio
     * contra los de la categoría final del documento.
     *
     * @return array{campos: array<string, mixed>, categoria: string|null, estado: string|null, visible: bool|null}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function actualizar(mixed $body, ModuloDocumentos $modulo): array
    {
        $body = is_array($body) ? $body : [];
        $errors = [];

        $campos = $this->campos($body, true, $errors);
        $categoria = $this->categoria($body, $modulo, false, $errors);
        $estados = $categoria !== null ? ModuloDocumentos::estadosDe($categoria) : $modulo->estados();
        $estado = $this->estado($body, $estados, $errors);
        $visible = self::visible($body, $errors);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if ($campos === [] && $categoria === null && $estado === null && $visible === null) {
            $editables = [...self::CAMPOS_TEXTO, 'integrantes', 'estado', 'visible'];
            if ($modulo->eligeCategoria()) {
                $editables[] = 'categoria';
            }

            throw ApiException::validation([], sprintf(
                'Envía al menos un campo para actualizar: %s.',
                implode(', ', $editables),
            ));
        }

        return ['campos' => $campos, 'categoria' => $categoria, 'estado' => $estado, 'visible' => $visible];
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function campos(array $body, bool $parcial, array &$errors): array
    {
        $campos = [];
        $limites = [
            'titulo' => $this->settings->documentoTituloMaxLength,
            'descripcion' => $this->settings->documentoDescripcionMaxLength,
            'contenido' => $this->settings->documentoContenidoMaxLength,
        ];

        foreach (self::CAMPOS_TEXTO as $campo) {
            if ($parcial && !array_key_exists($campo, $body)) {
                continue;
            }
            if ($campo === 'contenido') {
                $campos[$campo] = self::textoOpcional($body[$campo] ?? null, $campo, $limites[$campo], $errors);

                continue;
            }

            $valor = InputReader::string($body, $campo, $errors);
            if (!isset($errors[$campo]) && mb_strlen($valor) > $limites[$campo]) {
                $errors[$campo] = sprintf('Máximo %d caracteres.', $limites[$campo]);
            }
            $campos[$campo] = $valor;
        }

        if (!$parcial || array_key_exists('integrantes', $body)) {
            $campos['integrantes'] = $this->integrantes($body['integrantes'] ?? null, $errors);
        }

        return $campos;
    }

    /**
     * Markdown (DEC-B37): se guarda tal cual, sin espacios alrededor; vacío o null se guarda como null.
     *
     * @param array<string, string> $errors
     */
    private static function textoOpcional(mixed $valor, string $campo, int $max, array &$errors): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (!is_string($valor)) {
            $errors[$campo] = 'Este campo debe ser texto.';

            return null;
        }
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        if (mb_strlen($valor) > $max) {
            $errors[$campo] = sprintf('Máximo %d caracteres.', $max);
        }

        return $valor;
    }

    /**
     * Lista de ids de `Usuarios` sin repetidos (DEC-B38). null equivale a la lista vacía.
     *
     * @param array<string, string> $errors
     *
     * @return list<ObjectId>
     */
    private function integrantes(mixed $valor, array &$errors): array
    {
        if ($valor === null) {
            return [];
        }
        if (!is_array($valor) || !array_is_list($valor)) {
            $errors['integrantes'] = 'Debe ser una lista de identificadores de usuario.';

            return [];
        }

        $ids = [];
        foreach ($valor as $item) {
            $id = ObjectIdValidator::toObjectId(is_string($item) ? $item : null);
            if ($id === null) {
                $errors['integrantes'] = 'Cada integrante debe ser un identificador válido (24 caracteres hexadecimales).';

                return [];
            }
            $ids[(string) $id] = $id;
        }

        if (count($ids) > $this->settings->documentoIntegrantesMax) {
            $errors['integrantes'] = sprintf('Máximo %d integrantes.', $this->settings->documentoIntegrantesMax);

            return [];
        }

        return array_values($ids);
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $errors
     */
    private function categoria(array $body, ModuloDocumentos $modulo, bool $obligatoria, array &$errors): ?string
    {
        if (!$modulo->eligeCategoria()) {
            // En /projects la categoría es fija: un valor enviado se ignora.
            return null;
        }
        if (!array_key_exists('categoria', $body) && !$obligatoria) {
            return null;
        }

        $categoria = InputReader::string($body, 'categoria', $errors);
        if (isset($errors['categoria'])) {
            return null;
        }
        if (!$modulo->tieneCategoria($categoria)) {
            $errors['categoria'] = sprintf(
                'Categoría no válida. Valores permitidos: %s.',
                implode(', ', $modulo->categorias()),
            );

            return null;
        }

        return $categoria;
    }

    /**
     * @param array<mixed>          $body
     * @param list<string>          $permitidos
     * @param array<string, string> $errors
     */
    private function estado(array $body, array $permitidos, array &$errors): ?string
    {
        if (!array_key_exists('estado', $body)) {
            return null;
        }
        $estado = InputReader::string($body, 'estado', $errors);
        if (isset($errors['estado'])) {
            return null;
        }
        if (!in_array($estado, $permitidos, true)) {
            $errors['estado'] = sprintf('Estado no válido. Valores permitidos: %s.', implode(', ', $permitidos));

            return null;
        }

        return $estado;
    }

    /**
     * Booleano JSON estricto: "true", 1 o un objeto no se aceptan.
     *
     * @param array<mixed>          $body
     * @param array<string, string> $errors
     */
    private static function visible(array $body, array &$errors): ?bool
    {
        if (!array_key_exists('visible', $body)) {
            return null;
        }
        if (!is_bool($body['visible'])) {
            $errors['visible'] = 'El campo visible debe ser true o false.';

            return null;
        }

        return $body['visible'];
    }
}

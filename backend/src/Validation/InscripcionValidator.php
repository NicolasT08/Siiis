<?php

declare(strict_types=1);

namespace Siiis\Validation;

use Siiis\Config\Settings;
use Siiis\Repositories\InscripcionesRepository;
use Siiis\Support\ApiException;

/**
 * Campos de `POST /inscriptions` (`multipart/form-data`, DEC-B51) y de `PATCH /inscriptions/{id}` (DEC-B52).
 * En un formulario todo llega como texto: `semestre` se acepta como dígitos y `acepta_tratamiento_datos`
 * como `true`. Solo se aceptan escalares (antiinyección NoSQL). `programa_academico`, `semestre` y
 * `telefono` usan las reglas del perfil (PerfilValidator, DEC-B21).
 */
final class InscripcionValidator
{
    /** Áreas de interés admitidas (lista blanca, DEC-B63). */
    public const AREAS_INTERES = [
        'desarrollo_software',
        'transformacion_digital',
        'tic',
        'innovacion',
        'inteligencia_artificial',
        'ciencia_datos',
    ];

    /** Código estudiantil: 4 a 20 letras o dígitos (DEC-B63). */
    private const PATRON_CODIGO = '/^[A-Za-z0-9]{4,20}\z/';

    public function __construct(
        private readonly Settings $settings,
        private readonly PerfilValidator $perfil,
    ) {
    }

    /**
     * @return array{nombre_completo: string, correo: string, telefono: string|null, programa_academico: string,
     *               semestre: int, carta_motivacion: string, codigo_estudiantil: string|null,
     *               areas_interes: list<string>}
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function crear(mixed $body): array
    {
        $s = $this->settings;
        $body = is_array($body) ? $body : [];
        $errors = [];

        $nombre = InputReader::texto($body, 'nombre_completo', $s->inscripcionNombreMaxLength, $errors, true);
        $correo = $this->correo($body, $errors);

        // Reglas del perfil; programa y semestre son obligatorios aquí.
        $perfil = $this->perfil->validate([
            'programa_academico' => $body['programa_academico'] ?? null,
            'semestre' => self::entero($body['semestre'] ?? null),
            'telefono' => $body['telefono'] ?? null,
        ], true, $errors);
        foreach (['programa_academico', 'semestre'] as $campo) {
            if (!isset($errors[$campo]) && ($perfil[$campo] ?? null) === null) {
                $errors[$campo] = 'Este campo es obligatorio.';
            }
        }

        // Obligatoria, sin mínimo de caracteres (DEC-B65).
        $carta = InputReader::texto($body, 'carta_motivacion', $s->inscripcionCartaMaxLength, $errors);

        // Opcionales nuevos (DEC-B63, PROPUESTA pendiente del equipo de BD).
        $codigo = null;
        $valorCodigo = $body['codigo_estudiantil'] ?? null;
        if ($valorCodigo !== null && $valorCodigo !== '') {
            if (!is_string($valorCodigo) || preg_match(self::PATRON_CODIGO, trim($valorCodigo)) !== 1) {
                $errors['codigo_estudiantil'] = 'Debe tener de 4 a 20 letras o números, sin espacios ni símbolos.';
            } else {
                $codigo = strtoupper(trim($valorCodigo));
            }
        }
        $areas = self::areas($body['areas_interes'] ?? null, $errors);

        // Compromiso de disponibilidad: obligatorio, pero no se guarda (como la autorización de datos, DEC-B63).
        $disponible = $body['disponibilidad'] ?? null;
        if ($disponible !== true && $disponible !== 'true' && $disponible !== '1') {
            $errors['disponibilidad'] = 'Debes confirmar tu disponibilidad para las reuniones semanales.';
        }

        // La autorización de datos ya no se exige (DEC-B65): el formulario solo informa el uso de los datos.

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        /** @var string $programa */
        $programa = $perfil['programa_academico'];
        /** @var int $semestre */
        $semestre = $perfil['semestre'];
        $telefono = $perfil['telefono'] ?? null;

        return [
            'nombre_completo' => $nombre,
            'correo' => $correo,
            'telefono' => is_string($telefono) ? $telefono : null,
            'programa_academico' => $programa,
            'semestre' => $semestre,
            'carta_motivacion' => $carta,
            'codigo_estudiantil' => $codigo,
            'areas_interes' => $areas,
        ];
    }

    /**
     * Lista de áreas (`areas_interes[]` en el formulario o un arreglo JSON) dentro de la lista blanca, sin repetidos.
     *
     * @param array<string, string> $errors
     *
     * @return list<string>
     */
    private static function areas(mixed $valor, array &$errors): array
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return [];
        }
        if (!is_array($valor) || !array_is_list($valor)) {
            $errors['areas_interes'] = 'Envía las áreas de interés como una lista.';

            return [];
        }
        $areas = [];
        foreach ($valor as $area) {
            if (!is_string($area) || !in_array($area, self::AREAS_INTERES, true)) {
                $errors['areas_interes'] = sprintf('Valor no válido. Valores permitidos: %s.', implode(', ', self::AREAS_INTERES));

                return [];
            }
            $areas[$area] = $area;
        }

        return array_values($areas);
    }

    /**
     * `{"estado_solicitud": "aceptada" | "rechazada"}`: solo se resuelve una solicitud pendiente (DEC-B52).
     *
     * @throws ApiException 400 VALIDATION_ERROR
     */
    public function resolver(mixed $body): string
    {
        $errors = [];
        $estado = InputReader::opcion(
            $body,
            'estado_solicitud',
            [InscripcionesRepository::ESTADO_ACEPTADA, InscripcionesRepository::ESTADO_RECHAZADA],
            $errors,
        );
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $estado;
    }

    /**
     * Correo en minúsculas, con formato válido y del dominio permitido (`INSCRIPCION_DOMINIOS_CORREO`).
     *
     * @param array<mixed>          $body
     * @param array<string, string> $errors
     */
    private function correo(array $body, array &$errors): string
    {
        $correo = InputReader::correo($body, $errors);
        $dominios = $this->settings->inscripcionDominiosCorreo;
        if (isset($errors['correo']) || $dominios === []) {
            return $correo;
        }

        $dominio = substr($correo, (int) strrpos($correo, '@') + 1);
        if (!in_array($dominio, $dominios, true)) {
            $errors['correo'] = sprintf(
                'Usa tu correo institucional (%s).',
                implode(', ', array_map(static fn (string $d): string => '@' . $d, $dominios)),
            );
        }

        return $correo;
    }

    /**
     * "5" → 5 (formulario); un entero JSON se deja igual; cualquier otra cosa pasa tal cual para que
     * PerfilValidator la rechace.
     */
    private static function entero(mixed $valor): mixed
    {
        if (is_string($valor)) {
            $valor = trim($valor);
            if ($valor === '') {
                return null;
            }
            if (ctype_digit($valor) && strlen($valor) <= 3) {
                return (int) $valor;
            }
        }

        return $valor;
    }
}

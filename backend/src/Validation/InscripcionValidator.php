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
    public function __construct(
        private readonly Settings $settings,
        private readonly PerfilValidator $perfil,
    ) {
    }

    /**
     * @return array{nombre_completo: string, correo: string, telefono: string|null, programa_academico: string,
     *               semestre: int, carta_motivacion: string}
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

        $carta = InputReader::texto($body, 'carta_motivacion', $s->inscripcionCartaMaxLength, $errors);
        if (!isset($errors['carta_motivacion']) && mb_strlen($carta) < $s->inscripcionCartaMinLength) {
            $errors['carta_motivacion'] = sprintf('Mínimo %d caracteres.', $s->inscripcionCartaMinLength);
        }

        // Ley 1581 de 2012: no se guarda (el modelo no tiene el campo), pero es obligatoria (DEC-B51).
        $acepta = $body['acepta_tratamiento_datos'] ?? null;
        if ($acepta !== true && $acepta !== 'true' && $acepta !== '1') {
            $errors['acepta_tratamiento_datos'] = 'Debes autorizar el tratamiento de tus datos personales.';
        }

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
        ];
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

<?php

declare(strict_types=1);

namespace Siiis\Services;

use MongoDB\BSON\ObjectId;
use Siiis\Database\Collections;
use Siiis\Repositories\MantenimientoCuentasRepository;
use Siiis\Security\Roles;
use Throwable;

/**
 * Baja definitiva de cuentas, solo para mantenimiento (DEC-B73, `scripts/eliminar_cuentas.php`). La baja normal
 * sigue siendo la desactivación desde el panel (DEC-B22).
 *
 * `revisar()` no escribe nada: reporta lo relacionado con cada correo y los motivos por los que no se puede borrar.
 * `eliminar()` borra solo las cuentas sin motivos en contra, y la foto de perfil en Cloudinary al final.
 */
class EliminarCuentasService
{
    /** Cuentas que el script nunca toca. */
    public const PROTEGIDAS = ['siiisweb@gmail.com'];

    public function __construct(
        private readonly MantenimientoCuentasRepository $repo,
        private readonly CloudinaryService $cloudinary,
        private readonly string $directorCorreo = '',
    ) {
    }

    public static function normalizar(string $correo): string
    {
        return mb_strtolower(trim($correo));
    }

    /**
     * @param list<string> $correos
     *
     * @return list<array<string, mixed>> un reporte por correo (sin repetidos), con `bloqueos` (motivos para no borrar)
     */
    public function revisar(array $correos): array
    {
        $reportes = [];
        foreach (array_values(array_unique(array_map(self::normalizar(...), $correos))) as $correo) {
            $reportes[] = $this->revisarUno($correo);
        }

        // Al menos un superadministrador activo debe quedar después de borrar todas las cuentas pedidas.
        $ids = [];
        $haySuperadmin = false;
        foreach ($reportes as $r) {
            if ($r['usuario_id'] instanceof ObjectId) {
                $ids[] = $r['usuario_id'];
            }
            $haySuperadmin = $haySuperadmin || $r['rol'] === Roles::SUPERADMINISTRADOR;
        }
        $restantes = $this->repo->contarSuperadministradoresActivos($ids);
        foreach ($reportes as &$r) {
            $r['superadministradores_activos_restantes'] = $restantes;
            if ($restantes < 1 && $r['encontrada']) {
                $r['bloqueos'][] = 'no quedaría ningún superadministrador activo en el sitio.';
            }
        }
        unset($r);

        return $reportes;
    }

    /**
     * Borra las cuentas de los reportes que no tienen bloqueos. Devuelve los reportes con `resultado`
     * (lo borrado) o `error`, y `foto` con lo que pasó en Cloudinary.
     *
     * @param list<array<string, mixed>> $reportes de `revisar()`
     *
     * @return list<array<string, mixed>>
     */
    public function eliminar(array $reportes): array
    {
        foreach ($reportes as &$r) {
            if (!$r['encontrada'] || $r['bloqueos'] !== []) {
                continue;
            }
            /** @var ObjectId $credencialId */
            $credencialId = $r['credencial_id'];
            $usuarioId = $r['usuario_id'] instanceof ObjectId ? $r['usuario_id'] : null;
            try {
                $r['resultado'] = $this->repo->eliminar($credencialId, $usuarioId);
            } catch (Throwable $e) {
                $r['error'] = $e->getMessage();
                continue;
            }

            $publicId = $r['foto_public_id'];
            if (is_string($publicId) && $publicId !== '') {
                try {
                    $this->cloudinary->delete($publicId);
                    $r['foto'] = 'borrada';
                } catch (Throwable $e) {
                    $r['foto'] = 'no se pudo borrar (' . $e->getMessage() . '); bórrala a mano: ' . $publicId;
                }
            }
        }
        unset($r);

        return $reportes;
    }

    /**
     * @return array<string, mixed>
     */
    private function revisarUno(string $correo): array
    {
        $reporte = [
            'correo' => $correo,
            'encontrada' => false,
            'credencial_id' => null,
            'usuario_id' => null,
            'nombre' => null,
            'rol' => null,
            'estado' => null,
            'estado_cuenta' => null,
            'sesiones' => ['total' => 0, 'activas' => 0],
            'foto_public_id' => null,
            'documentos_autor' => [],
            'documentos_integrante' => [],
            'resenas' => 0,
            'inscripciones_con_correo' => 0,
            'mensajes_con_correo' => 0,
            'avisos' => [],
            'bloqueos' => [],
        ];

        if (in_array($correo, self::PROTEGIDAS, true)) {
            $reporte['bloqueos'][] = 'es una cuenta protegida: este script nunca la toca.';

            return $reporte;
        }
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $reporte['bloqueos'][] = 'el correo no es válido.';

            return $reporte;
        }

        $credencial = $this->repo->credencialPorCorreo($correo);
        if ($credencial === null) {
            $reporte['bloqueos'][] = 'no existe una cuenta con ese correo en Credenciales.';

            return $reporte;
        }
        /** @var ObjectId $credencialId */
        $credencialId = $credencial['_id'];
        $reporte['encontrada'] = true;
        $reporte['credencial_id'] = $credencialId;
        $reporte['estado_cuenta'] = $credencial['estado_cuenta'] ?? null;

        $enlace = ($credencial['usuario_id'] ?? null) instanceof ObjectId ? $credencial['usuario_id'] : null;
        $usuario = $this->repo->usuario($enlace, $credencialId);
        if ($usuario === null) {
            $reporte['avisos'][] = 'la credencial no tiene perfil en Usuarios: solo se borrará la credencial.';
        } else {
            /** @var ObjectId $usuarioId */
            $usuarioId = $usuario['_id'];
            $reporte['usuario_id'] = $usuarioId;
            $reporte['nombre'] = trim(((string) ($usuario['nombre'] ?? '')) . ' ' . ((string) ($usuario['apellido'] ?? '')));
            $reporte['rol'] = $usuario['rol'] ?? null;
            $reporte['estado'] = $usuario['estado'] ?? null;
            $foto = $usuario['foto_perfil'] ?? null;
            $reporte['foto_public_id'] = is_array($foto) && is_string($foto['public_id'] ?? null) ? $foto['public_id'] : null;
            $reporte['sesiones'] = $this->repo->contarSesiones($usuarioId);
            $reporte['documentos_autor'] = $this->repo->documentosComoAutor($usuarioId);
            $reporte['documentos_integrante'] = $this->repo->documentosComoIntegrante($usuarioId);
            $reporte['resenas'] = $this->repo->contarResenas($usuarioId);

            if (($usuario['estado'] ?? null) === true) {
                $reporte['bloqueos'][] = 'la cuenta está activa: desactívala primero desde el panel.';
            }
            if ($reporte['documentos_autor'] !== []) {
                $reporte['bloqueos'][] = sprintf(
                    'es autora de %d documento(s): elimínalos primero desde el panel.',
                    count($reporte['documentos_autor']),
                );
            }
        }

        $reporte['inscripciones_con_correo'] = $this->repo->contarPorCorreo(Collections::INSCRIPCIONES, $correo);
        $reporte['mensajes_con_correo'] = $this->repo->contarPorCorreo(Collections::MENSAJES_CONTACTO, $correo);
        if ($this->directorCorreo !== '' && $correo === self::normalizar($this->directorCorreo)) {
            $reporte['avisos'][] = 'es la cuenta de DIRECTOR_CORREO: el inicio dejará de mostrar al director hasta que cambies esa variable.';
        }

        return $reporte;
    }
}

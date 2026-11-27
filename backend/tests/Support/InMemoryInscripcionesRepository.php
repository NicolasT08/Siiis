<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\InscripcionesRepository;

/**
 * Doble de InscripcionesRepository sobre InMemoryDatabase (proyección del listado y resolución atómica).
 */
final class InMemoryInscripcionesRepository extends InscripcionesRepository
{
    public bool $fallarAlInsertar = false;

    /** Simula que otro administrador resolvió la solicitud entre la lectura y la escritura. */
    public ?string $resolverAntesCon = null;

    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function paginar(?string $estado, int $page, int $perPage): array
    {
        $items = array_values(array_filter(
            $this->db->inscripciones,
            static fn (array $i): bool => $estado === null || $i['estado_solicitud'] === $estado,
        ));
        usort($items, static fn (array $a, array $b): int => [self::ms($b['fecha_solicitud']), (string) $b['_id']]
            <=> [self::ms($a['fecha_solicitud']), (string) $a['_id']]);
        $campos = array_flip(['_id', 'nombre_completo', 'correo', 'telefono', 'programa_academico', 'semestre', 'estado_solicitud', 'fecha_solicitud']);

        return [
            'items' => array_map(
                static fn (array $i): array => array_intersect_key($i, $campos),
                array_slice($items, ($page - 1) * $perPage, $perPage),
            ),
            'total' => count($items),
        ];
    }

    public function findById(ObjectId $id): ?array
    {
        return $this->db->inscripciones[(string) $id] ?? null;
    }

    public function tienePendiente(string $correo): bool
    {
        foreach ($this->db->inscripciones as $i) {
            if ($i['correo'] === $correo && $i['estado_solicitud'] === self::ESTADO_PENDIENTE) {
                return true;
            }
        }

        return false;
    }

    public function insertar(array $inscripcion): void
    {
        if ($this->fallarAlInsertar) {
            throw new \RuntimeException('MongoDB caído (simulado)');
        }
        $this->db->inscripciones[(string) $inscripcion['_id']] = $inscripcion;
    }

    public function resolver(ObjectId $id, string $estado): bool
    {
        $clave = (string) $id;
        if ($this->resolverAntesCon !== null && isset($this->db->inscripciones[$clave])) {
            $this->db->inscripciones[$clave]['estado_solicitud'] = $this->resolverAntesCon;
        }
        if (($this->db->inscripciones[$clave]['estado_solicitud'] ?? null) !== self::ESTADO_PENDIENTE) {
            return false;
        }
        $this->db->inscripciones[$clave]['estado_solicitud'] = $estado;

        return true;
    }

    private static function ms(mixed $fecha): int
    {
        return $fecha instanceof UTCDateTime ? (int) (string) $fecha : 0;
    }
}

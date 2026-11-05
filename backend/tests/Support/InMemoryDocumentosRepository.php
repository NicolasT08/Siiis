<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\DocumentoCriterios;
use Siiis\Repositories\DocumentosRepository;

/**
 * Doble en memoria de DocumentosRepository. Reproduce el filtro de `DocumentosRepository::filtro`; la
 * versión real se prueba contra Atlas en DocumentosRepositoryIntegrationTest.
 */
final class InMemoryDocumentosRepository extends DocumentosRepository
{
    public bool $fallarAlActualizar = false;

    /** Cuántas veces se llamó a paginar (para comprobar que no hay consultas de más). */
    public int $consultas = 0;

    /** Se ejecuta una vez justo después de la próxima lectura por id (simula escrituras concurrentes). */
    public ?\Closure $alLeer = null;

    public function __construct(private readonly InMemoryDatabase $db)
    {
    }

    public function paginar(DocumentoCriterios $criterios, int $page, int $perPage): array
    {
        ++$this->consultas;
        $items = array_values(array_filter(
            $this->db->documentos,
            static fn (array $d): bool => self::cumple($d, $criterios),
        ));
        usort($items, static fn (array $a, array $b): int => [
            self::ms($b['fecha_creacion']),
            (string) $b['_id'],
        ] <=> [
            self::ms($a['fecha_creacion']),
            (string) $a['_id'],
        ]);

        $pagina = array_map(static function (array $d): array {
            unset($d['contenido'], $d['archivos_adjuntos']);

            return $d;
        }, array_slice($items, ($page - 1) * $perPage, $perPage));

        return ['items' => $pagina, 'total' => count($items)];
    }

    public function findById(ObjectId $id): ?array
    {
        $doc = $this->db->documentos[(string) $id] ?? null;
        if ($this->alLeer !== null) {
            $alLeer = $this->alLeer;
            $this->alLeer = null;
            $alLeer();
        }

        return $doc;
    }

    public function insertar(array $documento): void
    {
        $this->db->documentos[(string) $documento['_id']] = $documento;
    }

    public function actualizar(ObjectId $id, array $set, ?string $siEstado = null): bool
    {
        if ($this->fallarAlActualizar) {
            throw new \RuntimeException('Fallo simulado de MongoDB');
        }
        $doc = $this->db->documentos[(string) $id] ?? null;
        if ($doc === null || ($siEstado !== null && $doc['estado'] !== $siEstado)) {
            return false;
        }
        $this->db->documentos[(string) $id] = $set + $doc;

        return true;
    }

    public function agregarAdjunto(ObjectId $id, array $adjunto, int $max, UTCDateTime $fecha, ?string $siEstado = null): bool
    {
        if ($this->fallarAlActualizar) {
            throw new \RuntimeException('Fallo simulado de MongoDB');
        }
        $doc = $this->db->documentos[(string) $id] ?? null;
        if ($doc === null || ($siEstado !== null && $doc['estado'] !== $siEstado) || count($doc['archivos_adjuntos']) >= $max) {
            return false;
        }
        $doc['archivos_adjuntos'][] = $adjunto;
        $doc['fecha_actualizacion'] = $fecha;
        $this->db->documentos[(string) $id] = $doc;

        return true;
    }

    public function quitarAdjunto(ObjectId $id, string $publicId, UTCDateTime $fecha, ?string $siEstado = null): bool
    {
        $doc = $this->db->documentos[(string) $id] ?? null;
        if ($doc === null || ($siEstado !== null && $doc['estado'] !== $siEstado)) {
            return false;
        }
        $restantes = array_values(array_filter(
            $doc['archivos_adjuntos'],
            static fn (array $a): bool => $a['public_id'] !== $publicId,
        ));
        if (count($restantes) === count($doc['archivos_adjuntos'])) {
            return false;
        }
        $doc['archivos_adjuntos'] = $restantes;
        $doc['fecha_actualizacion'] = $fecha;
        $this->db->documentos[(string) $id] = $doc;

        return true;
    }

    public function eliminar(ObjectId $id, ?string $siEstado = null): bool
    {
        $doc = $this->db->documentos[(string) $id] ?? null;
        if ($doc === null || ($siEstado !== null && $doc['estado'] !== $siEstado)) {
            return false;
        }
        unset($this->db->documentos[(string) $id]);

        return true;
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function cumple(array $d, DocumentoCriterios $c): bool
    {
        $integrantes = array_map('strval', $d['integrantes'] ?? []);
        $publico = $d['visible'] === true && $d['estado'] !== 'en_revision';
        $alcance = match (true) {
            $c->alcance === DocumentoCriterios::ALCANCE_TODO => true,
            $c->alcance === DocumentoCriterios::ALCANCE_PROPIO && $c->actor !== null => $publico
                || (string) $d['autor_id'] === (string) $c->actor
                || in_array((string) $c->actor, $integrantes, true),
            default => $publico,
        };

        return $alcance
            && in_array($d['categoria'], $c->categorias, true)
            && ($c->estado === null || $d['estado'] === $c->estado)
            && ($c->visible === null || $d['visible'] === $c->visible)
            && ($c->autor === null || (string) $d['autor_id'] === (string) $c->autor)
            && ($c->integrante === null || in_array((string) $c->integrante, $integrantes, true));
    }

    private static function ms(mixed $fecha): int
    {
        return $fecha instanceof UTCDateTime ? (int) (string) $fecha : 0;
    }
}

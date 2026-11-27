<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Siiis\Repositories\DocumentoCriterios;
use Siiis\Repositories\DocumentosRepository;
use Siiis\Security\Roles;
use Siiis\Support\ModuloDocumentos;

final class ModuloDocumentosTest extends TestCase
{
    public function testCategoriasYEstadosPorModulo(): void
    {
        self::assertSame(['proyecto'], ModuloDocumentos::PROYECTOS->categorias());
        self::assertSame(['en_revision', 'ejecucion', 'finalizado'], ModuloDocumentos::PROYECTOS->estados());
        self::assertFalse(ModuloDocumentos::PROYECTOS->eligeCategoria());

        self::assertSame(['articulo', 'noticia'], ModuloDocumentos::ARTICULOS->categorias());
        self::assertSame(['en_revision', 'publicado'], ModuloDocumentos::ARTICULOS->estados());
        self::assertTrue(ModuloDocumentos::ARTICULOS->eligeCategoria());
        self::assertFalse(ModuloDocumentos::ARTICULOS->tieneCategoria('proyecto'));
        self::assertSame([], ModuloDocumentos::estadosDe('otra'));
    }

    public function testRevisoresDeContenido(): void
    {
        foreach (['superadministrador', 'administrador', 'docente_director'] as $rol) {
            self::assertTrue(Roles::esRevisorContenido($rol), $rol);
        }
        foreach (['estudiante_lider', 'estudiante', 'Administrador', ''] as $rol) {
            self::assertFalse(Roles::esRevisorContenido($rol), $rol);
        }
        self::assertFalse(Roles::esRevisorContenido(null));
    }

    public function testFiltroPublico(): void
    {
        $filtro = DocumentosRepository::filtro(new DocumentoCriterios(['proyecto'], DocumentoCriterios::ALCANCE_PUBLICO));

        self::assertSame(['$and' => [
            ['categoria' => 'proyecto'],
            ['visible' => true, 'estado' => ['$ne' => 'en_revision']],
        ]], $filtro);
    }

    public function testFiltroPropioConFiltrosQueNoSePisan(): void
    {
        $actor = new ObjectId();
        $autor = new ObjectId();

        $filtro = DocumentosRepository::filtro(new DocumentoCriterios(
            ['articulo', 'noticia'],
            DocumentoCriterios::ALCANCE_PROPIO,
            actor: $actor,
            estado: 'en_revision',
            autor: $autor,
        ));

        // El filtro de estado y la condición de alcance van en $and: ninguno reemplaza al otro.
        self::assertEquals(['$and' => [
            ['categoria' => ['$in' => ['articulo', 'noticia']]],
            ['estado' => 'en_revision'],
            ['autor_id' => $autor],
            ['$or' => [
                ['visible' => true, 'estado' => ['$ne' => 'en_revision']],
                ['autor_id' => $actor],
                ['integrantes' => $actor],
            ]],
        ]], $filtro);
    }

    public function testFiltroTodoYAlcancesSinActor(): void
    {
        self::assertSame(
            ['categoria' => 'proyecto'],
            DocumentosRepository::filtro(new DocumentoCriterios(['proyecto'], DocumentoCriterios::ALCANCE_TODO)),
        );

        $publico = DocumentosRepository::filtro(new DocumentoCriterios(['proyecto'], DocumentoCriterios::ALCANCE_PUBLICO));
        self::assertSame($publico, DocumentosRepository::filtro(new DocumentoCriterios(['proyecto'], DocumentoCriterios::ALCANCE_PROPIO)));
        self::assertSame($publico, DocumentosRepository::filtro(new DocumentoCriterios(['proyecto'], 'otro')));
    }
}

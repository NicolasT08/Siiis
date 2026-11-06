<?php

declare(strict_types=1);

namespace Siiis\Tests\Api;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Siiis\Repositories\MultimediaRepository;
use Siiis\Tests\Support\InMemoryMultimediaRepository;

final class HomeApiTest extends ApiTestCase
{
    /**
     * @param list<array<string, mixed>> $docs
     */
    private function repo(array $docs): InMemoryMultimediaRepository
    {
        return new InMemoryMultimediaRepository($docs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function docs(): array
    {
        $doc = static fn (string $seccion, string $tipo, int $orden): array => [
            '_id' => new ObjectId(),
            'tipo' => $tipo,
            'url' => "https://res.cloudinary.com/demo/$tipo/upload/$seccion-$orden",
            'public_id' => "secreto-$seccion-$orden",
            'seccion' => $seccion,
            'descripcion' => "Ítem $orden",
            'orden' => $orden,
            'fecha_subida' => new UTCDateTime(),
        ];

        return [
            $doc('slider_principal', 'imagen', 3),
            $doc('video_presentacion', 'video', 2),
            $doc('slider_principal', 'imagen', 1),
            $doc('galeria', 'imagen', 1),
            $doc('slider_principal', 'imagen', 2),
            $doc('video_presentacion', 'video', 1),
        ];
    }

    public function testSliderOrdenadoConProyeccionYCache(): void
    {
        $repo = $this->repo($this->docs());
        $response = $this->app([MultimediaRepository::class => $repo])->handle($this->request('GET', '/api/v1/home/slider'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        $data = $this->json($response)['data'];
        self::assertSame([1, 2, 3], array_column($data, 'orden'));
        foreach ($data as $item) {
            self::assertSame(['id', 'tipo', 'url', 'descripcion', 'orden'], array_keys($item));
            self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $item['id']);
            self::assertSame('imagen', $item['tipo']);
        }
        self::assertStringNotContainsString('secreto', (string) $response->getBody());
        self::assertStringNotContainsString('public_id', (string) $response->getBody());
    }

    public function testVideosOrdenados(): void
    {
        $response = $this->app([MultimediaRepository::class => $this->repo($this->docs())])
            ->handle($this->request('GET', '/api/v1/home/videos'));

        $data = $this->json($response)['data'];
        self::assertSame([1, 2], array_column($data, 'orden'));
        self::assertSame(['video', 'video'], array_column($data, 'tipo'));
        // Los videos no llevan f_auto,q_auto (DEC-B42).
        self::assertSame('https://res.cloudinary.com/demo/video/upload/video_presentacion-1', $data[0]['url']);
    }

    public function testLasImagenesSeEntreganConFormatoYCalidadAutomaticos(): void
    {
        $docs = $this->docs();
        $docs[2]['url'] = 'https://res.cloudinary.com/demo/image/upload/v1/sample.jpg';
        $response = $this->app([MultimediaRepository::class => $this->repo($docs)])
            ->handle($this->request('GET', '/api/v1/home/slider'));

        self::assertSame(
            'https://res.cloudinary.com/demo/image/upload/f_auto,q_auto/v1/sample.jpg',
            $this->json($response)['data'][0]['url'],
        );
    }

    public function testAplicaElTopeConfigurable(): void
    {
        $repo = $this->repo($this->docs());
        $response = $this->app([MultimediaRepository::class => $repo], ['HOME_MAX_ITEMS' => '2'])
            ->handle($this->request('GET', '/api/v1/home/slider'));

        self::assertCount(2, $this->json($response)['data']);
        self::assertSame([['slider_principal', 2]], $repo->llamadas);
    }

    public function testSeccionVaciaDevuelveListaVacia(): void
    {
        $response = $this->app([MultimediaRepository::class => $this->repo([])])
            ->handle($this->request('GET', '/api/v1/home/videos'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":[]}', (string) $response->getBody());
    }
}

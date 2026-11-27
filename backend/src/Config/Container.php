<?php

declare(strict_types=1);

namespace Siiis\Config;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Siiis\Database\Mongo;
use Siiis\Middleware\ErrorHandler;
use Siiis\Security\GoogleJwksProvider;
use Siiis\Security\JwtService;
use Siiis\Security\RateLimiter;
use Siiis\Support\Clock;
use Siiis\Support\Logger;
use Siiis\Support\SystemClock;

use function DI\factory;
use function DI\get;

/**
 * Contenedor PHP-DI (DEC-B01). Las pruebas pasan $overrides para sustituir repositorios y servicios.
 */
final class Container
{
    /**
     * @param array<string, mixed> $overrides definiciones que reemplazan a las por defecto
     */
    public static function build(Settings $settings, array $overrides = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            Settings::class => $settings,
            Logger::class => factory(static fn (Settings $s): Logger => new Logger($s->storagePath . '/logs')),
            Mongo::class => factory(static fn (Settings $s): Mongo => new Mongo($s->mongoUri, $s->mongoDbName)),
            Clock::class => get(SystemClock::class),
            JwtService::class => factory(
                static fn (Settings $s, Clock $clock): JwtService => new JwtService($s->jwtSecret, $s->jwtTtlSeconds, $clock),
            ),
            RateLimiter::class => factory(
                static fn (Settings $s, Clock $clock, Logger $logger): RateLimiter => new RateLimiter(
                    $s->storagePath . '/ratelimit',
                    $clock,
                    $logger,
                ),
            ),
            GoogleJwksProvider::class => factory(
                static fn (Settings $s, Clock $clock, Logger $logger): GoogleJwksProvider => new GoogleJwksProvider(
                    $s->storagePath . '/cache/google_jwks.json',
                    $clock,
                    $logger,
                ),
            ),
            ErrorHandler::class => factory(
                static fn (Logger $logger, Settings $s): ErrorHandler => new ErrorHandler($logger, $s->appDebug),
            ),
        ]);
        if ($overrides !== []) {
            $builder->addDefinitions($overrides);
        }

        return $builder->build();
    }
}

<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing;

use Efnext\Security\Addressing\Contracts\PublicIdGenerator;
use Efnext\Security\Addressing\Generation\Encoding;
use Efnext\Security\Addressing\Generation\RandomPublicIdGenerator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

final class AddressingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/addressing.php', 'addressing');

        $this->app->singleton(PublicIdGenerator::class, function ($app): PublicIdGenerator {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('addressing.public_ids', []);

            return new RandomPublicIdGenerator(
                connections: $app->make(ConnectionResolverInterface::class),
                encoding: Encoding::fromName((string) ($config['encoding'] ?? 'base32')),
                defaultEntropyBytes: (int) ($config['entropy_bytes'] ?? 10),
                defaultMaxAttempts: (int) ($config['max_attempts'] ?? 5),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/addressing.php' => $this->app->configPath('addressing.php'),
        ], 'security-addressing-config');

        // $table->publicId() – a unique, indexed public id column.
        Blueprint::macro('publicId', function (?string $column = null): ColumnDefinition {
            /** @var Blueprint $this */
            $column ??= (string) config('addressing.public_ids.column', 'public_id');

            return $this->string($column, 80)->unique();
        });
    }
}

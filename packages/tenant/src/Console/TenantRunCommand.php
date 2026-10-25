<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Console;

use Efnext\Security\Tenant\Context\TenantContext;
use Efnext\Security\Tenant\Contracts\TenantLocator;
use Efnext\Security\Tenant\Resolution\Source;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run another Artisan command inside a tenant context:
 *
 *   php artisan tenant:run ten_7f3k9zq2m4x8c1bt "reports:generate --month=2026-09"
 */
final class TenantRunCommand extends Command
{
    protected $signature = 'tenant:run
        {tenant : Tenant id, public id, global object id or slug}
        {artisan : The Artisan command line to run, quoted}
        {--allow-inactive : Run even if the tenant is suspended or archived}';

    protected $description = 'Run an Artisan command with an active tenant context';

    public function handle(TenantLocator $locator, TenantContextManager $manager): int
    {
        $identifier = $this->argument('tenant');
        $commandLine = $this->argument('artisan');

        if (! is_string($identifier) || ! is_string($commandLine) || trim($commandLine) === '') {
            $this->components->error('Usage: tenant:run {tenant} {artisan command line}');

            return self::FAILURE;
        }

        $record = $locator->locate($identifier);

        if ($record === null) {
            $this->components->error(sprintf('Tenant "%s" not found.', $identifier));

            return self::FAILURE;
        }

        if (! $record->status->isActive() && ! $this->option('allow-inactive')) {
            $this->components->error(sprintf('Tenant "%s" is %s. Use --allow-inactive to run anyway.', $identifier, $record->status->value));

            return self::FAILURE;
        }

        $context = TenantContext::for($record->id, Source::Console, $record->publicId);

        /** @var OutputInterface $output */
        $output = $this->output;

        $artisan = $this->getApplication();

        if (! $artisan instanceof Application) {
            $this->components->error('tenant:run must be executed through Artisan.');

            return self::FAILURE;
        }

        return $manager->run($context, static fn (): int => $artisan->call($commandLine, [], $output));
    }
}

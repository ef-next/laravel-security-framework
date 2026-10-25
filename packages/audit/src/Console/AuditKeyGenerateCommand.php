<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Console;

use Efnext\Security\Audit\Signing\Keyring;
use Illuminate\Console\Command;

final class AuditKeyGenerateCommand extends Command
{
    protected $signature = 'audit:key:generate {--id=k1 : Key id to print in the env example}';

    protected $description = 'Generate a random HMAC key for audit signing';

    public function handle(): int
    {
        $id = $this->option('id');

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $id) !== 1) {
            $this->components->error('Key ids must match /^[A-Za-z0-9_.-]{1,32}$/.');

            return self::FAILURE;
        }

        $key = base64_encode(random_bytes(Keyring::MINIMUM_KEY_BYTES));

        $this->components->info('Add this to your environment (append to AUDIT_SIGNING_KEYS when rotating):');
        $this->line(sprintf('AUDIT_SIGNING_KEYS="%s:base64:%s"', $id, $key));
        $this->line(sprintf('AUDIT_SIGNING_ACTIVE_KEY=%s', $id));

        return self::SUCCESS;
    }
}

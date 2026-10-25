<?php

declare(strict_types=1);

/*
 * The production recipe is a .env block, not an edited config file: the list
 * of anchor stores therefore has to be settable through AUDIT_ANCHOR_STORES.
 * The config file is evaluated with the variable set, as Laravel would do it.
 */

/** @return array<string, mixed> */
function auditConfigWith(?string $stores, ?string $disk = null, ?string $path = null): array
{
    $variables = ['AUDIT_ANCHOR_STORES' => $stores, 'AUDIT_ANCHOR_DISK' => $disk, 'AUDIT_ANCHOR_PATH' => $path];

    foreach ($variables as $name => $value) {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    try {
        $config = require __DIR__.'/../../config/audit.php';
    } finally {
        foreach (array_keys($variables) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    return is_array($config) ? $config : [];
}

it('defaults to the database store when AUDIT_ANCHOR_STORES is not set', function (): void {
    $anchoring = auditConfigWith(null)['anchoring'];

    expect($anchoring['stores'])->toBe(['database'])
        ->and($anchoring['filesystem'])->toBe(['disk' => 'local', 'path' => 'audit-anchors']);
});

it('reads the anchor store list, disk and path from the environment', function (): void {
    $anchoring = auditConfigWith(' filesystem , database,, ', 'audit-anchors', 'anchors/billing')['anchoring'];

    expect($anchoring['stores'])->toBe(['filesystem', 'database'])
        ->and($anchoring['filesystem'])->toBe(['disk' => 'audit-anchors', 'path' => 'anchors/billing']);
});

it('passes a custom store class through unchanged', function (): void {
    expect(auditConfigWith('App\\Audit\\WormAnchorStore,database')['anchoring']['stores'])->toBe(['App\\Audit\\WormAnchorStore', 'database']);
});

it('falls back to the database store when AUDIT_ANCHOR_STORES is set but empty', function (): void {
    expect(auditConfigWith('')['anchoring']['stores'])->toBe(['database'])
        ->and(auditConfigWith(' , ')['anchoring']['stores'])->toBe(['database']);
});

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| These tests fail the build when a package grows a dependency it must not
| have. The dependency direction is addressing <- tenant <- audit.
|
*/

arch('packages use strict types')
    ->expect('Efnext\Security')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('addressing does not depend on tenant or audit')
    ->expect('Efnext\Security\Addressing')
    ->not->toUse(['Efnext\Security\Tenant', 'Efnext\Security\Audit']);

arch('tenant does not depend on audit')
    ->expect('Efnext\Security\Tenant')
    ->not->toUse('Efnext\Security\Audit');

arch('no host application classes are referenced')
    ->expect('Efnext\Security')
    ->not->toUse(['App\Models', 'App\Support', 'App\Http']);

arch('value objects are final and readonly')
    ->expect([
        'Efnext\Security\Addressing\Identifiers',
        'Efnext\Security\Tenant\Context',
        'Efnext\Security\Audit\Records',
    ])
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('the hashing core is framework-free')
    ->expect('Efnext\Security\Audit\Hashing')
    ->not->toUse(['Illuminate', 'Symfony', 'Carbon']);

arch('the sanitizer is framework-free')
    ->expect('Efnext\Security\Audit\Sanitizing')
    ->not->toUse(['Illuminate', 'Symfony', 'Carbon']);

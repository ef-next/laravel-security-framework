<?php

declare(strict_types=1);

use Efnext\Security\Tests\DatabaseTestCase;
use Efnext\Security\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Unit directories get the lightweight application test case, Feature
| directories a freshly migrated database per test.
|
*/

uses(TestCase::class)->in(
    __DIR__.'/Arch',
    dirname(__DIR__).'/packages/addressing/tests/Unit',
    dirname(__DIR__).'/packages/tenant/tests/Unit',
    dirname(__DIR__).'/packages/audit/tests/Unit',
);

uses(DatabaseTestCase::class)->in(
    dirname(__DIR__).'/packages/addressing/tests/Feature',
    dirname(__DIR__).'/packages/tenant/tests/Feature',
    dirname(__DIR__).'/packages/audit/tests/Feature',
);

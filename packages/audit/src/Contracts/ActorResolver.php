<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

use Efnext\Security\Audit\Records\Actor;

interface ActorResolver
{
    public function resolve(): Actor;
}

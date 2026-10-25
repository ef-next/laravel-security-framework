<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Doctor;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Warn => 'WARN',
            self::Fail => 'FAIL',
            self::Skip => 'SKIP',
        };
    }
}

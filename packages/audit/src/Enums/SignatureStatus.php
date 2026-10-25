<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Enums;

enum SignatureStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case UnknownKey = 'unknown_key';
    case NotConfigured = 'not_configured';
}

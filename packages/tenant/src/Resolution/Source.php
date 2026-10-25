<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

/**
 * Where a tenant context came from. Recorded for forensics and audit events.
 */
enum Source: string
{
    case Header = 'header';
    case Route = 'route';
    case Session = 'session';
    case Subdomain = 'subdomain';
    case Queue = 'queue';
    case Console = 'console';
    case Manual = 'manual';
}

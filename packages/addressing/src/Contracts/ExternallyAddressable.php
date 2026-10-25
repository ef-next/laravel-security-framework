<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Contracts;

use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;

/**
 * A model that can be addressed from other services.
 */
interface ExternallyAddressable
{
    public function publicId(): PublicId;

    /** Lowercase resource type used in global object ids, e.g. "domain". */
    public static function resourceType(): string;

    public function globalObjectId(): GlobalObjectId;
}

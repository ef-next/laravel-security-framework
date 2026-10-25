<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Tests\Fixtures;

use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;
use Illuminate\Database\Eloquent\Model;

final class Document extends Model implements ExternallyAddressable
{
    use HasGlobalObjectId;
    use HasPublicId;

    public $timestamps = false;

    protected $table = 'documents';

    protected $guarded = [];

    public static function publicIdPrefix(): string
    {
        return 'doc';
    }

    public static function resourceType(): string
    {
        return 'document';
    }
}

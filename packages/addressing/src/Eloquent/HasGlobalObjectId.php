<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Eloquent;

use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds "<service>:<resource-type>:<public-id>" for a model.
 *
 * Use together with HasPublicId and implement ExternallyAddressable
 * (which requires a static resourceType()).
 *
 * @mixin Model
 */
trait HasGlobalObjectId
{
    abstract public function publicId(): PublicId;

    abstract public function publicIdColumn(): string;

    abstract public static function resourceType(): string;

    public function globalObjectId(): GlobalObjectId
    {
        return GlobalObjectId::of(static::serviceName(), static::resourceType(), $this->publicId());
    }

    public static function serviceName(): string
    {
        $configured = config('addressing.service_name', 'app');

        return is_string($configured) && $configured !== '' ? $configured : 'app';
    }

    /**
     * Matches only ids of this service and resource type; anything else yields no rows.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereGlobalObjectId(Builder $query, GlobalObjectId|string $id): Builder
    {
        $value = $id instanceof GlobalObjectId ? $id : GlobalObjectId::tryFromString($id);

        if ($value === null || ! $value->is(static::serviceName(), static::resourceType())) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn($this->publicIdColumn()), $value->publicId->toString());
    }
}

<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Eloquent;

use Efnext\Security\Addressing\Contracts\PublicIdGenerator;
use Efnext\Security\Addressing\Exceptions\InvalidPublicId;
use Efnext\Security\Addressing\Exceptions\MissingPublicId;
use Efnext\Security\Addressing\Exceptions\PublicIdImmutable;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Assigns an immutable public id on creation and uses it as the route key.
 *
 * Requirements:
 *  - a string column (default "public_id", see config addressing.public_ids.column)
 *    with a UNIQUE index: $table->publicId() in your migration.
 *  - a static publicIdPrefix() method returning the prefix, e.g. "dom".
 *
 * Bulk operations (Model::insert(), upsert()) bypass Eloquent events and do not
 * receive a public id. Generate one via PublicIdGenerator in that case.
 *
 * @mixin Model
 */
trait HasPublicId
{
    abstract public static function publicIdPrefix(): string;

    public static function bootHasPublicId(): void
    {
        static::creating(static function (Model $model): void {
            /** @var Model&self $model */
            $column = $model->publicIdColumn();
            $current = $model->getAttribute($column);

            if (is_string($current) && $current !== '') {
                $parsed = PublicId::tryFromString($current);

                if ($parsed === null || ! $parsed->hasPrefix(static::publicIdPrefix())) {
                    throw InvalidPublicId::malformed($current);
                }

                return;
            }

            /** @var PublicIdGenerator $generator */
            $generator = app(PublicIdGenerator::class);

            $model->setAttribute($column, $generator->generateUnique(
                prefix: static::publicIdPrefix(),
                table: $model->getTable(),
                column: $column,
                connection: $model->getConnectionName(),
            )->toString());
        });

        static::updating(static function (Model $model): void {
            /** @var Model&self $model */
            if ($model->isDirty($model->publicIdColumn())) {
                throw PublicIdImmutable::forModel($model::class, $model->publicIdColumn());
            }
        });
    }

    /**
     * Keep the public id out of mass assignment unless the model opted in explicitly.
     */
    public function initializeHasPublicId(): void
    {
        $column = $this->publicIdColumn();

        if ($this->guarded === ['*'] || in_array($column, $this->fillable, true) || in_array($column, $this->guarded, true)) {
            return;
        }

        $this->guarded[] = $column;
    }

    public function publicIdColumn(): string
    {
        $configured = config('addressing.public_ids.column', 'public_id');

        return is_string($configured) && $configured !== '' ? $configured : 'public_id';
    }

    public function publicId(): PublicId
    {
        $value = $this->getAttribute($this->publicIdColumn());

        if (! is_string($value) || $value === '') {
            throw MissingPublicId::forModel(static::class, $this->publicIdColumn());
        }

        return PublicId::fromString($value);
    }

    public function getRouteKeyName(): string
    {
        return $this->publicIdColumn();
    }

    /**
     * Route binding with a value that is not a well-formed public id never hits the database.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (($field === null || $field === $this->publicIdColumn()) && ! PublicId::isValid(is_scalar($value) ? (string) $value : null)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, PublicId|string $publicId): Builder
    {
        $value = $publicId instanceof PublicId ? $publicId : PublicId::tryFromString($publicId);

        if ($value === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn($this->publicIdColumn()), $value->toString());
    }
}

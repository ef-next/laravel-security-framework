<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned model with a custom tenant column.
 *
 * @property int $id
 * @property string $org_id
 * @property string $name
 */
final class Project extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'projects';

    protected $guarded = [];

    protected string $tenantColumn = 'org_id';
}

<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests\ModuleFixtures\AiGovernance;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = [];

    public function getRolesAttribute(): Collection
    {
        return collect();
    }
}

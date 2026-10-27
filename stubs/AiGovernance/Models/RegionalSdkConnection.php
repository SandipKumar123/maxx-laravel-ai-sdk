<?php

namespace Modules\AiGovernance\Models;

use Illuminate\Database\Eloquent\Model;

final class RegionalSdkConnection extends Model
{
    protected $table = 'regional_sdk_connections';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'base_url', 'access_token', 'enabled', 'verified_at',
        'resource_id', 'model_id', 'execution_region', 'executor_subject'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'enabled' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }
}

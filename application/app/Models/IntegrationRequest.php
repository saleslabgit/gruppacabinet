<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationRequest extends Model
{
    protected $table = 'gp_integration_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['response_status' => 'integer', 'completed_at' => 'datetime'];
    }
}

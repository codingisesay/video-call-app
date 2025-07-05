<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoCall extends Model
{
    protected $fillable = [
        'agent_id',
        'client_id',
        'call_token',
        'file_path',
        'duration_seconds',
        'started_at',
        'ended_at',
        'status',
    ];
}

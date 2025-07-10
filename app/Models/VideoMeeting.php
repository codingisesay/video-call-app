<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoMeeting extends Model
{
    protected $fillable = [
        'project_name',
        'meeting_token',
        'agent_id',
        'application_id',
        'kyc_application_id',
        'customer_name',
        'customer_email',
        'expires_at',
        'status',
    ];
}

 

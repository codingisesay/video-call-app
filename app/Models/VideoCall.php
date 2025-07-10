<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoCall extends Model
{
    protected $fillable = [
        'video_meeting_id',
        'file_path',
        'status',
    ];
}

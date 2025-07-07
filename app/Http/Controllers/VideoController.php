<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VideoCall;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
public function upload(Request $request)
{
    try {
        if ($request->hasFile('video')) {
            $path = $request->file('video')->store('videos');

            $videoCall = VideoCall::create([
                'video_meeting_id' => $request->video_meeting_id,
                'file_path'        => $path,
                'duration_seconds' => $request->duration,
                'started_at'       => $request->started_at,
                'ended_at'         => $request->ended_at,
                'status'           => 'uploaded',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Upload successful!',
                'path' => $path,
                'data' => $videoCall,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No video uploaded.'
        ], 400);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage(),
        ], 500);
    }
}
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VideoCall;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function upload(Request $request)
    {
        if ($request->hasFile('video')) {
            $path = $request->file('video')->store('videos');

            $videoCall = VideoCall::create([
                'agent_id'         => 1, // for local testing, hard-coded agent
                'client_id'        => null,
                'call_token'       => $request->call_token,
                'file_path'        => $path,
                'duration_seconds' => $request->duration,
                'started_at'       => $request->started_at,
                'ended_at'         => $request->ended_at,
                'status'           => 'uploaded',
            ]);

            return response()->json([
                'message' => 'Upload successful!',
                'path' => $path,
            ]);
        }

        return response()->json(['message' => 'No video uploaded.'], 400);
    }
}

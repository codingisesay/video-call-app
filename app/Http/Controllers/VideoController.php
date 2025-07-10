<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VideoCall;
use App\Models\VideoMeeting;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
public function upload(Request $request)
{

        // Increase PHP runtime limits
    ini_set('upload_max_filesize', '100M');
    ini_set('post_max_size', '100M');
    ini_set('max_execution_time', '300');
    ini_set('memory_limit', '512M');

    \Log::info('Incoming request fields:', $request->all());
    \Log::info('Incoming request files:', $request->allFiles());

    $meetingToken = $request->input('call_token');

    $videoMeeting = VideoMeeting::where('meeting_token', $meetingToken)->first();

    if (!$videoMeeting) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid meeting token.'
        ], 404);
    }

    // Check if video is a file
    $file = $request->file('video');

    if ($file) {
        $path = $file->store('videos','public');

        $videoCall = VideoCall::create([
            'video_meeting_id' => $videoMeeting->id,
            'file_path'        => $path,
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
}


public function fetchVideoDetailsByApplicationOrKyc(Request $request)
{
    try {
        $applicationId = $request->application_id;
        $kycApplicationId = $request->kyc_application_id;

        if (!$applicationId && !$kycApplicationId) {
            return response()->json([
                'success' => false,
                'message' => 'Either application_id or kyc_application_id must be provided.',
            ], 422);
        }

        $query = VideoCall::join(
                'video_meetings',
                'video_calls.video_meeting_id',
                '=',
                'video_meetings.id'
            )
            ->select(
                'video_calls.*',
                'video_meetings.project_name',
                'video_meetings.customer_name',
                'video_meetings.customer_email',
                'video_meetings.meeting_token',
                'video_meetings.application_id',
                'video_meetings.kyc_application_id',
                'video_meetings.expires_at'
            );

        if ($applicationId) {
            $query->where('video_meetings.application_id', $applicationId);
        } else {
            $query->where('video_meetings.kyc_application_id', $kycApplicationId);
        }

        $data = $query->get();

        if ($data->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No video call found for the given application or KYC application ID.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch video details: ' . $e->getMessage(),
        ], 500);
    }
}



}

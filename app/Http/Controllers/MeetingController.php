<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\VideoMeeting;

class MeetingController extends Controller
{
    public function create(Request $request)
    {
        $token = Str::random(20);

        $meeting = VideoMeeting::create([
            'meeting_token'    => $token,
            'agent_id'         => $request->agent_id,
            'application_id'   => $request->application_id,
            'customer_name'    => $request->customer_name,
            'customer_email'   => $request->customer_email,
            'expires_at'       => now()->addMinutes(30),
            'status'           => 'active',
        ]);

        return response()->json([
            'meeting_link' => url('/join/' . $token),
        ]);
    }

    public function join($token)
    {
        $meeting = VideoMeeting::where('meeting_token', $token)
            ->where('expires_at', '>', now())
            ->firstOrFail();

        return view('video-call', [
            'meeting' => $meeting,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\VideoMeeting;
use Illuminate\Support\Facades\Mail;

class MeetingController extends Controller
{
public function create(Request $request)
{
    try {
        $token = Str::random(20);

        $meeting = VideoMeeting::create([
            'project_name'     => 'DAO',
            'meeting_token'    => $token,
            'agent_id'         => $request->agent_id,
            'application_id'   => $request->application_id,
            'customer_name'    => $request->customer_name,
            'customer_email'   => $request->customer_email,
            'expires_at'       => now()->addMinutes(50),
            'status'           => 'active',
        ]);

        $meetingLink = url('/join/' . $token);

        // Send email to customer with the meeting link
        Mail::raw(
            "Dear {$request->customer_name},\n\nYour video call is scheduled. Please join using the following link:\n\n{$meetingLink}\n\nThank you.",
            function ($message) use ($request) {
                $message->to($request->customer_email)
                        ->subject('Your Video Call Link');
            }
        );

        return response()->json([
            'success' => true,
            'meeting_link' => $meetingLink,
            'data' => $meeting,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to create meeting: ' . $e->getMessage(),
        ], 500);
    }
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

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\VideoMeeting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MeetingController extends Controller
{
    public function create(Request $request)
    {
        // 1) Validate inputs
        $data = $request->validate([
            'agent_id'            => 'nullable|string|max:191',
            'application_id'      => 'nullable|string|max:191',
            'kyc_application_id'  => 'nullable|string|max:191',
            'customer_name'       => 'nullable|string|max:191',
            'customer_email'      => 'nullable|email|max:191',
            'expires_in_minutes'  => 'nullable|integer|min:10|max:240', // 10 min .. 4 hours
        ]);

        try {
            // 2) Guaranteed-unique token (loop until unique or use UUID)
            // If you added a UNIQUE index on meeting_token this will be safe.
            do {
                $token = Str::random(20);
            } while (VideoMeeting::where('meeting_token', $token)->exists());

            // 3) Create meeting
            $meeting = VideoMeeting::create([
                'project_name'       => 'DAO',
                'meeting_token'      => $token,
                'agent_id'           => $data['agent_id']            ?? null,
                'application_id'     => $data['application_id']      ?? null,
                'kyc_application_id' => $data['kyc_application_id']  ?? null,
                'customer_name'      => $data['customer_name']       ?? null,
                'customer_email'     => $data['customer_email']      ?? null,
                'expires_at'         => now()->addMinutes($data['expires_in_minutes'] ?? 50),
                'status'             => 'active',
            ]);

            $meetingLink = url('/join/' . $token);

            // 4) Email (only if customer_email is present)
            if (!empty($data['customer_email'])) {
                try {
                    Mail::raw(
                        "Dear {$data['customer_name']},\n\nYour video call is scheduled. Please join using the following link:\n\n{$meetingLink}\n\nThank you.",
                        function ($message) use ($data) {
                            $message->to($data['customer_email'])
                                    ->subject('Your Video Call Link');
                        }
                    );
                } catch (\Throwable $e) {
                    // Don't fail the API just because the email failed.
                    Log::warning('Meeting email failed: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success'      => true,
                'meeting_link' => $meetingLink,
                'data'         => $meeting,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create meeting: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function join($token, Request $request)
    {
        // Find non-expired meeting
        $meeting = VideoMeeting::where('meeting_token', $token)
            ->where('expires_at', '>', now())
            ->firstOrFail();

        // ⚠️ Browsers do NOT send Authorization header on HTML GET.
        // Try to grab a JWT from:
        //   - Authorization header (if present; e.g., when you open /join with a tool)
        //   - session('jwt_token')
        //   - query param ?t= (optional fallback — only if you explicitly allow it)
        $jwtToken = $request->bearerToken()
            ?? session('jwt_token')
            ?? $request->query('t'); // remove this line if you do NOT want to allow ?t=token

        // If you want to persist the JWT for subsequent page reloads:
        if ($jwtToken) {
            session(['jwt_token' => $jwtToken]);
        }

        // Render Blade and pass meeting + jwt
        $response = response(
            view('video-call', [
                'meeting'  => $meeting,
                'jwtToken' => $jwtToken, // used by your fixed Blade to set window.Laravel.jwtToken
            ])
        );

        // Frame-embed: CSP frame-ancestors is the modern/portable control.
        // Your original had: "frame-ancestors https://172.16.1.224:9443;"
        // Keep that, and optionally add 'self' if you plan to embed locally too.
        $csp = "frame-ancestors 'self' https://172.16.1.224:9443";
        // If you need http as well (dev), you can add: http://172.16.1.224:9443

        return $response
            ->header('Content-Security-Policy', $csp);

        // Note: X-Frame-Options is deprecated and conflicts with CSP in some cases.
        // If you MUST send it, you can omit or send 'ALLOWALL' (non-standard). Best: rely on CSP only.
    }
}

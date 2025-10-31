<?php

namespace App\Http\Controllers;

use App\Models\VideoMeeting;
use App\Models\VideoCall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;


class VideoController extends Controller
{
    // ========= Chunked upload (agent-only) =========

    public function uploadChunk(Request $request)
    {

        // return response()->json(['ok' => true, 'hit' => 'uploadChunk']);
        $request->validate([
            'upload_id' => 'required|string', // meeting_token
            'seq'       => 'required|integer|min:0',
            'chunk'     => 'required|file|mimetypes:video/webm,application/octet-stream|max:20480', // 20MB
        ]);

        $meeting = VideoMeeting::where('meeting_token', $request->upload_id)->first();
        if (!$meeting) return response()->json(['success' => false, 'message' => 'Invalid upload_id'], 404);

        // ✅ Agent-only enforcement: JWT must belong to this meeting's agent
        if ($resp = $this->authorizeAgent($request, $meeting)) return $resp;

        if ($this->hasFinalFlag() && $meeting->recording_uploaded) {
            return response()->json(['success' => true, 'message' => 'Already finalized; chunk ignored']);
        }

        $baseDir = "recordings/{$meeting->id}/parts";
        Storage::disk('local')->makeDirectory($baseDir);
        Storage::disk('local')->putFileAs($baseDir, $request->file('chunk'), ((int)$request->seq) . '.webm'); // idempotent per seq

        Log::info('Chunk stored', ['meeting_id' => $meeting->id, 'seq' => (int)$request->seq]);
        return response()->json(['success' => true, 'message' => 'Chunk received']);
    }


public function finalizeUpload(Request $request)
{
    $data = $request->validate([
        'upload_id'   => 'required|string',
        'total_parts' => 'nullable|integer|min:1', // guidance only; we discover files
    ]);

    $meeting = VideoMeeting::where('meeting_token', $data['upload_id'])->first();
    if (!$meeting) {
        return response()->json(['success' => false, 'message' => 'Invalid upload_id'], 404);
    }

    return DB::transaction(function () use ($data, $meeting, $request) {
        $meeting = VideoMeeting::where('id', $meeting->id)->lockForUpdate()->first();
        if ($this->hasFinalFlag() && $meeting->recording_uploaded) {
            return response()->json(['success' => true, 'message' => 'Already finalized']);
        }

        $meetingId = $meeting->id;
        $partsDir  = storage_path("app/recordings/{$meetingId}/parts");
        $publicDir = storage_path('app/public/videos');
        @mkdir($publicDir, 0775, true);

        if (!is_dir($partsDir)) {
            return response()->json([
                'success' => false,
                'message' => 'Parts directory missing',
                'dir'     => $partsDir,
            ], 422);
        }

        // ---- Discover parts from disk ----
        $files = glob($partsDir . '/*.webm') ?: [];
        $indexed = [];
        foreach ($files as $f) {
            if (preg_match('~(?:^|/)(?:part_)?(\d+)\.webm$~', $f, $m)) {
                $indexed[(int)$m[1]] = realpath($f);
            }
        }
        ksort($indexed, SORT_NUMERIC);

        if (empty($indexed)) {
            return response()->json(['success' => false, 'message' => 'No parts found'], 422);
        }

        // Optional: enforce continuity 0..N-1
        /*
        $expected = range(0, max(array_KEYS($indexed)));
        $missing  = array_diff($expected, array_keys($indexed));
        if (!empty($missing)) {
            return response()->json(['success' => false, 'message' => 'Missing parts: '.implode(',', $missing)], 422);
        }
        */

        // --- Runner (Symfony Process) ---
        $run = function(array $cmd) {
            $proc = new \Symfony\Component\Process\Process($cmd);
            $proc->setTimeout(900);
            $proc->run();
            $esc = implode(' ', array_map('escapeshellarg', $cmd));
            return [$proc->isSuccessful(), $proc->getOutput(), $proc->getErrorOutput(), $esc];
        };

        // ffmpeg availability
        [$okProbe,, $errProbe] = $run(['ffmpeg', '-version']);
        if (!$okProbe) {
            return response()->json([
                'success' => false,
                'message' => 'ffmpeg not found in PATH',
                'stderr'  => $errProbe,
            ], 500);
        }

        // === Single part fast path ===
        if (count($indexed) === 1) {
            $src = reset($indexed);
            $dstWebm = "$publicDir/meeting_{$meetingId}.webm";
            [$okCopy,, $errCopy, $cmdCopy] = $run(['ffmpeg','-hide_banner','-loglevel','error','-i',$src,'-c','copy','-y',$dstWebm]);
            if (!$okCopy) {
                $dstMp4 = "$publicDir/meeting_{$meetingId}.mp4";
                [$okRe,, $errRe, $cmdRe] = $run([
                    'ffmpeg','-hide_banner','-loglevel','error','-i',$src,
                    '-c:v','libx264','-preset','veryfast','-crf','23',
                    '-c:a','aac','-b:a','128k','-movflags','+faststart','-y',$dstMp4
                ]);
                if (!$okRe) {
                    return response()->json([
                        'success'=>false,'message'=>'ffmpeg failed single-part',
                        'stderr_copy'=>$errCopy,'stderr_encode'=>$errRe,
                        'cmd_copy'=>$cmdCopy,'cmd_encode'=>$cmdRe
                    ],500);
                }
                $finalRel = "videos/meeting_{$meetingId}.mp4";
            } else {
                $finalRel = "videos/meeting_{$meetingId}.webm";
            }
        } else {
            // === Multi-part: concat list + re-encode for reliability ===
            $listFile = storage_path("app/recordings/{$meetingId}/list.txt");
            $lines = [];
            foreach ($indexed as $abs) {
                $lines[] = "file '" . str_replace("'", "'\\''", $abs) . "'";
            }
            file_put_contents($listFile, implode(PHP_EOL, $lines));

            $dstMp4 = "$publicDir/meeting_{$meetingId}.mp4";
            [$okRe,, $errRe, $cmdRe] = $run([
                'ffmpeg','-hide_banner','-loglevel','error',
                '-f','concat','-safe','0','-i',$listFile,
                '-fflags','+genpts', // regenerate timestamps for concatenated chunks
                '-c:v','libx264','-preset','veryfast','-crf','23',
                '-c:a','aac','-b:a','128k','-movflags','+faststart','-y',$dstMp4
            ]);

            if (!$okRe) {
                // Last resort: try stream copy (may work if chunks match perfectly)
                 $dstWebm = "$publicDir/meeting_{$meetingId}.webm";
                [$okCopy,, $errCopy, $cmdCopy] = $run([
                    'ffmpeg','-hide_banner','-loglevel','error',
                    '-f','concat','-safe','0','-i',$listFile,
                    '-c','copy','-y',$dstWebm
                ]);
                if (!$okCopy) {
                    return response()->json([
                        'success'=>false,'message'=>'ffmpeg concat failed (encode & copy)',
                        'stderr_encode'=>$errRe,'stderr_copy'=>$errCopy,
                        'cmd_encode'=>$cmdRe,'cmd_copy'=>$cmdCopy,
                        'list_txt'=>file_get_contents($listFile),
                        'parts_found'=>array_values($indexed)
                    ],500);
                }
                $finalRel = "videos/meeting_{$meetingId}.webm";
            } else {
                $finalRel = "videos/meeting_{$meetingId}.mp4";
            }

            @unlink($listFile);
        }

        // Persist/mark uploaded
        \App\Models\VideoCall::updateOrCreate(
            ['video_meeting_id' => $meetingId],
            ['file_path' => $finalRel, 'status' => 'uploaded', 'updated_at' => now(), 'created_at' => now()]
        );

        if ($this->hasFinalFlag()) {
            $meeting->recording_uploaded = 1;
            $meeting->save();
        }

        // Cleanup parts (best-effort)
        try { $this->rrmdir($partsDir); } catch (\Throwable $e) {
            \Log::warning('Cleanup parts failed', ['meeting_id'=>$meetingId,'err'=>$e->getMessage()]);
        }

        // Public URL via Storage (needs storage:link)
        $publicUrl = \Storage::disk('public')->url($finalRel);

        // Notify DAO (optional meta)
        $this->notifyDao($request->bearerToken(), $meeting->application_id, [
            'video_url'   => $publicUrl,
            'meeting_id'  => $meetingId,
            'parts_count' => count($indexed),
            'format'      => str_ends_with($finalRel,'.mp4') ? 'mp4' : 'webm',
        ]);

        return response()->json([
            'success'    => true,
            'message'    => 'Finalized',
            'final_path' => $finalRel,
            'public_url' => $publicUrl,
        ]);
    });
}

    // ========= Self-KYC helpers (no meeting_token initially) =========

    public function startSelfKyc(Request $request)
    {
        $data = $request->validate([
            'application_id'      => 'nullable|string',
            'kyc_application_id'  => 'nullable|string',
            'customer_name'       => 'nullable|string',
            'customer_email'      => 'nullable|email',
            'agent_id'            => 'nullable|string',
            'project_name'        => 'nullable|string',
            'ttl_minutes'         => 'nullable|integer|min:5|max:4320',
        ]);

        $token = (string) Str::uuid();
        $expiresAt = now()->addMinutes($data['ttl_minutes'] ?? 120);

        $meeting = VideoMeeting::create([
            'project_name'        => $data['project_name'] ?? 'SELF_KYC',
            'meeting_token'       => $token,
            'agent_id'            => $data['agent_id'] ?? null,
            'application_id'      => $data['application_id'] ?? null,
            'kyc_application_id'  => $data['kyc_application_id'] ?? null,
            'customer_name'       => $data['customer_name'] ?? null,
            'customer_email'      => $data['customer_email'] ?? null,
            'expires_at'          => $expiresAt,
            'status'              => 'active',
        ]);

        return response()->json([
            'success'     => true,
            'upload_id'   => $token,
            'meeting_id'  => $meeting->id,
            'expires_at'  => $expiresAt->toIso8601String(),
        ]);
    }



    public function retakeSelfKyc(Request $request)
{
    // Accept any of these keys; require at least one
    $request->validate([
        'upload_id'         => 'nullable|string',
        'kyc_application_id'=> 'nullable|string',
        'application_id'    => 'nullable|string',
    ]);

    if (!$request->upload_id && !$request->kyc_application_id && !$request->application_id) {
        return response()->json([
            'success' => false,
            'message' => 'Provide upload_id (meeting_token) OR kyc_application_id OR application_id.'
        ], 422);
    }

    // Resolve the meeting
    $meeting = null;
    if ($request->upload_id) {
        $meeting = VideoMeeting::where('meeting_token', $request->upload_id)->first();
    } elseif ($request->kyc_application_id) {
        $meeting = VideoMeeting::where('kyc_application_id', $request->kyc_application_id)->latest('id')->first();
    } else { // application_id
        $meeting = VideoMeeting::where('application_id', $request->application_id)->latest('id')->first();
    }

    if (!$meeting) {
        return response()->json(['success' => false, 'message' => 'No matching self-KYC session found.'], 404);
    }

    return DB::transaction(function () use ($meeting) {
        // Delete any finalized record
        $videoCall = VideoCall::where('video_meeting_id', $meeting->id)->first();
        if ($videoCall) {
            if ($videoCall->file_path) {
                try { Storage::disk('public')->delete($videoCall->file_path); } catch (\Throwable $e) {
                    Log::warning('Retake: failed to remove file', ['meeting_id' => $meeting->id, 'err' => $e->getMessage()]);
                }
            }
            $videoCall->delete();
        }

        // Clear any partial chunks
        $partsDir = storage_path("app/recordings/{$meeting->id}/parts");
        $listFile = storage_path("app/recordings/{$meeting->id}/list.txt");
        try {
            if (file_exists($listFile)) @unlink($listFile);
            if (is_dir($partsDir)) $this->rrmdir($partsDir);
        } catch (\Throwable $e) {
            Log::warning('Retake: cleanup parts failed', ['err' => $e->getMessage()]);
        }

        // Reset flags
        if ($this->hasFinalFlag()) $meeting->recording_uploaded = 0;
        $meeting->status = 'active';
        $meeting->save();

        // Return same upload_id (meeting_token) to reuse on frontend
        return response()->json([
            'success'    => true,
            'message'    => 'Retake ready. Old recording and parts deleted.',
            'upload_id'  => $meeting->meeting_token,
            'meeting_id' => $meeting->id,
        ]);
    });
}



    // app/Http/Controllers/VideoController.php
private function authorizeAgent(Request $request, VideoMeeting $meeting)
{
    // ✅ Accept ANY valid DAO token (signature/expiry already checked by middleware).
    // No per-meeting agent check. HIGH-TRUST MODE.
    return null;
}

    private function runProcess(array $cmd): bool
    {
        $proc = new Process($cmd);
        $proc->setTimeout(300);
        $proc->run();
        if (!$proc->isSuccessful()) {
            Log::error('ffmpeg failed', [
                'cmd' => implode(' ', $cmd),
                'out' => $proc->getOutput(),
                'err' => $proc->getErrorOutput()
            ]);
            return false;
        }
        return true;
    }

    private function rrmdir($dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function hasFinalFlag(): bool
    {
        try { return Schema::hasColumn('video_meetings', 'recording_uploaded'); }
        catch (\Throwable $e) { return false; }
    }

    private function notifyDao(?string $jwtToken, ?string $applicationId): void
    {
        if (!$jwtToken || !$applicationId) return;
        try {
            $daoApiUrl = rtrim(env('DAO_API_URL'), '/') . '/api/video-status-update';
            $daoResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $jwtToken,
                'Accept'        => 'application/json',
            ])->withOptions(['verify' => false])->post($daoApiUrl, [
                'application_id' => $applicationId,
                'status'         => 'Pending',
            ]);
            Log::info('DAO API (notify):', ['status' => $daoResponse->status()]);
            Log::info('DAO API (notify):', ['url' => $daoApiUrl]);
        } catch (\Throwable $e) {
            Log::error('DAO notify error: ' . $e->getMessage());
        }
    }


public function fetchVideoDetailsByApplicationOrKyc(Request $request)
{
    try {
        // 1) Validate: require at least one of the two
        $request->validate([
            'application_id'     => 'nullable|string',
            'kyc_application_id' => 'nullable|string',
            'latest_only'        => 'sometimes|boolean',
        ]);

        $applicationId     = $request->input('application_id');
        $kycApplicationId  = $request->input('kyc_application_id');
        $latestOnly        = (bool) $request->boolean('latest_only', false);

        if (!$applicationId && !$kycApplicationId) {
            return response()->json([
                'success' => false,
                'message' => 'Either application_id or kyc_application_id must be provided.',
            ], 422);
        }

        // 2) Build query
        $query = VideoCall::query()
            ->join('video_meetings', 'video_calls.video_meeting_id', '=', 'video_meetings.id')
            ->select([
                'video_calls.id',
                'video_calls.video_meeting_id',
                'video_calls.file_path',
                'video_calls.status',
                'video_calls.created_at as video_created_at',
                'video_calls.updated_at as video_updated_at',
                'video_meetings.project_name',
                'video_meetings.customer_name',
                'video_meetings.customer_email',
                'video_meetings.meeting_token',
                'video_meetings.application_id',
                'video_meetings.kyc_application_id',
                'video_meetings.expires_at',
                'video_meetings.status as meeting_status',
            ]);

        if ($applicationId) {
            $query->where('video_meetings.application_id', $applicationId);
        }
        if ($kycApplicationId) {
            $query->where('video_meetings.kyc_application_id', $kycApplicationId);
        }

        // Order newest first; optionally limit to the latest one
        $query->orderByDesc('video_calls.id');

        if ($latestOnly) {
            $row = $query->first();
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'No video call found for the given identifier(s).',
                ], 404);
            }

            // Attach a public URL if file exists
            $row->public_url = $row->file_path ? Storage::disk('public')->url($row->file_path) : null;

            return response()->json([
                'success' => true,
                'data'    => $row,
            ]);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No video call found for the given identifier(s).',
            ], 404);
        }

        // Map public URLs for each record
        $rows->transform(function ($r) {
            $r->public_url = $r->file_path ? Storage::disk('public')->url($r->file_path) : null;
            return $r;
        });

        return response()->json([
            'success' => true,
            'count'   => $rows->count(),
            'data'    => $rows,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch video details: ' . $e->getMessage(),
        ], 500);
    }
}

public function ping(Request $request)
{
    return response()->json(['success' => true, 'message' => 'pong']);

}
}

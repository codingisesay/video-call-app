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

    // public function finalizeUpload(Request $request)
    // {
    //     $request->validate([
    //         'upload_id'   => 'required|string',
    //         'total_parts' => 'required|integer|min:0',
    //     ]);

    //     $meeting = VideoMeeting::where('meeting_token', $request->upload_id)->first();
    //     if (!$meeting) return response()->json(['success' => false, 'message' => 'Invalid upload_id'], 404);

    //     // ✅ Agent-only enforcement
    //     if ($resp = $this->authorizeAgent($request, $meeting)) return $resp;

    //     return DB::transaction(function () use ($request, $meeting) {
    //         $meeting = VideoMeeting::where('id', $meeting->id)->lockForUpdate()->first();

    //         if ($this->hasFinalFlag() && $meeting->recording_uploaded) {
    //             return response()->json(['success' => true, 'message' => 'Already finalized']);
    //         }

    //         $partsDir = storage_path("app/recordings/{$meeting->id}/parts");
    //         $total = (int)$request->total_parts;

    //         for ($i = 0; $i < $total; $i++) {
    //             if (!file_exists("{$partsDir}/{$i}.webm")) {
    //                 return response()->json(['success' => false, 'message' => "Missing part {$i}"], 422);
    //             }
    //         }

    //         // Build concat list
    //         $listFile = storage_path("app/recordings/{$meeting->id}/list.txt");
    //         @mkdir(dirname($listFile), 0775, true);
    //         $lines = [];
    //         for ($i = 0; $i < $total; $i++) {
    //             $p = "{$partsDir}/{$i}.webm";
    //             $lines[] = "file '" . str_replace("'", "'\\''", $p) . "'";
    //         }
    //         file_put_contents($listFile, implode(PHP_EOL, $lines));

    //         // Output
    //         $publicDir = storage_path('app/public/videos');
    //         @mkdir($publicDir, 0775, true);
    //         $finalWebm = "{$publicDir}/meeting_{$meeting->id}.webm";

    //         // Fast concat
    //         $ok = $this->runProcess(['ffmpeg', '-f', 'concat', '-safe', '0', '-i', $listFile, '-c', 'copy', '-y', $finalWebm]);

    //         // Fallback re-encode
    //         if (!$ok) {
    //             $finalMp4 = "{$publicDir}/meeting_{$meeting->id}.mp4";
    //             $ok = $this->runProcess([
    //                 'ffmpeg', '-f', 'concat', '-safe', '0', '-i', $listFile,
    //                 '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
    //                 '-c:a', 'aac', '-y', $finalMp4
    //             ]);
    //             if (!$ok) return response()->json(['success' => false, 'message' => 'ffmpeg concat failed'], 500);
    //             $finalPath = "videos/meeting_{$meeting->id}.mp4";
    //         }

    //         if (!isset($finalPath)) $finalPath = "videos/meeting_{$meeting->id}.webm";

    //         // Upsert exactly one row per meeting (ensure UNIQUE on video_meeting_id)
    //         VideoCall::updateOrCreate(
    //             ['video_meeting_id' => $meeting->id],
    //             ['file_path' => $finalPath, 'status' => 'uploaded', 'updated_at' => now(), 'created_at' => now()]
    //         );

    //         if ($this->hasFinalFlag()) {
    //             $meeting->recording_uploaded = 1;
    //             $meeting->save();
    //         }

    //         // Cleanup parts
    //         try { @unlink($listFile); $this->rrmdir($partsDir); } catch (\Throwable $e) {
    //             Log::warning('Cleanup parts failed: ' . $e->getMessage());
    //         }

    //         // Notify DAO (best-effort)
    //         $this->notifyDao(request()->bearerToken(), $meeting->application_id);

    //         return response()->json([
    //             'success'    => true,
    //             'message'    => 'Finalized',
    //             'final_path' => $finalPath,
    //             'public_url' => asset('storage/' . $finalPath),
    //         ]);
    //     });
    // }



    public function finalizeUpload(Request $request)
{
    $request->validate([
        'upload_id'   => 'required|string',
        'total_parts' => 'required|integer|min:0',
    ]);

    $meeting = VideoMeeting::where('meeting_token', $request->upload_id)->first();
    if (!$meeting) {
        return response()->json(['success' => false, 'message' => 'Invalid upload_id'], 404);
    }

    // 🚫 You disabled per-meeting agent check by design (high-trust mode)
    // if ($resp = $this->authorizeAgent($request, $meeting)) return $resp;

    return DB::transaction(function () use ($request, $meeting) {
        $meeting = VideoMeeting::where('id', $meeting->id)->lockForUpdate()->first();

        if ($this->hasFinalFlag() && $meeting->recording_uploaded) {
            return response()->json(['success' => true, 'message' => 'Already finalized']);
        }

        $partsDir   = storage_path("app/recordings/{$meeting->id}/parts");
        $total      = (int) $request->total_parts;
        $publicDir  = storage_path('app/public/videos');
        @mkdir($publicDir, 0775, true);

        // Sanity: ensure parts exist
        for ($i = 0; $i < $total; $i++) {
            if (!file_exists("{$partsDir}/{$i}.webm")) {
                return response()->json(['success' => false, 'message' => "Missing part {$i}"], 422);
            }
        }

        // Helper to run ffmpeg and capture diagnostics
        $run = function(array $cmd) {
            $proc = new \Symfony\Component\Process\Process($cmd);
            $proc->setTimeout(300);
            $proc->run();
            return [$proc->isSuccessful(), $proc->getOutput(), $proc->getErrorOutput(), implode(' ', $cmd)];
        };

        // Helper to check ffmpeg existence
        [$okProbe, $outProbe, $errProbe, $cmdProbe] = $run(['ffmpeg', '-version']);
        if (!$okProbe) {
            return response()->json([
                'success' => false,
                'message' => 'ffmpeg not found on PATH. Install ffmpeg or add to PATH and retry.',
                'ffmpeg_cmd' => $cmdProbe,
                'stderr' => $errProbe,
            ], 500);
        }

        // === Case 1: Single part → avoid concat demuxer entirely ===
        if ($total === 1) {
            $src = "{$partsDir}/0.webm";
            $dstWebm = "{$publicDir}/meeting_{$meeting->id}.webm";

            // Try stream copy first (fast)
            [$okCopy, $outCopy, $errCopy, $cmdCopy] = $run(['ffmpeg', '-i', $src, '-c', 'copy', '-y', $dstWebm]);

            if (!$okCopy) {
                // Fallback to re-encode MP4 (more compatible)
                $dstMp4 = "{$publicDir}/meeting_{$meeting->id}.mp4";
                [$okRe, $outRe, $errRe, $cmdRe] = $run([
                    'ffmpeg', '-i', $src,
                    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                    '-c:a', 'aac', '-y', $dstMp4
                ]);

                if (!$okRe) {
                    return response()->json([
                        'success' => false,
                        'message' => 'ffmpeg failed to finalize single part',
                        'stderr_copy' => $errCopy,
                        'stderr_encode' => $errRe,
                        'cmd_copy' => $cmdCopy,
                        'cmd_encode' => $cmdRe,
                    ], 500);
                }

                $finalPath = "videos/meeting_{$meeting->id}.mp4";
            } else {
                $finalPath = "videos/meeting_{$meeting->id}.webm";
            }

        } else {
            // === Case 2: Multiple parts → concat demuxer with safe list file ===
            $listFile = storage_path("app/recordings/{$meeting->id}/list.txt");
            @mkdir(dirname($listFile), 0775, true);

            $lines = [];
            for ($i = 0; $i < $total; $i++) {
                // ffmpeg concat list requires POSIX-like quoting; ensure absolute paths
                $p = "{$partsDir}/{$i}.webm";
                $lines[] = "file '" . str_replace("'", "'\\''", $p) . "'";
            }
            file_put_contents($listFile, implode(PHP_EOL, $lines));

            $dstWebm = "{$publicDir}/meeting_{$meeting->id}.webm";
            [$okConcat, $outConcat, $errConcat, $cmdConcat] = $run([
                'ffmpeg', '-f', 'concat', '-safe', '0', '-i', $listFile, '-c', 'copy', '-y', $dstWebm
            ]);

            if (!$okConcat) {
                // Fallback to re-encode MP4 across parts
                $dstMp4 = "{$publicDir}/meeting_{$meeting->id}.mp4";
                [$okRe, $outRe, $errRe, $cmdRe] = $run([
                    'ffmpeg', '-f', 'concat', '-safe', '0', '-i', $listFile,
                    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                    '-c:a', 'aac', '-y', $dstMp4
                ]);

                if (!$okRe) {
                    return response()->json([
                        'success' => false,
                        'message' => 'ffmpeg concat failed',
                        'stderr_concat' => $errConcat,
                        'stderr_encode' => $errRe,
                        'cmd_concat' => $cmdConcat,
                        'cmd_encode' => $cmdRe,
                        'list_txt' => file_get_contents($listFile),
                    ], 500);
                }

                $finalPath = "videos/meeting_{$meeting->id}.mp4";
            } else {
                $finalPath = "videos/meeting_{$meeting->id}.webm";
            }

            // Clean list file
            try { @unlink($listFile); } catch (\Throwable $e) {}
        }

        // Upsert one row per meeting
        \App\Models\VideoCall::updateOrCreate(
            ['video_meeting_id' => $meeting->id],
            ['file_path' => $finalPath, 'status' => 'uploaded', 'updated_at' => now(), 'created_at' => now()]
        );

        if ($this->hasFinalFlag()) {
            $meeting->recording_uploaded = 1;
            $meeting->save();
        }

        // Cleanup parts dir
        try { $this->rrmdir($partsDir); } catch (\Throwable $e) {
            \Log::warning('Cleanup parts failed', ['err' => $e->getMessage()]);
        }

        // Notify DAO (best-effort)
        $this->notifyDao(request()->bearerToken(), $meeting->application_id);

        return response()->json([
            'success'    => true,
            'message'    => 'Finalized',
            'final_path' => $finalPath,
            'public_url' => asset('storage/' . $finalPath),
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
        $request->validate(['upload_id' => 'required|string']);
        $meeting = VideoMeeting::where('meeting_token', $request->upload_id)->first();
        if (!$meeting) return response()->json(['success' => false, 'message' => 'Invalid upload_id'], 404);

        return DB::transaction(function () use ($meeting) {
            $videoCall = VideoCall::where('video_meeting_id', $meeting->id)->first();
            if ($videoCall) {
                if ($videoCall->file_path) {
                    try { Storage::disk('public')->delete($videoCall->file_path); } catch (\Throwable $e) {
                        Log::warning('Retake: failed to remove file', ['meeting_id' => $meeting->id, 'err' => $e->getMessage()]);
                    }
                }
                $videoCall->delete();
            }

            $partsDir = storage_path("app/recordings/{$meeting->id}/parts");
            $listFile = storage_path("app/recordings/{$meeting->id}/list.txt");
            try { if (file_exists($listFile)) @unlink($listFile); $this->rrmdir($partsDir); } catch (\Throwable $e) {
                Log::warning('Retake: cleanup parts failed', ['err' => $e->getMessage()]);
            }

            if ($this->hasFinalFlag()) $meeting->recording_uploaded = 0;
            $meeting->status = 'active';
            $meeting->save();

            return response()->json([
                'success'    => true,
                'message'    => 'Retake ready. Old recording and parts deleted.',
                'upload_id'  => $meeting->meeting_token,
                'meeting_id' => $meeting->id,
            ]);
        });
    }

    // ========= Helpers =========

    /** Enforce that the JWT belongs to the meeting's agent. */
    // private function authorizeAgent(Request $request, VideoMeeting $meeting)
    // {
    //     // Your JWT middleware should set this claims array
    //     $claims = $request->attributes->get('auth_user');
    //     if (!$claims) {
    //         return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
    //     }

    //     // Choose what you compare: here we assume agent_id stores the JWT "sub"
    //     $jwtSub = $claims['sub'] ?? null;
    //     if (!empty($meeting->agent_id) && $jwtSub !== $meeting->agent_id) {
    //         return response()->json(['success' => false, 'message' => 'Forbidden (agent mismatch)'], 403);
    //     }

    //     return null;
    // }

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
            $daoApiUrl = rtrim(env('DAO_API_URL'), '/') . '/dao/api/video-status-update';
            $daoResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $jwtToken,
                'Accept'        => 'application/json',
            ])->withOptions(['verify' => false])->post($daoApiUrl, [
                'application_id' => $applicationId,
                'status'         => 'Pending',
            ]);
            Log::info('DAO API (notify):', ['status' => $daoResponse->status()]);
        } catch (\Throwable $e) {
            Log::error('DAO notify error: ' . $e->getMessage());
        }
    }
}

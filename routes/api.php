<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\VideoController;

Route::middleware('jwt.verify')->group(function () {
    // Existing
    Route::post('/create-meeting', [MeetingController::class, 'create']);
    Route::post('/upload-video', [VideoController::class, 'upload']); // legacy single-shot
    Route::post('/fetch-video-details', [VideoController::class, 'fetchVideoDetailsByApplicationOrKyc']);

    // NEW: chunked upload flow (for live call and self-KYC)
    Route::post('/upload-chunk',    [VideoController::class, 'uploadChunk']);
    Route::post('/finalize-upload', [VideoController::class, 'finalizeUpload']);

    // NEW: self-KYC session + retake (no meeting_token initially)
    Route::post('/self-kyc/start',  [VideoController::class, 'startSelfKyc']);
    Route::post('/self-kyc/retake', [VideoController::class, 'retakeSelfKyc']);
});

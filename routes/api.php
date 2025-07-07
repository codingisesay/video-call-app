<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\VideoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/



Route::post('/create-meeting', [MeetingController::class, 'create']);
Route::post('/upload-video', [VideoController::class, 'upload']);
Route::get('/video-details/{meetingToken}', [VideoController::class, 'fetchVideoDetails']);
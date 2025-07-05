<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_calls', function (Blueprint $table) {
            $table->id();

            $table->string('agent_id')
                ->nullable();

            $table->string('client_id')->nullable();
                

            $table->string('call_token')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->enum('status', ['recorded', 'uploaded', 'failed'])
                  ->default('recorded');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_calls');
    }
};

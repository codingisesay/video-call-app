<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('video_meetings', function (Blueprint $table) {
    $table->id();
    $table->string('meeting_token')->unique();
    $table->string('agent_id')->nullable();
    $table->string('application_id')->nullable();
    $table->string('customer_name')->nullable();
    $table->string('customer_email')->nullable();
    $table->dateTime('expires_at');
    $table->enum('status', ['active', 'expired', 'completed'])->default('active');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_meetings');
    }
};

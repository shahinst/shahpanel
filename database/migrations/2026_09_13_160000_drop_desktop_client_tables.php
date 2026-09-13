<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('desktop_remote_commands');
        Schema::dropIfExists('desktop_app_releases');
        Schema::dropIfExists('desktop_client_logs');
        Schema::dropIfExists('desktop_client_sessions');
        Schema::dropIfExists('desktop_client_devices');
        Schema::dropIfExists('staff_security_tokens');
    }

    public function down(): void
    {
        // Intentionally empty — desktop client feature was removed.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_state', function (Blueprint $table): void {
            // When the licence server last asked this installation to return.
            // It has no inbound address, so being asked is the only way it
            // learns to report more often.
            $table->timestamp('next_check_in_at')->nullable()->after('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('license_state', function (Blueprint $table): void {
            $table->dropColumn('next_check_in_at');
        });
    }
};

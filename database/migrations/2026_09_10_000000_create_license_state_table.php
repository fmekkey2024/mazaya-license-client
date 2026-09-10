<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_state', function (Blueprint $table): void {
            $table->id();
            $table->string('install_id', 64)->nullable();
            $table->text('token')->nullable();
            $table->text('secret')->nullable();

            // Monotonic. A token carrying anything lower is a replay.
            $table->unsignedBigInteger('seq')->default(0);

            // Highest server timestamp ever seen — the clock-rollback baseline.
            $table->unsignedBigInteger('max_seen_at')->default(0);

            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->text('last_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_state');
    }
};

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
            // Keyed digest of the licensing configuration this install was
            // activated against. A mismatch means .env has been edited.
            $table->string('seal', 64)->nullable()->after('secret');
        });
    }

    public function down(): void
    {
        Schema::table('license_state', function (Blueprint $table): void {
            $table->dropColumn('seal');
        });
    }
};

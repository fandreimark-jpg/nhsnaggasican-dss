<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The missing step between GradingEngine's raw weighted percentage
 * ("computed_grade") and the number DepEd actually expects on a report
 * card — see App\Services\TransmutationService. 'scheme' identifies
 * which published transmutation table a row belongs to (multiple
 * schemes can coexist — see TransmutationRangesSeeder's TODO for the
 * one deliberately NOT seeded yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmutation_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('scheme');
            $table->decimal('min_initial', 5, 2);
            $table->decimal('max_initial', 5, 2);
            $table->unsignedTinyInteger('transmuted');
            $table->timestamps();

            $table->index(['scheme', 'min_initial', 'max_initial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmutation_ranges');
    }
};

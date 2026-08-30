<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('school_year');           // e.g. '2026-2027'
            $table->unsignedTinyInteger('term');      // 1, 2, or 3
            $table->boolean('is_open')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['school_year', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
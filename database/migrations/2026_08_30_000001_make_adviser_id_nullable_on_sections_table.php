<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SectionController already lets a section be created/updated with no
 * adviser assigned ("Adviser assignment is optional"), but the original
 * sections migration never made adviser_id nullable — every insert with
 * a null adviser_id was failing the NOT NULL constraint at the database
 * level. This widens the column without touching the existing foreign
 * key (restrict-on-delete stays: an adviser who IS assigned still can't
 * be deleted out from under a section).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('adviser_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('adviser_id')->nullable(false)->change();
        });
    }
};

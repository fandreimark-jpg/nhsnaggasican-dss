<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ECR alignment" work order, PART 5a — when an upload was read through the
 * DepEd Strengthened SHS E-Class Record profile (see EcrProfileDetector),
 * the version tag actually found at INPUT DATA!T64 (e.g. "2026_v1.0") is
 * recorded here. Null for every upload read through the existing flat
 * lrn/last_name/first_name/item… path — that path is untouched by this
 * column's existence. When DepEd ships a future version (2027_v1.0), this
 * is how a past upload can be told apart from one read under the new
 * profile without guessing from its timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_uploads', function (Blueprint $table) {
            $table->string('ecr_profile_version')->nullable()->after('column_mapping');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_uploads', function (Blueprint $table) {
            $table->dropColumn('ecr_profile_version');
        });
    }
};

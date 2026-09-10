<?php

use App\Models\DepedSubjectCatalog;
use App\Models\Subject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ECR alignment" work order, PART 2b — links an existing Subject to its
 * catalog row when one genuinely matches, so GradingEngine can prefer the
 * catalog's per-subject weights over the subject_group_weights fallback.
 * Nullable: a Grade 12 subject under DO 8 has no SSHS catalog row and never
 * will, and NOT every existing Subject name is actually in the Strengthened
 * SHS curriculum -- see the backfill below.
 *
 * Backfill is EXACT, case-insensitive course_title match only. No fuzzy
 * matching -- a wrong link mis-weights every grade in that subject. Zero
 * matches leaves catalog_id null (falls through to subject_group_weights
 * exactly as before this migration -- no behaviour change for that subject).
 * More than one match also leaves it null and is reported, never guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('catalog_id')
                ->nullable()
                ->after('subject_group')
                ->constrained('deped_subject_catalog')
                ->onDelete('set null');
        });

        $matched = [];
        $unmatched = [];
        $ambiguous = [];

        foreach (Subject::all() as $subject) {
            $candidates = DepedSubjectCatalog::where('scheme', 'do015_2026')
                ->whereRaw('LOWER(course_title) = ?', [strtolower(trim($subject->name))])
                ->get();

            if ($candidates->count() === 1) {
                $subject->update(['catalog_id' => $candidates->first()->id]);
                $matched[] = $subject->name;
            } elseif ($candidates->count() > 1) {
                $ambiguous[] = $subject->name;
            } else {
                $unmatched[] = $subject->name;
            }
        }

        echo "\n  deped_subject_catalog backfill:\n";
        echo '  matched (' . count($matched) . '): ' . (empty($matched) ? '(none)' : implode(', ', $matched)) . "\n";
        echo '  unmatched (' . count($unmatched) . '): ' . (empty($unmatched) ? '(none)' : implode(', ', $unmatched)) . "\n";
        if (!empty($ambiguous)) {
            echo '  AMBIGUOUS, left null (' . count($ambiguous) . '): ' . implode(', ', $ambiguous) . "\n";
        }
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropForeign(['catalog_id']);
            $table->dropColumn('catalog_id');
        });
    }
};

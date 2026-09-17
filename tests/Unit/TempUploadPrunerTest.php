<?php

namespace Tests\Unit;

use App\Services\TempUploadPruner;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The "local" disk is faked for every test by tests/TestCase.php, so
 * these never touch the real storage/app/private upload directories.
 */
class TempUploadPrunerTest extends TestCase
{
    public function test_only_files_older_than_the_cutoff_are_removed(): void
    {
        $disk = Storage::disk('local');
        $disk->put('temp_assessment_uploads/old.xlsx', 'old');
        $disk->put('temp_assessment_uploads/fresh.xlsx', 'fresh');
        // Push one file's mtime two days into the past.
        touch($disk->path('temp_assessment_uploads/old.xlsx'), time() - 2 * 24 * 3600);

        $removed = TempUploadPruner::prune('temp_assessment_uploads');

        $this->assertSame(1, $removed);
        $disk->assertMissing('temp_assessment_uploads/old.xlsx');
        $disk->assertExists('temp_assessment_uploads/fresh.xlsx');
    }

    public function test_a_missing_directory_is_a_no_op(): void
    {
        $this->assertSame(0, TempUploadPruner::prune('temp_directory_that_does_not_exist'));
    }

    public function test_a_shorter_cutoff_can_be_passed_and_other_directories_are_untouched(): void
    {
        $disk = Storage::disk('local');
        $disk->put('temp_ecr_learner_imports/a.xlsx', 'a');
        $disk->put('other_dir/keep.xlsx', 'keep');
        touch($disk->path('temp_ecr_learner_imports/a.xlsx'), time() - 3600);
        touch($disk->path('other_dir/keep.xlsx'), time() - 3600);

        $this->assertSame(1, TempUploadPruner::prune('temp_ecr_learner_imports', 30));
        $disk->assertMissing('temp_ecr_learner_imports/a.xlsx');
        $disk->assertExists('other_dir/keep.xlsx');
    }
}

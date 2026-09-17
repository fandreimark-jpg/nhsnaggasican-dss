<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Removes abandoned temporary uploads.
 *
 * Both multi-step upload flows — the Adviser assessment upload
 * (detect → verify → preview → import) and the Admin "Import Learners
 * from ECR" preview → confirm — park the uploaded workbook under
 * storage/app/private/<dir>/<uuid>.<ext> between steps and delete it only
 * when the final step succeeds. A flow the user abandons (closes the tab
 * on the Verify screen, hits a validation error, never confirms) left
 * its file behind forever: the pre-demo audit (2026-09-17) found 830
 * such files (~22 MB) that nothing would ever remove.
 *
 * Called at the START of each new upload rather than from a scheduler,
 * so it needs no cron/queue infrastructure to work — the directory is
 * tidied whenever it is next used. Only files older than $maxAgeMinutes
 * are touched, so an upload another adviser is in the middle of
 * verifying right now is never deleted out from under them; a session
 * that lasts longer than the age limit simply gets the same "start the
 * upload again" message the existing missing-file guard already shows.
 */
class TempUploadPruner
{
    /** Longer than any realistic verify/preview session, shorter than "forever". */
    public const DEFAULT_MAX_AGE_MINUTES = 24 * 60;

    /**
     * @return int number of files removed
     */
    public static function prune(string $directory, int $maxAgeMinutes = self::DEFAULT_MAX_AGE_MINUTES, string $disk = 'local'): int
    {
        $storage = Storage::disk($disk);
        if (!$storage->exists($directory)) {
            return 0;
        }

        $cutoff = now()->subMinutes($maxAgeMinutes)->getTimestamp();
        $removed = 0;

        foreach ($storage->files($directory) as $path) {
            if ($storage->lastModified($path) < $cutoff && $storage->delete($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}

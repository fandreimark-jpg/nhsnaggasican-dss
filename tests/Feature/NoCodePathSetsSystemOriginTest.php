<?php

namespace Tests\Feature;

use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * "Master pass" PART 1.2e / hard constraint — "Do not create any code
 * path that sets origin => 'system'." The value exists purely so the
 * interface can tell the truth if such a source is ever added; nothing
 * in this codebase may assign it today. A static source scan, not a
 * runtime assertion, since the point is that the CODE never expresses
 * this assignment anywhere, not just that today's tested paths don't.
 */
class NoCodePathSetsSystemOriginTest extends TestCase
{
    public function test_no_php_or_blade_file_assigns_origin_to_system(): void
    {
        $finder = new Finder();
        $finder->files()
            ->in([app_path(), resource_path('views'), database_path()])
            ->name(['*.php', '*.blade.php']);

        // Matches 'origin' => 'system' / "origin" => "system" with any
        // whitespace, and the same shape with a single-quoted key only
        // (Blade never uses double-quoted array keys for this column) —
        // deliberately loose so a differently-formatted assignment still
        // gets caught rather than slipping past an overly narrow regex.
        $pattern = '/[\'"]origin[\'"]\s*=>\s*[\'"]system[\'"]/';

        $offenders = [];
        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $contents = $file->getContents();
            if (preg_match($pattern, $contents)) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertEmpty($offenders, 'Found origin => system assignment in: ' . implode(', ', $offenders));
    }
}

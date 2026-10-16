<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The demo box runs MariaDB 10.4 with explicit_defaults_for_timestamp = OFF.
 * There a NOT NULL TIMESTAMP never stays a plain column: a table's first one
 * silently gains DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, and
 * every later one gains DEFAULT '0000-00-00 00:00:00', which strict mode's
 * NO_ZERO_DATE rejects (error 1067 — how 2026_09_27_000001 first failed).
 * The test suite runs on SQLite, which has neither behaviour, so no feature
 * test can catch this. This static check can.
 *
 * A required point in time is a ->dateTime() column; ->timestamp() is for a
 * nullable column or one with an explicit ->useCurrent().
 */
class MigrationTimestampPortabilityTest extends TestCase
{
    /** Already run everywhere; changing a migration that has run is forbidden. */
    private const LEGACY = [
        '2026_06_16_012310_create_risk_results_table.php', // generated_at — see CLAUDE.md
    ];

    public function test_no_migration_declares_a_not_null_timestamp_column(): void
    {
        $offenders = [];

        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $path) {
            if (in_array(basename($path), self::LEGACY, true)) {
                continue;
            }

            $source = file_get_contents($path);
            // Each ->timestamp('name') call with the rest of its statement.
            preg_match_all('/->timestamp\(\s*\'([a-z_]+)\'[^;]*;/', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as [$statement, $column]) {
                if (!str_contains($statement, '->nullable(') && !str_contains($statement, '->useCurrent(')) {
                    $offenders[] = basename($path) . ': ' . $column;
                }
            }
        }

        $this->assertSame([], $offenders, 'Use ->dateTime() for a required point in time; a NOT NULL TIMESTAMP gets an implicit default on MariaDB 10.4.');
    }
}

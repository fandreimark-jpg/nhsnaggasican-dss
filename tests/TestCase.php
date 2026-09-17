<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every multi-step upload test (assessment detect/verify/preview,
        // Import Learners from ECR) writes its workbook to the "local"
        // disk. Without this, each suite run left ~100 real files under
        // storage/app/private/temp_* that nothing removed — 830 of them
        // (~22 MB) by the 2026-09-17 pre-demo audit. Faking the disk
        // keeps test uploads in storage/framework/testing (wiped per
        // test) and away from the real private upload directories.
        Storage::fake('local');
    }
}

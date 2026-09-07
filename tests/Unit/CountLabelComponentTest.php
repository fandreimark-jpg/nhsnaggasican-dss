<?php

namespace Tests\Unit;

use Tests\TestCase;

class CountLabelComponentTest extends TestCase
{
    private function render(array $data): string
    {
        return trim(view('components.count-label', $data)->render());
    }

    public function test_singular_count_has_no_trailing_s(): void
    {
        $this->assertSame('1 student', $this->render(['count' => 1, 'noun' => 'student']));
    }

    public function test_plural_count_has_trailing_s(): void
    {
        $this->assertSame('2 students', $this->render(['count' => 2, 'noun' => 'student']));
        $this->assertSame('0 students', $this->render(['count' => 0, 'noun' => 'student']));
    }

    public function test_total_flag_inserts_the_word_total_before_the_noun(): void
    {
        $this->assertSame('3 total tracks', $this->render(['count' => 3, 'noun' => 'track', 'total' => true]));
        $this->assertSame('1 total track', $this->render(['count' => 1, 'noun' => 'track', 'total' => true]));
    }
}

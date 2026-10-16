<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The short, shared, plain-language explanation on both dashboards of the
 * two signals this system shows — In-Term Status (a description of recorded
 * evidence) and an Academic Concern (opened only by an approved rule). The
 * classifier Risk Level it used to explain no longer exists.
 */
class InTermStatusHelpTest extends TestCase
{
    use RefreshDatabase;

    private function assertHelpBlock($response): void
    {
        $response->assertOk()
            ->assertSee('One subject, one term', false)
            ->assertSee('Academic Concern</p>', false)
            ->assertSee('predicts nothing, and opens no concern', false)
            ->assertSee('recorded as Missing', false)
            ->assertDontSee('Risk Level</p>', false)
            ->assertDontSee('classifier', false);
    }

    public function test_principal_dashboard_shows_the_help_block(): void
    {
        $this->assertHelpBlock($this->actingAs(User::factory()->principal()->create())->get('/principal/dashboard'));
    }

    public function test_adviser_dashboard_shows_the_help_block(): void
    {
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->assertHelpBlock($this->actingAs($adviser)->get('/adviser/dashboard'));
    }

    /** In-Term Status's own sentence must never describe it as risk. */
    public function test_help_block_never_uses_risk_to_describe_in_term_status(): void
    {
        $content = $this->actingAs(User::factory()->principal()->create())->get('/principal/dashboard')->getContent();

        $helpStart = strpos($content, 'In-Term Status</p>');
        $helpEnd = strpos($content, 'Academic Concern</p>');
        $this->assertNotFalse($helpStart);
        $this->assertNotFalse($helpEnd);

        $this->assertStringNotContainsStringIgnoringCase('risk', strip_tags(substr($content, $helpStart, $helpEnd - $helpStart)));
    }
}

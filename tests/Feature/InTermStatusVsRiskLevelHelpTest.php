<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 4 of "close the delivery loop": a short, shared, plain-language
 * explanation of In-Term Status vs Risk Level on both dashboards — the
 * question a first-time reader asks before anything else.
 */
class InTermStatusVsRiskLevelHelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_principal_dashboard_shows_the_help_block(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');

        $response->assertOk();
        $response->assertSee('One subject, one term', false);
        $response->assertSee('Every subject', false);
        $response->assertSee('trend across terms', false);
    }

    public function test_adviser_dashboard_shows_the_help_block(): void
    {
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($adviser)->get('/adviser/dashboard');

        $response->assertOk();
        $response->assertSee('One subject, one term', false);
        $response->assertSee('Every subject', false);
        $response->assertSee('trend across terms', false);
    }

    /**
     * The literal ground rule: neither label describes the other measure.
     * "In-Term Status" must never appear next to the word "Risk" inside
     * its own explanatory sentence, and vice versa.
     */
    public function test_help_block_never_uses_risk_to_describe_in_term_status(): void
    {
        $principal = User::factory()->principal()->create();

        $response = $this->actingAs($principal)->get('/principal/dashboard');
        $content = $response->getContent();

        $helpStart = strpos($content, 'In-Term Status</p>');
        $helpEnd = strpos($content, 'Risk Level</p>');

        $this->assertNotFalse($helpStart);
        $this->assertNotFalse($helpEnd);

        // The sentence describing In-Term Status (between its own heading
        // and the Risk Level heading that follows it) must not contain
        // the word "Risk".
        $inTermStatusSentence = substr($content, $helpStart, $helpEnd - $helpStart);
        $this->assertStringNotContainsStringIgnoringCase('risk', strip_tags($inTermStatusSentence));
    }
}

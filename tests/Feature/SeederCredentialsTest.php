<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Final release pass (2026-09-27): the seeder used to give every account it
 * created the committed default password. Now no password is committed; a
 * deployed (production) database can never receive a known one.
 */
class SeederCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private \Symfony\Component\Console\Output\BufferedOutput $output;

    private function seedAs(string $env, ?string $password): void
    {
        $this->app['env'] = $env;
        config(['dss.seed_account_password' => $password]);
        // Invoked directly: `db:seed` itself asks an interactive production
        // confirmation, which is the command's guard, not the seeder's.
        $command = new class extends \Illuminate\Console\Command {
            protected $name = 'test:seed-credentials';
        };
        $command->setLaravel($this->app);
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $this->output = new \Symfony\Component\Console\Output\BufferedOutput()
        ));

        $seeder = $this->app->make(DatabaseSeeder::class);
        $seeder->setContainer($this->app)->setCommand($command);
        $seeder->__invoke();
    }

    public function test_no_password_literal_is_committed_in_the_seeder(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
        $this->assertStringNotContainsString("PASSWORD = '", $source);
        $this->assertDoesNotMatchRegularExpression("/Hash::make\\('[^']+'\\)/", $source);
    }

    public function test_production_without_a_configured_password_refuses_and_writes_no_account(): void
    {
        try {
            $this->seedAs('production', null);
            $this->fail('The seeder must refuse in production without SEED_ACCOUNT_PASSWORD.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SEED_ACCOUNT_PASSWORD', $e->getMessage());
        }
        $this->assertSame(0, User::count());
    }

    public function test_a_short_or_default_configured_password_is_refused(): void
    {
        foreach (['short-pw', 'password', 'PASSWORD'] as $weak) {
            try {
                $this->seedAs('production', $weak);
                $this->fail("'$weak' must be refused.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('at least', $e->getMessage());
            }
        }
        $this->assertSame(0, User::count());
    }

    public function test_production_with_a_configured_password_uses_it_and_never_the_old_default(): void
    {
        $this->seedAs('production', 'Synthetic-Only-Pass-2026');

        $this->assertSame(3, User::count());
        foreach (User::all() as $user) {
            $this->assertTrue(Hash::check('Synthetic-Only-Pass-2026', $user->password));
            $this->assertFalse(Hash::check('password', $user->password));
        }
    }

    public function test_local_without_a_configured_password_gets_a_random_one_never_the_default(): void
    {
        $this->seedAs('local', null);

        $this->assertSame(3, User::count());
        preg_match('/never stored in plain text\): (\S+)/', $this->output->fetch(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'the generated password is shown once to the operator');
        foreach (User::all() as $user) {
            $this->assertFalse(Hash::check('password', $user->password));
            $this->assertTrue(Hash::check($m[1], $user->password), 'the shown password is the one that works');
        }
    }

    public function test_existing_accounts_are_never_touched_so_reseeding_needs_no_password(): void
    {
        $this->seedAs('local', 'Synthetic-Only-Pass-2026');
        $hashes = User::orderBy('id')->pluck('password')->all();

        // A later production re-seed with no password configured: every
        // account already exists, so nothing is created, nothing refused,
        // and no password changes.
        $this->seedAs('production', null);

        $this->assertSame($hashes, User::orderBy('id')->pluck('password')->all());
    }
}

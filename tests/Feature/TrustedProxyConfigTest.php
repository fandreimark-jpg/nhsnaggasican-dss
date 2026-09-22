<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * config/trustedproxy.php decides WHICH proxies are trusted; bootstrap/app.php
 * decides WHICH headers (never X-Forwarded-Host — see DeploymentProxyTest).
 * The config file is re-evaluated here with a controlled environment so the
 * three rules are pinned: local trusts nothing, an explicit value wins, and a
 * Railway service (platform variables present, TRUSTED_PROXIES unset) trusts
 * its ingress so HTTPS is detected and asset()/route() emit https:// URLs.
 */
class TrustedProxyConfigTest extends TestCase
{
    private const KEYS = ['TRUSTED_PROXIES', 'RAILWAY_ENVIRONMENT_NAME', 'RAILWAY_PUBLIC_DOMAIN'];

    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $server]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }

        parent::tearDown();
    }

    private function proxies(array $vars): array|string
    {
        foreach ($vars as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return (require base_path('config/trustedproxy.php'))['proxies'];
    }

    public function test_local_default_trusts_no_proxy(): void
    {
        $this->assertSame([], $this->proxies([]));
        $this->assertSame([], $this->proxies(['TRUSTED_PROXIES' => '']));
    }

    public function test_explicit_wildcard_trusts_the_calling_ip(): void
    {
        $this->assertSame('*', $this->proxies(['TRUSTED_PROXIES' => '*']));
    }

    public function test_explicit_list_is_split_and_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2'],
            $this->proxies(['TRUSTED_PROXIES' => '10.0.0.1, 10.0.0.2,'])
        );
    }

    public function test_railway_platform_variables_imply_a_single_ingress(): void
    {
        $this->assertSame('*', $this->proxies(['RAILWAY_ENVIRONMENT_NAME' => 'production']));
        $this->assertSame('*', $this->proxies(['RAILWAY_PUBLIC_DOMAIN' => 'dss.up.railway.app']));
    }

    public function test_an_empty_railway_variable_does_not_count_as_railway(): void
    {
        $this->assertSame([], $this->proxies(['RAILWAY_ENVIRONMENT_NAME' => '', 'RAILWAY_PUBLIC_DOMAIN' => '']));
    }

    public function test_an_explicit_list_wins_over_the_railway_inference(): void
    {
        $this->assertSame(
            ['10.0.0.1'],
            $this->proxies(['RAILWAY_ENVIRONMENT_NAME' => 'production', 'TRUSTED_PROXIES' => '10.0.0.1'])
        );
    }
}

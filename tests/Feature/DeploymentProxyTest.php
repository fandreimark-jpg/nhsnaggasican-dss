<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class DeploymentProxyTest extends TestCase
{
    public function test_container_ingress_preserves_https_without_trusting_forwarded_host(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $request = Request::create('http://demo.example/login', server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
        ]);

        (new TrustProxies)->handle($request, function (Request $request) {
            $this->assertTrue($request->isSecure());
            $this->assertSame('https://demo.example', $request->getSchemeAndHttpHost());
            return response('ok');
        });
    }

    public function test_local_development_does_not_trust_forwarded_https(): void
    {
        config(['trustedproxy.proxies' => []]);
        $request = Request::create('http://localhost/login', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        (new TrustProxies)->handle($request, function (Request $request) {
            $this->assertFalse($request->isSecure());
            return response('ok');
        });
    }
}

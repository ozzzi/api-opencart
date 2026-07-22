<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\TokenAuth;
use Tests\TestCase;

final class RestrictIpToHostTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;

        $this->withoutMiddleware(TokenAuth::class);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;

        parent::tearDown();
    }

    public function test_in_local_environment(): void
    {
        $response = $this->get('/api');

        $response->assertStatus(200);
    }

    public function test_block_not_allowed_ip(): void
    {
        config(['app.env' => 'production']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->get('/api');
        $response->assertStatus(401);
    }

    public function test_allowed_ip(): void
    {
        $allowedIp = '198.51.100.1';

        config(['app.env' => 'production']);
        config(['api.ip_address' => $allowedIp]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $allowedIp])
            ->get('/api');

        $response->assertOk();
    }

    public function test_allows_container_from_docker_subnet(): void
    {
        config(['app.env' => 'production']);
        config(['api.allowed_subnets' => ['172.16.0.0/12']]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.7'])
            ->get('/api');

        $response->assertOk();
    }

    public function test_allows_container_after_its_address_changed(): void
    {
        config(['app.env' => 'production']);
        config(['api.allowed_subnets' => ['172.16.0.0/12']]);

        foreach (['172.18.0.7', '172.20.0.3', '172.31.255.254'] as $rebuiltIp) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $rebuiltIp])
                ->get('/api');

            $response->assertOk();
        }
    }

    public function test_blocks_public_ip_outside_allowed_subnets(): void
    {
        config(['app.env' => 'production']);
        config(['api.allowed_subnets' => ['172.16.0.0/12']]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/api');

        $response->assertStatus(401);
    }

    public function test_forwarded_header_cannot_spoof_a_subnet_address(): void
    {
        config(['app.env' => 'production']);
        config(['api.allowed_subnets' => ['172.16.0.0/12']]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '172.18.0.7';

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/api');

        $response->assertStatus(401);
    }
}

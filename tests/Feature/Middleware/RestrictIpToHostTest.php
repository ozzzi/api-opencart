<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\TokenAuth;
use Tests\TestCase;

final class RestrictIpToHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(TokenAuth::class);
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
}

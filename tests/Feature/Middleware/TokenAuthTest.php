<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Tests\TestCase;

final class TokenAuthTest extends TestCase
{
    public function test_no_token(): void
    {
        $response = $this->get('/api');

        $response->assertStatus(401);
    }

    public function test_with_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . config('api.token'))
            ->get('/api');

        $response->assertStatus(200)
            ->assertJson(['success' => 'true']);
    }
}

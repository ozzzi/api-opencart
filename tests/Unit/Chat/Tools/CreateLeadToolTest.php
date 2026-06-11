<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\LeadServiceInterface;
use App\Services\Chat\Tools\CreateLeadTool;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class CreateLeadToolTest extends TestCase
{
    private MockInterface $leadService;

    private CreateLeadTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadService = Mockery::mock(LeadServiceInterface::class);

        /** @var LeadServiceInterface $service */
        $service = $this->leadService;

        $this->tool = new CreateLeadTool($service);
    }

    // ── contract ──────────────────────────────────────────────────────────────

    public function test_name_is_create_lead(): void
    {
        $this->assertSame('create_lead', $this->tool->getName());
    }

    public function test_schema_has_no_required_fields(): void
    {
        $this->assertSame([], $this->tool->getParameterSchema()['required']);
    }

    public function test_schema_defines_all_fields(): void
    {
        $properties = $this->tool->getParameterSchema()['properties'];

        $this->assertArrayHasKey('name', $properties);
        $this->assertArrayHasKey('phone', $properties);
        $this->assertArrayHasKey('email', $properties);
        $this->assertArrayHasKey('message', $properties);
        $this->assertArrayHasKey('product_ids', $properties);
    }

    // ── execute: missing contact ──────────────────────────────────────────────

    public function test_execute_fails_when_no_contact_provided(): void
    {
        $result = json_decode(
            $this->tool->execute(['name' => 'Иван', 'message' => 'Помогите'], $this->makeSession()),
            true,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('phone or email', $result['error']);
    }

    public function test_execute_fails_when_phone_is_empty_string(): void
    {
        $result = json_decode(
            $this->tool->execute(['phone' => '  ', 'name' => 'Иван'], $this->makeSession()),
            true,
        );

        $this->assertFalse($result['success']);
    }

    public function test_execute_fails_when_email_is_empty_string(): void
    {
        $result = json_decode(
            $this->tool->execute(['email' => '', 'name' => 'Иван'], $this->makeSession()),
            true,
        );

        $this->assertFalse($result['success']);
    }

    // ── execute: success with phone ───────────────────────────────────────────

    public function test_execute_succeeds_with_phone_only(): void
    {
        $lead = Lead::factory()->make(['id' => 7, 'session_id' => null]);

        $this->leadService
            ->expects('create')
            ->andReturn($lead);

        $result = json_decode(
            $this->tool->execute(['phone' => '+380991234567'], $this->makeSession()),
            true,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['lead_id']);
        $this->assertNotEmpty($result['message']);
    }

    // ── execute: success with email ───────────────────────────────────────────

    public function test_execute_succeeds_with_email_only(): void
    {
        $lead = Lead::factory()->make(['id' => 3, 'session_id' => null]);

        $this->leadService
            ->expects('create')
            ->andReturn($lead);

        $result = json_decode(
            $this->tool->execute(['email' => 'user@example.com'], $this->makeSession()),
            true,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['lead_id']);
    }

    // ── execute: data passed to service ──────────────────────────────────────

    public function test_execute_passes_all_fields_to_lead_service(): void
    {
        $lead = Lead::factory()->make(['id' => 1, 'session_id' => null]);
        $session = $this->makeSession();

        $this->leadService
            ->expects('create')
            ->withArgs(function (string $sessionId, array $data) use ($session): bool {
                return $sessionId === $session->id
                    && $data['name'] === 'Олег'
                    && $data['phone'] === '+380991111111'
                    && $data['email'] === 'oleg@example.com'
                    && $data['message'] === 'Хочу заказать ноутбук'
                    && $data['product_ids'] === [42, 55];
            })
            ->andReturn($lead);

        $this->tool->execute([
            'name'        => 'Олег',
            'phone'       => '+380991111111',
            'email'       => 'oleg@example.com',
            'message'     => 'Хочу заказать ноутбук',
            'product_ids' => [42, 55],
        ], $session);
    }

    public function test_execute_trims_whitespace_from_name_and_message(): void
    {
        $lead = Lead::factory()->make(['id' => 1, 'session_id' => null]);

        $this->leadService
            ->expects('create')
            ->withArgs(function (string $sessionId, array $data): bool {
                return $data['name'] === 'Олег'
                    && $data['message'] === 'Вопрос по доставке';
            })
            ->andReturn($lead);

        $this->tool->execute([
            'phone'   => '+380991234567',
            'name'    => '  Олег  ',
            'message' => '  Вопрос по доставке  ',
        ], $this->makeSession());
    }

    public function test_execute_passes_null_for_absent_optional_fields(): void
    {
        $lead = Lead::factory()->make(['id' => 1, 'session_id' => null]);

        $this->leadService
            ->expects('create')
            ->withArgs(function (string $sessionId, array $data): bool {
                return $data['name'] === null
                    && $data['message'] === null
                    && $data['product_ids'] === null;
            })
            ->andReturn($lead);

        $this->tool->execute(['phone' => '+380991234567'], $this->makeSession());
    }

    public function test_execute_casts_product_ids_to_integers(): void
    {
        $lead = Lead::factory()->make(['id' => 1, 'session_id' => null]);

        $this->leadService
            ->expects('create')
            ->withArgs(function (string $sessionId, array $data): bool {
                return $data['product_ids'] === [10, 20];
            })
            ->andReturn($lead);

        $this->tool->execute([
            'phone'       => '+380991234567',
            'product_ids' => ['10', '20'],
        ], $this->makeSession());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make([
            'id'       => (string) Str::uuid(),
            'language' => $lang,
        ]);
    }
}

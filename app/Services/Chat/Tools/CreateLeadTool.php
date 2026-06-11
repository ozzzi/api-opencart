<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\LeadServiceInterface;
use App\Services\Chat\Tools\Contracts\ToolInterface;

final class CreateLeadTool implements ToolInterface
{
    public function __construct(
        private readonly LeadServiceInterface $leadService,
    ) {
    }

    public function getName(): string
    {
        return 'create_lead';
    }

    public function getDescription(): string
    {
        return 'Create a customer lead (contact request) when you cannot help the customer directly '
            .'or when they explicitly ask to speak to a manager. '
            .'Collect name and at least one contact method (phone or email) before calling this tool. '
            .'Returns a confirmation that the request has been received.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => "Customer's name.",
                ],
                'phone' => [
                    'type'        => 'string',
                    'description' => "Customer's phone number.",
                ],
                'email' => [
                    'type'        => 'string',
                    'description' => "Customer's email address.",
                ],
                'message' => [
                    'type'        => 'string',
                    'description' => 'Brief description of the customer question or request.',
                ],
                'product_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'IDs of products the customer was asking about (if any).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        $phone = isset($arguments['phone']) ? mb_trim((string) $arguments['phone']) : null;
        $email = isset($arguments['email']) ? mb_trim((string) $arguments['email']) : null;

        if (($phone === null || $phone === '') && ($email === null || $email === '')) {
            return json_encode([
                'success' => false,
                'error'   => 'At least one contact method (phone or email) is required.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $data = [
            'name'        => isset($arguments['name']) ? mb_trim((string) $arguments['name']) ?: null : null,
            'phone'       => $phone ?: null,
            'email'       => $email ?: null,
            'message'     => isset($arguments['message']) ? mb_trim((string) $arguments['message']) ?: null : null,
            'product_ids' => isset($arguments['product_ids'])
                ? array_map('intval', (array) $arguments['product_ids'])
                : null,
        ];

        $lead = $this->leadService->create($session->id, $data);

        return json_encode([
            'success' => true,
            'lead_id' => $lead->id,
            'message' => 'Your request has been received. A manager will contact you shortly.',
        ], JSON_UNESCAPED_UNICODE);
    }
}

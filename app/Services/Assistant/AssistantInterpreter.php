<?php

namespace App\Services\Assistant;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class AssistantInterpreter
{
    public function __construct(private readonly OpenAiClient $client)
    {
    }

    public function interpret(string $message, array $context = []): array
    {
        $systemPrompt = $this->buildSystemPrompt($context);

        $response = $this->client->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ]);

        $content = $this->client->extractMessage($response);
        $decoded = $this->decodeJson($content);

        return $this->normalize($decoded);
    }

    private function buildSystemPrompt(array $context): string
    {
        $draft = $context['draft'] ?? null;
        $draftJson = $draft ? json_encode($draft, JSON_PRETTY_PRINT) : 'null';

        return <<<PROMPT
You are a structured assistant for a business management platform.
Return JSON only. Do not include markdown or extra text.

Allowed intents: create_quote, create_work, create_invoice, send_quote, accept_quote, convert_quote, mark_invoice_paid, update_work_status, create_customer, create_property, create_category, create_product, create_service, create_team_member, read_notifications, list_quotes, list_works, list_invoices, list_customers, show_quote, show_work, show_invoice, show_customer, create_task, update_task_status, assign_task, update_checklist_item, create_request, convert_request, send_invoice, remind_invoice, schedule_work, assign_work_team, unknown.

You cannot modify the app structure, UI, schema, or settings. You can only create or read workflow data.
If a user asks to change the UI or structure, set intent to "unknown".

If an existing draft is provided, merge new user info into it and return the updated draft in the matching object (quote/work/customer/property/category/product).
Draft JSON:
{$draftJson}

Output JSON schema:
{
  "intent": "create_quote|create_work|create_invoice|send_quote|accept_quote|convert_quote|mark_invoice_paid|update_work_status|create_customer|create_property|create_category|create_product|create_service|create_team_member|read_notifications|unknown",
  "confidence": 0.0,
  "quote": {
    "customer": {
      "name": "",
      "company_name": "",
      "first_name": "",
      "last_name": "",
      "email": "",
      "phone": ""
    },
    "status": "",
    "items": [
      {
        "name": "",
        "quantity": 1,
        "price": null,
        "item_type": "service|product",
        "unit": ""
      }
    ],
    "taxes": [],
    "notes": "",
    "messages": ""
  },
  "work": {
    "job_title": "",
    "instructions": "",
    "start_date": "",
    "end_date": "",
    "start_time": "",
    "end_time": "",
    "status": "",
    "type": "",
    "category": "",
    "customer": {
      "name": "",
      "company_name": "",
      "first_name": "",
      "last_name": "",
      "email": "",
      "phone": ""
    },
    "items": [
      {
        "name": "",
        "quantity": 1,
        "price": null,
        "item_type": "service|product",
        "unit": ""
      }
    ]
  },
  "invoice": {
    "status": "",
    "notes": "",
    "amount": null
  },
  "targets": {
    "quote_id": null,
    "quote_number": "",
    "work_id": null,
    "work_number": "",
    "invoice_id": null,
    "invoice_number": "",
    "task_id": null,
    "request_id": null,
    "checklist_item_id": null
  },
  "customer": {
    "name": "",
    "company_name": "",
    "first_name": "",
    "last_name": "",
    "email": "",
    "phone": ""
  },
  "property": {
    "type": "physical|billing|other",
    "street1": "",
    "street2": "",
    "city": "",
    "state": "",
    "zip": "",
    "country": "",
    "is_default": false,
    "customer": {
      "name": "",
      "company_name": "",
      "first_name": "",
      "last_name": "",
      "email": "",
      "phone": ""
    }
  },
  "category": {
    "name": "",
    "item_type": "service|product"
  },
  "product": {
    "name": "",
    "price": null,
    "item_type": "service|product",
    "category": "",
    "unit": "",
    "description": ""
  },
  "task": {
    "title": "",
    "description": "",
    "status": "",
    "due_date": "",
    "assignee": {
      "name": "",
      "email": ""
    }
  },
  "checklist_item": {
    "title": "",
    "status": ""
  },
  "request": {
    "title": "",
    "service_type": "",
    "description": "",
    "channel": "",
    "urgency": "",
    "contact_name": "",
    "contact_email": "",
    "contact_phone": "",
    "country": "",
    "state": "",
    "city": "",
    "street1": "",
    "street2": "",
    "postal_code": ""
  },
  "team_member": {
    "name": "",
    "email": "",
    "role": "admin|member|seller",
    "permissions": []
  },
  "team_members": [],
  "filters": {
    "search": "",
    "status": "",
    "limit": null
  },
  "actions": []
}

Rules:
- Use null for unknown numeric values.
- Use empty strings for unknown text fields.
- Keep item_type only as "service" or "product".
- For work.status use one of: to_schedule, scheduled, en_route, in_progress, tech_complete, pending_review, validated, auto_validated, dispute, closed, cancelled, completed.
- For action intents, fill targets with any provided quote/work/invoice ids or numbers.
- For team_member.permissions, only use permission ids: quotes.view, quotes.create, quotes.edit, quotes.send, jobs.view, jobs.edit, tasks.view, tasks.create, tasks.edit, tasks.delete, sales.manage, sales.pos.
- If intent is not clear, set intent to "unknown".
PROMPT;
    }

    private function decodeJson(string $payload): array
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            throw new RuntimeException('OpenAI returned an empty response.');
        }

        if (Str::startsWith($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?/i', '', $trimmed);
            $trimmed = preg_replace('/```$/', '', (string) $trimmed);
            $trimmed = trim((string) $trimmed);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return $decoded;
    }

    private function normalize(array $data): array
    {
        $intent = strtolower((string) ($data['intent'] ?? 'unknown'));
        $allowed = [
            'create_quote',
            'create_work',
            'create_invoice',
            'send_quote',
            'accept_quote',
            'convert_quote',
            'mark_invoice_paid',
            'update_work_status',
            'create_customer',
            'create_property',
            'create_category',
            'create_product',
            'create_service',
            'create_team_member',
            'read_notifications',
            'list_quotes',
            'list_works',
            'list_invoices',
            'list_customers',
            'show_quote',
            'show_work',
            'show_invoice',
            'show_customer',
            'create_task',
            'update_task_status',
            'assign_task',
            'update_checklist_item',
            'create_request',
            'convert_request',
            'send_invoice',
            'remind_invoice',
            'schedule_work',
            'assign_work_team',
            'unknown',
        ];
        if (!in_array($intent, $allowed, true)) {
            $intent = 'unknown';
        }

        $confidence = (float) ($data['confidence'] ?? 0.0);
        $confidence = max(0.0, min(1.0, $confidence));

        $quote = $this->normalizeQuote($data['quote'] ?? []);
        $work = $this->normalizeWork($data['work'] ?? []);
        $invoice = $this->normalizeInvoice($data['invoice'] ?? []);
        $targets = $this->normalizeTargets($data['targets'] ?? []);
        $customer = $this->normalizeCustomer($data['customer'] ?? []);
        $property = $this->normalizeProperty($data['property'] ?? []);
        $category = $this->normalizeCategory($data['category'] ?? []);
        $product = $this->normalizeProduct($data['product'] ?? []);
        $teamMember = $this->normalizeTeamMember($data['team_member'] ?? []);
        $task = $this->normalizeTask($data['task'] ?? []);
        $checklistItem = $this->normalizeChecklistItem($data['checklist_item'] ?? []);
        $request = $this->normalizeRequest($data['request'] ?? []);
        $teamMembers = $this->normalizeTeamMembers($data['team_members'] ?? []);
        $filters = $this->normalizeFilters($data['filters'] ?? []);

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'quote' => $quote,
            'work' => $work,
            'invoice' => $invoice,
            'targets' => $targets,
            'customer' => $customer,
            'property' => $property,
            'category' => $category,
            'product' => $product,
            'task' => $task,
            'checklist_item' => $checklistItem,
            'request' => $request,
            'team_member' => $teamMember,
            'team_members' => $teamMembers,
            'filters' => $filters,
            'actions' => array_values(array_filter(Arr::wrap($data['actions'] ?? []))),
        ];
    }

    private function normalizeQuote($quote): array
    {
        $quote = is_array($quote) ? $quote : [];
        $customer = is_array($quote['customer'] ?? null) ? $quote['customer'] : [];

        $normalizedItems = $this->normalizeItems($quote['items'] ?? []);

        $taxes = array_values(array_filter(array_map(
            fn ($value) => $this->cleanString($value),
            Arr::wrap($quote['taxes'] ?? [])
        )));

        return [
            'customer' => [
                'name' => $this->cleanString($customer['name'] ?? null),
                'company_name' => $this->cleanString($customer['company_name'] ?? null),
                'first_name' => $this->cleanString($customer['first_name'] ?? null),
                'last_name' => $this->cleanString($customer['last_name'] ?? null),
                'email' => $this->cleanString($customer['email'] ?? null),
                'phone' => $this->cleanString($customer['phone'] ?? null),
            ],
            'status' => $this->cleanString($quote['status'] ?? null),
            'items' => $normalizedItems,
            'taxes' => $taxes,
            'notes' => $this->cleanString($quote['notes'] ?? null),
            'messages' => $this->cleanString($quote['messages'] ?? null),
        ];
    }

    private function normalizeWork($work): array
    {
        $work = is_array($work) ? $work : [];
        $customer = is_array($work['customer'] ?? null) ? $work['customer'] : [];

        return [
            'job_title' => $this->cleanString($work['job_title'] ?? null),
            'instructions' => $this->cleanString($work['instructions'] ?? null),
            'start_date' => $this->cleanString($work['start_date'] ?? null),
            'end_date' => $this->cleanString($work['end_date'] ?? null),
            'start_time' => $this->cleanString($work['start_time'] ?? null),
            'end_time' => $this->cleanString($work['end_time'] ?? null),
            'status' => $this->cleanString($work['status'] ?? null),
            'type' => $this->cleanString($work['type'] ?? null),
            'category' => $this->cleanString($work['category'] ?? null),
            'customer' => [
                'name' => $this->cleanString($customer['name'] ?? null),
                'company_name' => $this->cleanString($customer['company_name'] ?? null),
                'first_name' => $this->cleanString($customer['first_name'] ?? null),
                'last_name' => $this->cleanString($customer['last_name'] ?? null),
                'email' => $this->cleanString($customer['email'] ?? null),
                'phone' => $this->cleanString($customer['phone'] ?? null),
            ],
            'items' => $this->normalizeItems($work['items'] ?? []),
        ];
    }

    private function normalizeInvoice($invoice): array
    {
        $invoice = is_array($invoice) ? $invoice : [];
        $amount = $invoice['amount'] ?? null;
        $amount = $amount === null ? null : (float) $amount;

        return [
            'status' => $this->cleanString($invoice['status'] ?? null),
            'notes' => $this->cleanString($invoice['notes'] ?? null),
            'amount' => $amount,
        ];
    }

    private function normalizeTargets($targets): array
    {
        $targets = is_array($targets) ? $targets : [];

        return [
            'quote_id' => isset($targets['quote_id']) && is_numeric($targets['quote_id']) ? (int) $targets['quote_id'] : null,
            'quote_number' => $this->cleanString($targets['quote_number'] ?? null),
            'work_id' => isset($targets['work_id']) && is_numeric($targets['work_id']) ? (int) $targets['work_id'] : null,
            'work_number' => $this->cleanString($targets['work_number'] ?? null),
            'invoice_id' => isset($targets['invoice_id']) && is_numeric($targets['invoice_id']) ? (int) $targets['invoice_id'] : null,
            'invoice_number' => $this->cleanString($targets['invoice_number'] ?? null),
            'task_id' => isset($targets['task_id']) && is_numeric($targets['task_id']) ? (int) $targets['task_id'] : null,
            'request_id' => isset($targets['request_id']) && is_numeric($targets['request_id']) ? (int) $targets['request_id'] : null,
            'checklist_item_id' => isset($targets['checklist_item_id']) && is_numeric($targets['checklist_item_id']) ? (int) $targets['checklist_item_id'] : null,
        ];
    }

    private function normalizeItems($items): array
    {
        $items = Arr::wrap($items);
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->cleanString($item['name'] ?? null);
            $quantity = (int) ($item['quantity'] ?? 1);
            $quantity = $quantity > 0 ? $quantity : 1;
            $price = $item['price'];
            $price = $price === null ? null : (float) $price;
            $itemType = strtolower((string) ($item['item_type'] ?? ''));
            $itemType = $itemType === 'product' ? 'product' : ($itemType === 'service' ? 'service' : '');
            $unit = $this->cleanString($item['unit'] ?? null);

            $normalized[] = [
                'name' => $name,
                'quantity' => $quantity,
                'price' => $price,
                'item_type' => $itemType,
                'unit' => $unit,
            ];
        }

        return $normalized;
    }

    private function normalizeCustomer($customer): array
    {
        $customer = is_array($customer) ? $customer : [];

        return [
            'name' => $this->cleanString($customer['name'] ?? null),
            'company_name' => $this->cleanString($customer['company_name'] ?? null),
            'first_name' => $this->cleanString($customer['first_name'] ?? null),
            'last_name' => $this->cleanString($customer['last_name'] ?? null),
            'email' => $this->cleanString($customer['email'] ?? null),
            'phone' => $this->cleanString($customer['phone'] ?? null),
        ];
    }

    private function normalizeProperty($property): array
    {
        $property = is_array($property) ? $property : [];

        return [
            'type' => $this->cleanString($property['type'] ?? null),
            'street1' => $this->cleanString($property['street1'] ?? null),
            'street2' => $this->cleanString($property['street2'] ?? null),
            'city' => $this->cleanString($property['city'] ?? null),
            'state' => $this->cleanString($property['state'] ?? null),
            'zip' => $this->cleanString($property['zip'] ?? null),
            'country' => $this->cleanString($property['country'] ?? null),
            'is_default' => (bool) ($property['is_default'] ?? false),
            'customer' => $this->normalizeCustomer($property['customer'] ?? []),
        ];
    }

    private function normalizeCategory($category): array
    {
        $category = is_array($category) ? $category : [];

        return [
            'name' => $this->cleanString($category['name'] ?? null),
            'item_type' => $this->cleanString($category['item_type'] ?? null),
        ];
    }

    private function normalizeProduct($product): array
    {
        $product = is_array($product) ? $product : [];
        $price = $product['price'] ?? null;
        $price = $price === null ? null : (float) $price;

        return [
            'name' => $this->cleanString($product['name'] ?? null),
            'price' => $price,
            'item_type' => $this->cleanString($product['item_type'] ?? null),
            'category' => $this->cleanString($product['category'] ?? null),
            'unit' => $this->cleanString($product['unit'] ?? null),
            'description' => $this->cleanString($product['description'] ?? null),
        ];
    }

    private function normalizeTeamMember($member): array
    {
        $member = is_array($member) ? $member : [];
        $permissions = Arr::wrap($member['permissions'] ?? []);
        $cleanPermissions = [];
        foreach ($permissions as $permission) {
            $permission = $this->cleanString($permission);
            if ($permission !== '') {
                $cleanPermissions[] = $permission;
            }
        }

        return [
            'name' => $this->cleanString($member['name'] ?? null),
            'email' => $this->cleanString($member['email'] ?? null),
            'role' => $this->cleanString($member['role'] ?? null),
            'permissions' => array_values(array_unique($cleanPermissions)),
        ];
    }

    private function normalizeTeamMembers($members): array
    {
        $members = Arr::wrap($members);
        $normalized = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $normalized[] = [
                'name' => $this->cleanString($member['name'] ?? null),
                'email' => $this->cleanString($member['email'] ?? null),
                'role' => $this->cleanString($member['role'] ?? null),
            ];
        }

        return array_values(array_filter($normalized, function (array $member) {
            return $member['name'] !== '' || $member['email'] !== '';
        }));
    }

    private function normalizeTask($task): array
    {
        $task = is_array($task) ? $task : [];
        $assignee = is_array($task['assignee'] ?? null) ? $task['assignee'] : [];

        return [
            'title' => $this->cleanString($task['title'] ?? null),
            'description' => $this->cleanString($task['description'] ?? null),
            'status' => $this->cleanString($task['status'] ?? null),
            'due_date' => $this->cleanString($task['due_date'] ?? null),
            'assignee' => [
                'name' => $this->cleanString($assignee['name'] ?? null),
                'email' => $this->cleanString($assignee['email'] ?? null),
            ],
        ];
    }

    private function normalizeChecklistItem($item): array
    {
        $item = is_array($item) ? $item : [];

        return [
            'title' => $this->cleanString($item['title'] ?? null),
            'status' => $this->cleanString($item['status'] ?? null),
        ];
    }

    private function normalizeRequest($request): array
    {
        $request = is_array($request) ? $request : [];

        return [
            'title' => $this->cleanString($request['title'] ?? null),
            'service_type' => $this->cleanString($request['service_type'] ?? null),
            'description' => $this->cleanString($request['description'] ?? null),
            'channel' => $this->cleanString($request['channel'] ?? null),
            'urgency' => $this->cleanString($request['urgency'] ?? null),
            'contact_name' => $this->cleanString($request['contact_name'] ?? null),
            'contact_email' => $this->cleanString($request['contact_email'] ?? null),
            'contact_phone' => $this->cleanString($request['contact_phone'] ?? null),
            'country' => $this->cleanString($request['country'] ?? null),
            'state' => $this->cleanString($request['state'] ?? null),
            'city' => $this->cleanString($request['city'] ?? null),
            'street1' => $this->cleanString($request['street1'] ?? null),
            'street2' => $this->cleanString($request['street2'] ?? null),
            'postal_code' => $this->cleanString($request['postal_code'] ?? null),
        ];
    }

    private function normalizeFilters($filters): array
    {
        $filters = is_array($filters) ? $filters : [];
        $limit = $filters['limit'] ?? null;
        $limit = is_numeric($limit) ? (int) $limit : null;

        return [
            'search' => $this->cleanString($filters['search'] ?? null),
            'status' => $this->cleanString($filters['status'] ?? null),
            'limit' => $limit,
        ];
    }

    private function cleanString($value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value;
    }
}

<?php

use App\Support\BulkActions\BulkActionRegistry;
use Tests\TestCase;

uses(TestCase::class);

test('customer bulk action registry exposes menu actions and metadata', function () {
    $definition = app(BulkActionRegistry::class)->definitionFor('customer', [
        'can_edit' => true,
    ]);

    expect($definition)
        ->toMatchArray([
            'module' => 'customer',
            'enabled' => true,
            'method' => 'post',
            'menu_label_key' => 'customers.bulk.title',
            'selection_label_key' => 'customers.labels.selected',
        ])
        ->and($definition['endpoint'])->toBe(route('customer.bulk'))
        ->and($definition['actions'])->toHaveCount(6)
        ->and($definition['actions'][0])->toMatchArray([
            'key' => 'contact_selected',
            'kind' => 'client',
            'client_handler' => 'openBulkContact',
            'label_key' => 'customers.bulk_contact.action',
        ])
        ->and(collect($definition['actions'])->firstWhere('key', 'delete'))
        ->toMatchArray([
            'action' => 'delete',
            'confirm_key' => 'customers.bulk.delete_confirm',
            'tone' => 'danger',
        ]);
});

test('product bulk action registry exposes submit actions and delete confirmation', function () {
    $definition = app(BulkActionRegistry::class)->definitionFor('product', [
        'can_edit' => true,
    ]);

    expect($definition)
        ->toMatchArray([
            'module' => 'product',
            'enabled' => true,
            'method' => 'post',
            'menu_label_key' => 'products.bulk.actions',
            'selection_label_key' => 'products.bulk.selected',
        ])
        ->and($definition['endpoint'])->toBe(route('product.bulk'))
        ->and($definition['actions'])->toHaveCount(3)
        ->and(collect($definition['actions'])->pluck('action')->all())->toBe(['archive', 'restore', 'delete']);
});

test('request bulk action registry exposes status and assignee controls', function () {
    $definition = app(BulkActionRegistry::class)->definitionFor('request', [
        'statuses' => [
            ['id' => 'REQ_NEW', 'name' => 'New'],
            ['id' => 'REQ_LOST', 'name' => 'Lost'],
        ],
        'assignees' => [
            ['id' => 12, 'name' => 'Sam Team'],
        ],
    ]);

    expect($definition)
        ->toMatchArray([
            'module' => 'request',
            'enabled' => true,
            'method' => 'patch',
            'selection_label_key' => 'requests.bulk.selected',
        ])
        ->and($definition['endpoint'])->toBe(route('request.bulk'))
        ->and($definition['controls']['status'])->toMatchArray([
            'key' => 'status',
            'payload_key' => 'status',
            'label_key' => 'requests.bulk.status_label',
            'lost_reason_trigger_value' => 'REQ_LOST',
        ])
        ->and($definition['controls']['assign'])->toMatchArray([
            'key' => 'assign',
            'payload_key' => 'assigned_team_member_id',
            'label_key' => 'requests.bulk.assign_label',
        ])
        ->and($definition['controls']['status']['options'])->toBe([
            ['value' => 'REQ_NEW', 'label' => 'New'],
            ['value' => 'REQ_LOST', 'label' => 'Lost'],
        ])
        ->and($definition['controls']['assign']['options'])->toBe([
            ['value' => '12', 'label' => 'Sam Team'],
        ]);
});

<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;

test('task show uses the work quote property location when available', function () {
    $user = User::factory()->create([
        'company_features' => ['tasks' => true],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Test Co',
        'email' => 'task-location-owner@example.com',
        'salutation' => 'Mr',
    ]);

    Property::create([
        'customer_id' => $customer->id,
        'type' => 'physical',
        'is_default' => true,
        'street1' => '100 Default St',
        'street2' => null,
        'city' => 'Montreal',
        'state' => 'QC',
        'zip' => 'H1A 0A0',
        'country' => 'Canada',
    ]);

    $quoteProperty = Property::create([
        'customer_id' => $customer->id,
        'type' => 'physical',
        'is_default' => false,
        'street1' => '200 Work St',
        'street2' => null,
        'city' => 'Quebec',
        'state' => 'QC',
        'zip' => 'G1A 0A0',
        'country' => 'Canada',
    ]);

    $quote = Quote::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'property_id' => $quoteProperty->id,
        'job_title' => 'Quote job',
        'status' => 'draft',
        'subtotal' => 0,
        'total' => 0,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Work job',
        'instructions' => 'Handle work',
        'start_date' => now()->toDateString(),
        'subtotal' => 0,
        'total' => 0,
    ]);

    $task = Task::create([
        'account_id' => $user->id,
        'created_by_user_id' => $user->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Task with quote property',
        'status' => 'todo',
    ]);

    $response = $this->actingAs($user)->getJson(route('task.show', $task));

    $response->assertOk();
    $response->assertJsonPath('task.location.address', $quoteProperty->full_address);
    $response->assertJsonPath('task.location.city', 'Quebec');
});

test('task show falls back to the customer default property', function () {
    $user = User::factory()->create([
        'company_features' => ['tasks' => true],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Fallback Co',
        'email' => 'task-location-fallback@example.com',
        'salutation' => 'Mrs',
    ]);

    $property = Property::create([
        'customer_id' => $customer->id,
        'type' => 'physical',
        'is_default' => true,
        'street1' => '500 Default St',
        'street2' => null,
        'city' => 'Laval',
        'state' => 'QC',
        'zip' => 'H7A 0A0',
        'country' => 'Canada',
    ]);

    $task = Task::create([
        'account_id' => $user->id,
        'created_by_user_id' => $user->id,
        'customer_id' => $customer->id,
        'title' => 'Task with default property',
        'status' => 'todo',
    ]);

    $response = $this->actingAs($user)->getJson(route('task.show', $task));

    $response->assertOk();
    $response->assertJsonPath('task.location.address', $property->full_address);
    $response->assertJsonPath('task.location.city', 'Laval');
});

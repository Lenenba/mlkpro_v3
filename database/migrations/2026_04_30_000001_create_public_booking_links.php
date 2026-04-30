<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_booking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class, 'account_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_manual_confirmation')->default(true);
            $table->boolean('requires_deposit')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->string('source', 80)->nullable();
            $table->string('campaign', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'is_active']);
            $table->index('expires_at');
        });

        Schema::create('public_booking_link_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('public_booking_link_id')->constrained('public_booking_links')->cascadeOnDelete();
            $table->foreignIdFor(Product::class, 'service_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['public_booking_link_id', 'service_id'], 'public_booking_link_service_unique');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('public_booking_link_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('public_booking_links')
                ->nullOnDelete();
            $table->foreignIdFor(Customer::class, 'converted_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customers')
                ->nullOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignIdFor(LeadRequest::class, 'prospect_id')
                ->nullable()
                ->after('client_user_id')
                ->constrained('requests')
                ->nullOnDelete();
            $table->foreignId('public_booking_link_id')
                ->nullable()
                ->after('prospect_id')
                ->constrained('public_booking_links')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('public_booking_link_id');
            $table->dropConstrainedForeignId('prospect_id');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_customer_id');
            $table->dropConstrainedForeignId('public_booking_link_id');
        });

        Schema::dropIfExists('public_booking_link_services');
        Schema::dropIfExists('public_booking_links');
    }
};

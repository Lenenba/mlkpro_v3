<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plan_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->onDelete('cascade');
            $table->foreignIdFor(Customer::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Property::class)->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('trade_type')->nullable();
            $table->string('status')->default('new');
            $table->string('plan_file_path')->nullable();
            $table->string('plan_file_name')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('metrics')->nullable();
            $table->json('analysis')->nullable();
            $table->json('variants')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_scans');
    }
};

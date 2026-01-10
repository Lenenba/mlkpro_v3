<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_tour_steps', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('route_name');
            $table->string('selector')->nullable();
            $table->string('placement', 20)->default('bottom');
            $table->unsignedInteger('order_index')->default(0);
            $table->json('payload_json')->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->index(['route_name', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_tour_steps');
    }
};

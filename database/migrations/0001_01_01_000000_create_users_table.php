<?php

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
        // Create the 'roles' table
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('name')->unique(); // Unique role name (e.g., superadmin, admin, etc.)
            $table->string('description')->nullable(); // Optional description of the role
            $table->timestamps(); // Created at and updated at timestamps
        });

        // Create the 'users' table
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('name'); // User's name
            $table->string('email')->unique(); // Unique email
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete(); // Foreign key to 'roles' table
            $table->string('profile_picture')->nullable(); // Optional profile picture
            $table->string('phone_number')->nullable(); // Optional phone number
            $table->timestamp('email_verified_at')->nullable(); // Email verification timestamp
            $table->string('password'); // Password hash
            $table->rememberToken(); // Remember token for authentication
            $table->timestamps(); // Created at and updated at timestamps
        });

        // Create the 'password_reset_tokens' table
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary(); // Primary key is the email
            $table->string('token'); // Reset token
            $table->timestamp('created_at')->nullable(); // Token creation timestamp
        });

        // Create the 'sessions' table
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary(); // Primary key is the session ID
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete(); // Foreign key to 'users' table
            $table->string('ip_address', 45)->nullable(); // IP address (IPv4 or IPv6)
            $table->text('user_agent')->nullable(); // Browser's user agent
            $table->longText('payload'); // Session data
            $table->integer('last_activity')->index(); // Last activity timestamp (indexed for performance)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the tables in reverse order to respect dependencies
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};

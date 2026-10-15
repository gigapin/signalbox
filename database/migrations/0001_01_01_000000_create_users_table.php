<?php

/**
 * Create and remove the baseline users table schema.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Manage the initial framework tables before domain migrations are added. */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(
            'users',
            /**
         * Define the columns and indexes required by this framework table.
         *
         * @param Blueprint $table Schema definition receiving the table columns.
         */
            function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            },
        );

        Schema::create(
            'password_reset_tokens',
            /**
         * Define the columns and indexes required by this framework table.
         *
         * @param Blueprint $table Schema definition receiving the table columns.
         */
            function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            },
        );

        Schema::create(
            'sessions',
            /**
         * Define the columns and indexes required by this framework table.
         *
         * @param Blueprint $table Schema definition receiving the table columns.
         */
            function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            },
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

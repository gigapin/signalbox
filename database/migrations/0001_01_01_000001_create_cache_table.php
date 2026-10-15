<?php

/**
 * Create and remove the baseline cache table schema.
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
            'cache',
            /**
         * Define the columns and indexes required by this framework table.
         *
         * @param Blueprint $table Schema definition receiving the table columns.
         */
            function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->bigInteger('expiration')->index();
            },
        );

        Schema::create(
            'cache_locks',
            /**
         * Define the columns and indexes required by this framework table.
         *
         * @param Blueprint $table Schema definition receiving the table columns.
         */
            function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->bigInteger('expiration')->index();
            },
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};

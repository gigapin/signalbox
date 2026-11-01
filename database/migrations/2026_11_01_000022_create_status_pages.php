<?php

declare(strict_types=1);

/** Create public status pages, their ordered components and the monitors each component groups. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The join table replaces the specification's `monitor_ids_json`: composite keys make a monitor of another team
 * impossible at the database level, deleting a monitor removes it from every page, and one monitor cannot appear
 * in two components of a page.
 */
return new class () extends Migration {
    /** Create the three tables with tenant-bound keys and the checks shared with ManageStatusPages. */
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Public address segment, unique across all teams because it is resolved without a tenant.
            $table->text('slug')->unique();
            $table->text('title');
            $table->text('description')->nullable();
            // Shape {"mode": string, "accent": string}, values from closed enums.
            $table->jsonb('theme_json');
            // False is a draft: the public address answers 404.
            $table->boolean('is_public')->default(false);
            // Null means no password; the value is a one-way hash and never leaves the server.
            $table->text('password_hash')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['team_id', 'id'], 'status_pages_owner_unique');
        });

        Schema::create('status_page_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('status_page_id');
            $table->text('name');
            // Zero-based display order inside the page.
            $table->integer('position');
            $table->timestampsTz();
            $table->foreign(['team_id', 'status_page_id'])->references(['team_id', 'id'])->on('status_pages')->cascadeOnDelete();
            $table->unique(['status_page_id', 'position'], 'status_page_components_position_unique');
            $table->unique(['status_page_id', 'id'], 'status_page_components_owner_unique');
        });

        Schema::create('status_page_component_monitors', function (Blueprint $table): void {
            $table->unsignedBigInteger('status_page_component_id');
            $table->unsignedBigInteger('status_page_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('monitor_id');
            $table->primary(['status_page_component_id', 'monitor_id']);
            $table->foreign(['status_page_id', 'status_page_component_id'], 'status_page_component_monitors_component_fk')
                ->references(['status_page_id', 'id'])->on('status_page_components')->cascadeOnDelete();
            $table->foreign(['team_id', 'status_page_id'], 'status_page_component_monitors_page_fk')
                ->references(['team_id', 'id'])->on('status_pages')->cascadeOnDelete();
            $table->foreign(['team_id', 'monitor_id'], 'status_page_component_monitors_monitor_fk')
                ->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->unique(['status_page_id', 'monitor_id'], 'status_page_component_monitors_once_per_page');
            $table->index(['team_id', 'monitor_id'], 'status_page_component_monitors_monitor_idx');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE status_pages
                ADD CONSTRAINT status_pages_slug_format CHECK (slug ~ '^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$'),
                ADD CONSTRAINT status_pages_title_length CHECK (char_length(btrim(title)) BETWEEN 1 AND 120),
                ADD CONSTRAINT status_pages_description_length CHECK (description IS NULL OR char_length(description) BETWEEN 1 AND 500);
            ALTER TABLE status_page_components
                ADD CONSTRAINT status_page_components_name_length CHECK (char_length(btrim(name)) BETWEEN 1 AND 100),
                ADD CONSTRAINT status_page_components_position_range CHECK (position >= 0);
            SQL);
    }

    /** Drop the tables, children first. */
    public function down(): void
    {
        Schema::dropIfExists('status_page_component_monitors');
        Schema::dropIfExists('status_page_components');
        Schema::dropIfExists('status_pages');
    }
};

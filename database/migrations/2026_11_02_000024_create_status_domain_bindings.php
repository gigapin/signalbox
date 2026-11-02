<?php

declare(strict_types=1);

/** Keep pending DNS claims on pages and exclusive, expiring verified ownership in a global name registry. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pending claims cannot squat a hostname; only a successfully verified association occupies the registry. */
return new class () extends Migration {
    /** Add revocable claim generations and the composite ownership key for verified host routing. */
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->text('custom_domain')->nullable()->index();
            // Public DNS proof value; rotating it invalidates any in-flight verification.
            $table->uuid('domain_challenge')->nullable();
            $table->timestampTz('domain_checked_at')->nullable();
            $table->unique(['team_id', 'id', 'custom_domain', 'domain_challenge'], 'status_pages_domain_claim_unique');
        });

        Schema::create('status_domain_bindings', function (Blueprint $table): void {
            $table->text('hostname')->primary();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('status_page_id')->unique();
            $table->uuid('challenge');
            $table->timestampTz('verified_at');
            $table->timestampTz('expires_at')->index();
            $table->foreign(['team_id', 'status_page_id', 'hostname', 'challenge'], 'status_domain_binding_claim_fk')
                ->references(['team_id', 'id', 'custom_domain', 'domain_challenge'])->on('status_pages')->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE status_pages
                ADD CONSTRAINT status_pages_domain_pair CHECK ((custom_domain IS NULL) = (domain_challenge IS NULL)),
                ADD CONSTRAINT status_pages_domain_format CHECK (custom_domain IS NULL OR
                    (char_length(custom_domain) BETWEEN 3 AND 242 AND custom_domain = lower(custom_domain)
                    AND custom_domain ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$'));
            ALTER TABLE status_domain_bindings ADD CONSTRAINT status_domain_binding_expiry CHECK (expires_at > verified_at);
            SQL);
    }

    /** Remove verified associations before reversing the pending claim fields. */
    public function down(): void
    {
        Schema::dropIfExists('status_domain_bindings');
        DB::statement('ALTER TABLE status_pages DROP CONSTRAINT status_pages_domain_pair, DROP CONSTRAINT status_pages_domain_format');
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropUnique('status_pages_domain_claim_unique');
            $table->dropColumn(['custom_domain', 'domain_challenge', 'domain_checked_at']);
        });
    }
};

<?php

declare(strict_types=1);

/** Introduce private monitor configuration without modifying existing identity aggregates. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Enforce PostgreSQL structural invariants independently of HTTP validation and future plan policies. */
return new class () extends Migration {
    /** Create encrypted network columns, typed configuration storage and inactive scheduling state. */
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');

            // Encrypted casts need text storage, including the logically JSON-shaped HTTP headers.
            $table->text('url')->nullable();
            $table->string('method')->nullable();
            $table->text('headers_json')->nullable();
            $table->text('body')->nullable();
            $table->integer('expected_status')->nullable();
            $table->boolean('follow_redirects')->nullable();
            $table->text('expected_keyword')->nullable();
            $table->string('keyword_mode')->nullable();
            $table->string('tls_host')->nullable();
            $table->integer('tls_port')->nullable();
            $table->integer('tls_expiry_warning_days')->nullable();
            $table->string('dns_name')->nullable();
            $table->string('dns_record_type')->nullable();
            $table->jsonb('dns_expected_values')->nullable();
            $table->string('dns_resolver')->nullable();
            $table->integer('heartbeat_grace_seconds')->nullable();

            $table->jsonb('regions_json')->default('["eu"]');
            $table->integer('interval_seconds');
            $table->integer('timeout_ms');
            $table->integer('confirmation_threshold')->default(3);
            $table->boolean('is_paused')->default(false);
            $table->boolean('is_plan_suspended')->default(false);
            $table->string('last_status')->default('unknown');
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->timestampTz('ssl_expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['team_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE monitors
                ADD CONSTRAINT monitors_name_check CHECK (length(trim(name)) > 0),
                ADD CONSTRAINT monitors_type_check CHECK (type IN ('http', 'tls', 'dns', 'heartbeat', 'keyword')),
                ADD CONSTRAINT monitors_status_check CHECK (last_status IN ('unknown', 'up', 'down')),
                ADD CONSTRAINT monitors_timing_check CHECK (interval_seconds > 0 AND timeout_ms > 0 AND confirmation_threshold > 0),
                ADD CONSTRAINT monitors_failure_count_check CHECK (consecutive_failures >= 0),
                ADD CONSTRAINT monitors_regions_check CHECK (regions_json IN ('["eu"]'::jsonb, '["us"]'::jsonb, '["eu","us"]'::jsonb)),
                ADD CONSTRAINT monitors_http_shape_check CHECK (
                    (type IN ('http', 'keyword')
                        AND url IS NOT NULL AND headers_json IS NOT NULL
                        AND method IS NOT NULL AND method IN ('GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS')
                        AND expected_status IS NOT NULL AND expected_status BETWEEN 100 AND 599
                        AND follow_redirects IS NOT NULL)
                    OR (type NOT IN ('http', 'keyword')
                        AND url IS NULL AND method IS NULL AND headers_json IS NULL AND body IS NULL
                        AND expected_status IS NULL AND follow_redirects IS NULL)
                ),
                ADD CONSTRAINT monitors_keyword_shape_check CHECK (
                    (type = 'keyword' AND expected_keyword IS NOT NULL AND length(expected_keyword) > 0
                        AND keyword_mode IS NOT NULL AND keyword_mode IN ('present', 'absent'))
                    OR (type <> 'keyword' AND expected_keyword IS NULL AND keyword_mode IS NULL)
                ),
                ADD CONSTRAINT monitors_tls_shape_check CHECK (
                    (type = 'tls' AND tls_host IS NOT NULL AND length(trim(tls_host)) > 0
                        AND tls_port IS NOT NULL AND tls_port BETWEEN 1 AND 65535
                        AND (tls_expiry_warning_days IS NULL OR tls_expiry_warning_days >= 0))
                    OR (type <> 'tls' AND tls_host IS NULL AND tls_port IS NULL AND tls_expiry_warning_days IS NULL)
                ),
                ADD CONSTRAINT monitors_dns_shape_check CHECK (
                    (type = 'dns' AND dns_name IS NOT NULL AND length(trim(dns_name)) > 0
                        AND dns_record_type IS NOT NULL AND dns_record_type IN ('A','AAAA','CNAME','MX','TXT')
                        AND dns_expected_values IS NOT NULL
                        AND CASE WHEN jsonb_typeof(dns_expected_values) = 'array'
                            THEN jsonb_array_length(dns_expected_values) > 0
                                AND NOT jsonb_path_exists(dns_expected_values, '$[*] ? (@.type() != "string" || @ == "")')
                            ELSE false END
                        AND (dns_resolver IS NULL OR length(trim(dns_resolver)) > 0))
                    OR (type <> 'dns' AND dns_name IS NULL AND dns_record_type IS NULL AND dns_expected_values IS NULL AND dns_resolver IS NULL)
                ),
                ADD CONSTRAINT monitors_heartbeat_shape_check CHECK (
                    (type = 'heartbeat' AND heartbeat_grace_seconds IS NOT NULL AND heartbeat_grace_seconds >= 0)
                    OR (type <> 'heartbeat' AND heartbeat_grace_seconds IS NULL)
                );

            CREATE INDEX monitors_due_check_idx ON monitors (next_check_at, id)
                WHERE NOT is_paused AND NOT is_plan_suspended AND next_check_at IS NOT NULL;
            SQL);
    }

    /** Remove only monitor configuration when reversing this incremental migration. */
    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};

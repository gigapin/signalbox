<?php

/** Add optional profile and private pending-email state without changing existing login identities. */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** Add optional fields and preserve the original name without guessing its parts. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['first_name', 'last_name', 'phone', 'country', 'company_name', 'vat_number', 'avatar_path'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('pending_email')->nullable();
            $table->string('email_change_token_hash', 64)->nullable();
            $table->timestampTz('email_change_expires_at')->nullable();
            $table->timestampTz('email_change_sent_at')->nullable();
        });
        DB::table('users')->update(['first_name' => DB::raw('name')]);
    }
    /** Remove only optional profile state. */
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name','last_name','phone','country','company_name','vat_number','avatar_path','pending_email','email_change_token_hash','email_change_expires_at','email_change_sent_at']));
    }
};

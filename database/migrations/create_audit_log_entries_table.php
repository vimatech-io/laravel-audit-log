<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;

return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        Schema::create($table, function (Blueprint $table): void {
            $table->id();

            $table->nullableMorphs('tenant');
            $table->nullableMorphs('actor');
            $table->string('actor_guard', 64)->nullable();
            $table->nullableMorphs('impersonator');

            $table->string('action', 128)->index();
            $table->nullableMorphs('subject');

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at')->index();

            $table->index(['tenant_type', 'tenant_id', 'occurred_at']);
        });

        if (config('audit-log.append_only_trigger', true)) {
            AppendOnlyTrigger::install($table);
        }
    }

    public function down(): void
    {
        $table = $this->table();

        AppendOnlyTrigger::remove($table);
        Schema::dropIfExists($table);
    }

    private function table(): string
    {
        /** @var string $table */
        $table = config('audit-log.tables.entries', 'audit_log_entries');

        return $table;
    }
};

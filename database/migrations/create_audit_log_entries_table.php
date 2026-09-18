<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;

return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();
        $connection = $this->connection();

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_type')->nullable();
            $table->string('tenant_id', AuditEntry::MORPH_KEY_LENGTH)->nullable();

            $table->string('actor_type')->nullable();
            $table->string('actor_id', AuditEntry::MORPH_KEY_LENGTH)->nullable();
            $table->string('actor_guard', AuditEntry::ACTOR_GUARD_LENGTH)->nullable();

            $table->string('impersonator_type')->nullable();
            $table->string('impersonator_id', AuditEntry::MORPH_KEY_LENGTH)->nullable();

            $table->string('action', AuditEntry::ACTION_LENGTH);

            $table->string('subject_type')->nullable();
            $table->string('subject_id', AuditEntry::MORPH_KEY_LENGTH)->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();

            $table->string('ip', AuditEntry::IP_LENGTH)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', AuditEntry::REQUEST_ID_LENGTH)->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('occurred_at');

            $table->index(['tenant_type', 'tenant_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
            $table->index('action');
            $table->index('occurred_at');
            $table->index('request_id');
        });

        AppendOnlyTrigger::install($table, $connection);
    }

    public function down(): void
    {
        $table = $this->table();
        $connection = $this->connection();

        AppendOnlyTrigger::remove($table, $connection);
        Schema::connection($connection)->dropIfExists($table);
    }

    private function connection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-log.connection');

        return $connection;
    }

    private function table(): string
    {
        /** @var string $table */
        $table = config('audit-log.tables.entries', 'audit_log_entries');

        return $table;
    }
};

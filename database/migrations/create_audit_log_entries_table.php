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
            $table->string('request_id', AuditEntry::REQUEST_ID_LENGTH)->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at')->index();

            $table->index(['tenant_type', 'tenant_id', 'occurred_at']);
        });

        if (config('audit-log.append_only_trigger', true)) {
            AppendOnlyTrigger::install($table, $connection);
        }
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

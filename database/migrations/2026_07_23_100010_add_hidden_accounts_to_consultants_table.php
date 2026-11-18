<?php

use App\Models\Consultant;
use App\Services\HiddenAccountService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FK = 'consultants_user_id_foreign';

    public function up(): void
    {
        // 1. Add the new columns first so the app has them even if a later step
        //    needs a retry. Idempotent — safe to re-run after a partial migration.
        Schema::table('consultants', function (Blueprint $table) {
            if (! Schema::hasColumn('consultants', 'is_hidden')) {
                $table->boolean('is_hidden')->default(false)->index()->after('user_id');
            }
            if (! Schema::hasColumn('consultants', 'owner_consultant_id')) {
                // The real consultant that owns this hidden account (their SCN00101/102).
                $table->foreignId('owner_consultant_id')->nullable()->after('is_hidden')
                    ->constrained('consultants')->nullOnDelete();
            }
        });

        // 2. Hidden accounts have no login, so user_id must allow NULL. MySQL/MariaDB
        //    reject MODIFY on a column while its foreign key exists, so drop it, alter,
        //    and re-add. Every step is guarded to tolerate a half-finished prior run.
        if (DB::getDriverName() === 'mysql') {
            if (! $this->userIdIsNullable()) {
                if ($this->foreignKeyExists(self::FK)) {
                    Schema::table('consultants', fn (Blueprint $t) => $t->dropForeign(self::FK));
                }

                DB::statement('ALTER TABLE consultants MODIFY user_id BIGINT UNSIGNED NULL');
            }

            if (! $this->foreignKeyExists(self::FK)) {
                Schema::table('consultants', fn (Blueprint $t) =>
                    $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()
                );
            }
        } elseif (! $this->userIdIsNullable()) {
            // Portable path (SQLite in tests): Laravel alters nullability directly.
            Schema::table('consultants', fn (Blueprint $t) =>
                $t->unsignedBigInteger('user_id')->nullable()->change()
            );
        }

        // 3. Backfill: give every existing real consultant its two hidden accounts.
        $service = app(HiddenAccountService::class);
        Consultant::query()->where('is_hidden', false)->orderBy('id')->each(
            fn (Consultant $c) => $service->ensureFor($c)
        );
    }

    public function down(): void
    {
        // Remove hidden accounts first so user_id can go back to NOT NULL.
        Consultant::query()->where('is_hidden', true)->delete();

        Schema::table('consultants', function (Blueprint $table) {
            if (Schema::hasColumn('consultants', 'owner_consultant_id')) {
                $table->dropConstrainedForeignId('owner_consultant_id');
            }
            if (Schema::hasColumn('consultants', 'is_hidden')) {
                $table->dropColumn('is_hidden');
            }
        });

        // Only revert to NOT NULL if no null user_id rows remain.
        $hasNulls = DB::table('consultants')->whereNull('user_id')->exists();
        if (! $hasNulls) {
            if ($this->foreignKeyExists(self::FK)) {
                Schema::table('consultants', fn (Blueprint $t) => $t->dropForeign(self::FK));
            }
            DB::statement('ALTER TABLE consultants MODIFY user_id BIGINT UNSIGNED NOT NULL');
            Schema::table('consultants', fn (Blueprint $t) =>
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()
            );
        }
    }

    private function userIdIsNullable(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (Schema::getColumns('consultants') as $col) {
                if ($col['name'] === 'user_id') {
                    return (bool) $col['nullable'];
                }
            }

            return true;
        }

        $col = collect(DB::select("SHOW COLUMNS FROM consultants LIKE 'user_id'"))->first();

        return $col && strtoupper($col->Null) === 'YES';
    }

    private function foreignKeyExists(string $name): bool
    {
        // information_schema is MySQL-only; other drivers use the portable paths above.
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'consultants')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};

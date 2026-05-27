<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Truncate des données existantes (décision : on repart à zéro multi-tenant).
        DB::table('hfsql_raw_rows')->truncate();
        DB::table('hfsql_sync_runs')->truncate();

        Schema::table('hfsql_raw_rows', function (Blueprint $table) {
            $table->dropUnique(['table_name', 'row_key']);
            $table->dropIndex(['table_name']);
            $table->dropIndex(['row_key']);
            $table->foreignId('tenant_id')->after('id')
                ->constrained('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'table_name']);
            $table->unique(['tenant_id', 'table_name', 'row_key']);
        });

        Schema::table('hfsql_sync_runs', function (Blueprint $table) {
            $table->dropIndex(['table_name', 'started_at']);
            $table->foreignId('tenant_id')->after('id')
                ->constrained('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'table_name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('hfsql_raw_rows', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'table_name', 'row_key']);
            $table->dropIndex(['tenant_id', 'table_name']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->index('table_name');
            $table->index('row_key');
            $table->unique(['table_name', 'row_key']);
        });
        Schema::table('hfsql_sync_runs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'table_name', 'started_at']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->index(['table_name', 'started_at']);
        });
    }
};

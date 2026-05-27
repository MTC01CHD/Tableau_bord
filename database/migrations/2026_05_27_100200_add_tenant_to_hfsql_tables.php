<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hfsql_tables', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->foreignId('tenant_id')->after('id')
                ->constrained('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('hfsql_tables', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique('name');
        });
    }
};

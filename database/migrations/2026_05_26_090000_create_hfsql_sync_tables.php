<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stockage brut générique : une ligne = une ligne HFSQL.
        // Permet d'exposer n'importe quelle table HFSQL sans migration dédiée.
        Schema::create('hfsql_raw_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name', 64)->index();
            $table->string('row_key', 191)->nullable()->index();
            $table->jsonb('payload');
            $table->timestamp('synced_at')->useCurrent();
            $table->unique(['table_name', 'row_key']);
        });

        // Journal de chaque exécution de synchro (pour debug + indicateurs).
        Schema::create('hfsql_sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name', 64);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('rows_pulled')->default(0);
            $table->integer('rows_upserted')->default(0);
            $table->string('status', 16)->default('running'); // running|ok|error
            $table->text('error')->nullable();
            $table->index(['table_name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hfsql_sync_runs');
        Schema::dropIfExists('hfsql_raw_rows');
    }
};

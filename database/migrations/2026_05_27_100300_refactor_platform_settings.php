<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On reconstruit la table : PK devient (tenant_id, key) au lieu de (key).
        // Les anciens settings sont jetés (l'admin reconfigurera après migration).
        Schema::dropIfExists('platform_settings');
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 64);
            $table->jsonb('value')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });
    }
};

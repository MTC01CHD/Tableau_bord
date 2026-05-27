<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paramètres de la plateforme (HFSQL config + futurs réglages admin).
        // Stockés en clé/valeur, valeur en JSONB pour structures riches.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });

        // Liste des tables HFSQL sélectionnées pour la sync (gérée depuis l'admin).
        Schema::create('hfsql_tables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->unique();
            $table->string('date_column', 64)->nullable(); // colonne pour filtre incrémental N mois
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hfsql_tables');
        Schema::dropIfExists('platform_settings');
    }
};

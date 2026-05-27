<?php

namespace App\Models;

use App\Support\HfsqlDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vue typée sur les lignes HFSQL S_Projet stockées dans hfsql_raw_rows.
 * Les accessors traduisent les champs HFSQL en types PHP utilisables.
 */
class Projet extends HfsqlRawRow
{
    protected $table = 'hfsql_raw_rows';

    protected static function booted(): void
    {
        static::addGlobalScope('s_projet', fn (Builder $q) => $q->where('table_name', 'S_Projet'));
    }

    public function getIdProjetAttribute(): ?int
    {
        return $this->payload['IDProjet'] ?? null;
    }

    public function getNomAttribute(): string
    {
        return (string) ($this->payload['Nom'] ?? '');
    }

    public function getNumeroAttribute(): string
    {
        return (string) ($this->payload['numero'] ?? '');
    }

    public function getDescriptionAttribute(): string
    {
        return (string) ($this->payload['Description'] ?? '');
    }

    public function getEtatAttribute(): string
    {
        return (string) ($this->payload['Etat_Code'] ?? '');
    }

    public function getHeuresPrevuesAttribute(): float
    {
        return (float) ($this->payload['HeuresPrevues'] ?? 0);
    }

    public function getIsSupprimeAttribute(): bool
    {
        return ((int) ($this->payload['Supprimer'] ?? 0)) === 1;
    }

    public function getDateDebutAttribute(): ?CarbonImmutable
    {
        return HfsqlDate::parse($this->payload['DateDeDebut'] ?? null);
    }

    public function getDateFinAttribute(): ?CarbonImmutable
    {
        return HfsqlDate::parse($this->payload['DateDeFin'] ?? null)
            ?? HfsqlDate::parse($this->payload['DateFinEngagement'] ?? null);
    }

    public function getIdGestionnaireAttribute(): ?int
    {
        return $this->payload['IDGestionnaire'] ?? null;
    }

    public function getIdDepartementAttribute(): ?int
    {
        return $this->payload['ID_Departement'] ?? null;
    }

    /** Filtre les projets actifs (non supprimés). */
    public function scopeActifs(Builder $q): Builder
    {
        return $q->whereRaw("(payload->>'Supprimer')::int = 0");
    }

    /** Recherche texte sur nom + numéro + description. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (!$term) return $q;
        $pattern = '%' . strtolower($term) . '%';
        return $q->where(function ($w) use ($pattern) {
            $w->whereRaw("LOWER(payload->>'Nom') LIKE ?", [$pattern])
              ->orWhereRaw("LOWER(payload->>'numero') LIKE ?", [$pattern])
              ->orWhereRaw("LOWER(payload->>'Description') LIKE ?", [$pattern]);
        });
    }

    /** Filtre par état (A_PLANIFIER, EN_COURS, …). */
    public function scopeEtat(Builder $q, ?string $etat): Builder
    {
        return $etat ? $q->whereRaw("payload->>'Etat_Code' = ?", [$etat]) : $q;
    }
}

# Tableau de bord — modèle métier

> Référence canonique pour le calcul **Prévu / Réalisé / Dépensé** par projet et période.
> Toute modification du code de calcul doit rester alignée sur ce document.

## 1. Objectif

Pour un projet × une période donnée, le dashboard compare :

- ce qui était **prévu** (devis),
- ce qui a été **réalisé** (facturé au client),
- ce qui a été **dépensé** (consommé en heures, matériel, achats),

ventilé par **famille de moyens** (la liste vient de `S_Famille_Moyen`, JAMAIS codée en dur).

## 2. Les trois axes financiers

| Axe | Définition | Source HFSQL |
|---|---|---|
| **Prévu PV** | Prix de vente prévu (= autorisé contractuel) | Σ `S_Tache.Somme_V` |
| **Prévu PR** | Prix de revient prévu | Σ `S_Tache.Somme_R` |
| **Heures prévues** | Heures prévues sur les tâches | Σ `S_Tache.Heures` |
| **Réalisé PV** | Ce qui a été facturé au client | Σ `S_Com_Suivi_Element.Somme_V` où `S_Com_Suivi.Type='Vente'` |
| **Réalisé PR** | Prix de revient des lignes facturées | Σ `S_Com_Suivi_Element.Somme_R` où `S_Com_Suivi.Type='Vente'` |
| **Dépensé** | Ce qui a réellement coûté (Σ des 4 familles ci-dessous) | voir §4 |

### Marges utiles

| Marge | Formule | Sens |
|---|---|---|
| Marge prévue | `Prévu PV − Prévu PR` | Marge théorique du devis |
| Marge réalisée | `Réalisé PV − Réalisé PR` | Marge sur la facturation |
| **Marge réelle** | `Réalisé PV − Dépensé` | **Vrai écart cash** (ce qui compte) |

## 3. Règles d'exclusion sur S_Tache (Prévu)

Les options inactives ne comptent pas dans le prévu :

```
EXCLURE si TypeElement = 'OPTION' AND OptionActive = 0
```

Donc on garde : toutes les tâches normales + les options actives (`OptionActive = 1`).

## 4. Familles de moyens — dynamique depuis `S_Famille_Moyen`

La liste des familles est lue depuis la table HFSQL, filtrée `ActiveDefaut = 1`.
Chaque famille porte deux flags qui **déterminent sa source de données** :

| Flag | Famille type | Source de la dépense |
|---|---|---|
| `ParDefaut = 1` | Main d'œuvre | `P_Planning × P_Planning_Pointage × P_Ressource_Prix(TypeRessource='Personnel')` |
| `Materiel = 1` | Engins | `P_Pointage_Materiel` + `p_pointage_materiel_location` × `P_Ressource_Prix(TypeRessource='Materiel')` |
| (autres) | Fournitures, Sous-traitance, Divers, … | `S_Com_Suivi_Element` où `S_Com_Suivi.Type='Achat'` ET `ConstanteFamille` correspond |

### Liaison achat ↔ famille

- L'élément (`S_Com_Suivi_Element.ConstanteFamille`) porte la famille,
- **Si vide**, fallback sur la `ConstanteFamille` du suivi parent (`S_Com_Suivi.ConstanteFamille`),
- Format : `FM01`, `FM02`, … (cf. `S_Famille_Moyen.ConstanteFamille`).

## 5. Règles de calcul par source

### Personnel (familles `ParDefaut = 1`)

- Plannings du projet : `P_Planning.ID_Origine = IDProjet`, filtre date sur `DateRDZDebut`.
- Pour chaque `IDP_Planning` : si une ligne `P_Planning_Pointage` existe avec `original = 0` (correction), prendre sa `Duree`. Sinon, prendre la ligne `original = 1`.
- FK personne : `P_Planning.ID_Personnel_Base`.
- Tarif : `P_Ressource_Prix.PrixImputation` la plus récente où `APartirDu <= DateRDZDebut` et `TypeRessource = 'Personnel'`. On garde la ligne même si tarif = 0 (pour montrer les heures pointées).

### Matériel (familles `Materiel = 1`)

Agrège deux sources :

**`P_Pointage_Materiel`** (matériel sur planning)
- Joint à `P_Planning` via `IDP_Planning`, filtre projet via `P_Planning.ID_Origine`.
- Quantité = `Valeur` si `Modif = 0`, sinon `ValeurModif`.
- FK matériel : `ID_Materiel_Base`. Tarif : `TypeRessource = 'Materiel'`. Date : `P_Planning.DateRDZDebut`.

**`p_pointage_materiel_location`** (location matériel)
- FK projet : `ID_Origine`. FK matériel : `ID_Materiel`. Date : `DatePointage`.
- Quantité = `Duree` si `Modif = 0`, sinon `DureeModif`.
- Tarif idem.

Pour les deux : filtre `PrixImputation > 0`, libellé via `S_Engin.Numero` + `S_Engin.Designation`.

### Achats (familles `ParDefaut = 0` ET `Materiel = 0`)

- `S_Com_Suivi` où `Type = 'Achat'`, `IDProjet = ?`, date sur `DateDeDebut`.
- Joint à `S_Com_Suivi_Element` via `IDS_Com_Suivi`.
- Filtre ligne : `Element.ConstanteFamille = famille.ConstanteFamille` (avec fallback sur le suivi parent si l'élément n'en a pas).
- Total ligne = `Somme_V` (déjà pré-calculé, **ne pas** recalculer `Quantite × PU_V`).

### Réalisé (ventes facturées)

- `S_Com_Suivi` où `Type = 'Vente'`, `IDProjet = ?`, filtre date sur `DateDeDebut`.
- Joint à `S_Com_Suivi_Element` via `IDS_Com_Suivi`.
- Réalisé PV = Σ `Element.Somme_V`. Réalisé PR = Σ `Element.Somme_R`.

## 6. Colonnes HFSQL confirmées

### Tâches & projets
- `S_Tache` : `IDProjet`, `Numero`, `Designation`, `TypeElement`, `OptionActive`, `Quantite`, `PU_V`, `Somme_V`, `Somme_R`, `Heures`, `Unite`, `Ordre`
- `S_Projet` : `IDProjet`, `numero`, `Nom`, `Description`, `Etat_Code` (⚠ nom à confirmer définitivement via `?debug=etat`), `IDGestionnaire`, `ID_Departement`, `DateDeDebut`, `DateDeFin`, `Supprimer`
- `S_Famille_Moyen` : `IDFamille_Moyen`, `NomFamille`, `ConstanteFamille` (FM01..FM10), `ParDefaut`, `Materiel`, `ActiveDefaut`, `CouleurFamille`, `MargeDefaut`, `TypeRessource`

### Suivi commercial
- `S_Com_Suivi` : `IDS_Com_Suivi`, `IDProjet`, `Type` ∈ {`Vente`, `Achat`}, `ConstanteFamille`, `DateDeDebut`, `DateDeFin`
- `S_Com_Suivi_Element` : `IDS_Com_Suivi`, `ConstanteFamille`, `Designation`, `Quantite`, `PU_V`, `Somme_V`, `Somme_R`

### Planning & pointages
- `P_Planning` : `IDP_Planning`, `ID_Origine` (= IDProjet), `ID_Personnel_Base`, `DateRDZDebut`
- `P_Planning_Pointage` : `IDP_Planning`, `original` (0/1), `Duree`
- `P_Pointage_Materiel` : `IDP_Planning`, `ID_Materiel_Base`, `Modif` (0/1), `Valeur`, `ValeurModif`
- `p_pointage_materiel_location` : `ID_Origine`, `ID_Materiel`, `DatePointage`, `Modif`, `Duree`, `DureeModif`

### Référentiels
- `P_Ressource_Prix` : `IDRessource`, `TypeRessource` ∈ {`Personnel`, `Materiel`}, `APartirDu`, `PrixImputation`
- `S_Engin` : `ID_Engin`, `Numero`, `Designation`
- `S_Personnel` : `IDPersonnel`, `Nom`, `Prenom`
- `S_Projet_etat` : `Etat_Code`, `Descriptif`

## 7. Tables à synchroniser

Liste dans [`config/hfsql-tables.php`](../config/hfsql-tables.php). Toute table manquante dans la sync rend la famille correspondante **vide** (le service est défensif via `tableExists()`).

```
Projets / référentiels      : S_Projet, S_Projet_etat, S_Tache, S_Moyen, S_Famille_Moyen,
                              S_LiaisonTacheMoyen, S_Personnel, S_Engin
Suivi commercial            : S_Com_Suivi, S_Com_Suivi_Element
Pointages personnel         : P_Planning, P_Planning_Pointage
Pointages matériel + tarifs : P_Pointage_Materiel, p_pointage_materiel_location, P_Ressource_Prix
```

## 8. Implémentation

### Code organisé

| Fichier | Rôle |
|---|---|
| [`app/Services/ProjetDepensesService.php`](../app/Services/ProjetDepensesService.php) | Calculs Dépenses (par famille dynamique) et Réalisé (agrégé / détail par projet) |
| [`app/Http/Controllers/DashboardController.php`](../app/Http/Controllers/DashboardController.php) | Vue liste (KPI portfolio, agrégats par projet, filtres) + vue projet (détail, filtre période) + export CSV |
| [`app/Models/Projet.php`](../app/Models/Projet.php) | Modèle Projet (scope `etat()`, accesseurs typés) |
| [`resources/views/dashboard/index.blade.php`](../resources/views/dashboard/index.blade.php) | Vue liste projets |
| [`resources/views/dashboard/projet.blade.php`](../resources/views/dashboard/projet.blade.php) | Vue détail d'un projet |

### Méthodes clés de `ProjetDepensesService`

```php
// Dépenses ventilées par famille dynamique, pour UN projet sur la période
calculer(int $idProjet, ?CarbonImmutable $from, ?CarbonImmutable $to): array

// Réalisé détaillé (lignes), pour UN projet sur la période
calculerRealise(int $idProjet, ?CarbonImmutable $from, ?CarbonImmutable $to): array

// Réalisé agrégé par projet, pour TOUS les projets (une seule requête SQL) — pour la liste
realiseParProjet(): Collection
```

## 9. À ne JAMAIS faire

- ❌ Hard-coder les noms de familles (`'Achat'`, `'Personnel'`, ...) — toujours lire `S_Famille_Moyen`
- ❌ Utiliser `S_Tache.Somme_R` comme "Réalisé" — c'est le **prévu** en prix de revient
- ❌ Recalculer `Quantite × PU_V` sur S_Com_Suivi_Element pour un agrégat — utiliser `Somme_V` / `Somme_R` (pré-calculés)
- ❌ Recalculer le dépensé depuis P_Planning_Pointage en ignorant les corrections — toujours appliquer la règle modif (`original=0`) > original (`original=1`)
- ❌ Compter les options inactives dans le prévu — appliquer le filtre `TypeElement='OPTION' AND OptionActive=0`

## 10. Diagnostic

Si le filtre état est vide ou si une famille reste à 0 € de manière inexpliquée :

- Ajouter `?debug=etat` à l'URL du dashboard liste : affiche les clés réelles des payloads `S_Projet` pour identifier le vrai nom de colonne.
- Vérifier dans Admin → Tables à synchroniser que les tables requises sont bien activées et ont des lignes récentes.
- Pour les familles d'achat à 0 € : vérifier la `ConstanteFamille` sur quelques éléments `S_Com_Suivi_Element` (la valeur doit matcher celle de `S_Famille_Moyen`).

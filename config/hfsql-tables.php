<?php

/**
 * Tables HFSQL synchronisées par `php artisan hfsql:sync`.
 *
 * Liste restreinte aux tables strictement nécessaires au dashboard
 * « prévu vs dépensé par projet/période/famille ».
 *
 *   PRÉVU      ← S_Projet · S_Tache · S_Moyen · S_Famille_Moyen · S_LiaisonTacheMoyen
 *   DÉPENSÉ    ← S_Com_Suivi · S_Com_Suivi_Element  (consolidé par famille)
 *   PLANNING   ← P_Planning · P_Planning_Pointage   (avec règle original/modifié)
 *
 * Pour ajouter une table : ajoute-la ici et relance `php artisan hfsql:sync`.
 */

return [
    'S_Projet',
    'S_Projet_etat',           // libellé des états de projet (lookup Etat_Code)
    'S_Tache',
    'S_Moyen',
    'S_Famille_Moyen',
    'S_LiaisonTacheMoyen',
    'S_Com_Suivi',
    'S_Com_Suivi_Element',
    'P_Planning',
    'P_Planning_Pointage',
];

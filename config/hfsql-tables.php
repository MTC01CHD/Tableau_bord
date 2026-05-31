<?php

/**
 * Tables HFSQL synchronisées par `php artisan hfsql:sync`.
 *
 * Liste alignée sur la requête métier "Dépenses par projet" en 4 catégories :
 *
 *   PROJETS / RÉFÉRENTIELS
 *     S_Projet · S_Projet_etat · S_Tache · S_Moyen · S_Famille_Moyen
 *     S_LiaisonTacheMoyen · S_Personnel · S_Engin
 *
 *   AUTORISÉ (vente) / ACHAT
 *     S_Com_Suivi · S_Com_Suivi_Element        (Type = 'Vente' | 'Achat')
 *
 *   POINTAGES (calcul des coûts personnel/matériel/location)
 *     P_Planning · P_Planning_Pointage         (règle original/modifié)
 *     P_Pointage_Materiel                      (matériel sur planning)
 *     p_pointage_materiel_location             (location matériel)
 *     P_Ressource_Prix                         (tarifs Personnel/Materiel, historisés)
 *
 * Pour ajouter une table : ajoute-la ici et relance `php artisan hfsql:sync`.
 */

return [
    // Projets + référentiels
    'S_Projet',
    'S_Projet_etat',
    'S_Tache',
    'S_Moyen',
    'S_Famille_Moyen',
    'S_LiaisonTacheMoyen',
    'S_Personnel',
    'S_Engin',

    // Commandes (devis Vente / bons de commande Achat) + lignes
    'S_Com_Commande',
    'S_Com_Commande_Element',
    // Suivi commercial (vente = autorisé sur devis, achat = consommé)
    'S_Com_Suivi',
    'S_Com_Suivi_Element',
    // Facturation client (FA = facture, NC = note de crédit)
    'S_Com_Facturation',
    // Champs de calcul des moyens (pour les décompositions tâche × moyen)
    'S_Moyen_Champs',

    // Pointages personnel
    'P_Planning',
    'P_Planning_Pointage',

    // Pointages matériel + tarifs (pour calculer les coûts)
    'P_Pointage_Materiel',
    'p_pointage_materiel_location',
    'P_Ressource_Prix',
];

# Journal des modifications

Toutes les évolutions notables de Facture PDF Lumia.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [0.2.0] — 2026-08-18

### Lot 2 — Numérotation

#### Ajouté
- `Sequence` : compteur atomique reposant sur `LAST_INSERT_ID()` de MySQL, avec repli `INSERT IGNORE` pour la création de clé et jusqu'à 3 tentatives en cas de course.
- `NumberGenerator` : moteur de tags (`{number}`, `{number:4}`, `{year}`, `{year:2}`, `{month}`, `{day}`, `{order_number}`, `{order_id}`, `{customer_id}`), validation de format et aperçu sans consommation.
- `NumberStore` : attribution définitive, verrou applicatif par commande, écriture des metas et note de commande.
- Onglet Numérotation : format, premier numéro, remise à zéro annuelle ou mensuelle, aperçu du prochain numéro, table des tags cliquables, état et correction des compteurs.
- `Notices` : file de messages survivant à la redirection, pour remonter les erreurs de validation de format.
- Support d'un rendu libre en fin de section (`after`) dans le générateur de page de réglages.
- Filtres : `fpl_invoice_number`, `fpl_number_tag_value`, `fpl_number_available_tags`, `fpl_sequence_key`, `fpl_should_assign_number`. Action : `fpl_invoice_number_assigned`.

#### Notes
- Un format invalide n'est jamais enregistré : la valeur précédente est conservée et l'erreur remontée.
- Un numéro attribué ne peut plus être modifié. Seul le compteur est corrigeable, sous confirmation.

## [0.1.0] — 2026-08-18

### Lot 1 — Socle

#### Ajouté
- Amorçage du plugin, constantes, autoloader PSR-4 avec repli sans Composer.
- Vérification des prérequis (PHP 8.1, WP 6.4, WooCommerce 8.0, extensions gd/mbstring/dom).
- Déclaration de compatibilité HPOS et blocs Panier/Commande.
- Installateur : table `fpl_sequences`, dossier de stockage protégé, tâche cron de purge.
- Schéma de réglages déclaratif (onglets Général, Entreprise, Design, Outils).
- Dépôt de réglages avec fusion des valeurs par défaut et nettoyage typé.
- Migrateur de schéma pour les futures évolutions de structure.
- Abstraction `Document` et registre, avec la facture comme seule implémentation.
- Page d'administration générée depuis le schéma, avec rendu de champs mutualisé.
- Onglet Outils : diagnostic d'environnement et réinitialisation des réglages.
- Journalisation branchée sur WC_Logger, conditionnée au mode débogage.

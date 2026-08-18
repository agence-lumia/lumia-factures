# Journal des modifications

Toutes les évolutions notables de Facture PDF Lumia.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

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

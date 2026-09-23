## [1.1.1] — 2026-09-23

### Montants des commandes remisées

#### Corrigé
- Les lignes de produits affichent le prix **avant remise**, comme le sous-total. Une commande avec code promo déduisait la remise deux fois à la lecture : le prix unitaire et le total de la ligne étaient déjà remisés, puis la ligne « Remise » retirait le même montant du sous-total. Le prix unitaire correspond de nouveau au prix de la boutique et la somme des lignes est égale au sous-total.
- Le sous-total TTC ne mélange plus une base HT d'avant remise avec une TVA d'après remise. Il utilise la TVA de sous-total des lignes de produits, au lieu de la TVA totale de la commande, qui englobait aussi la livraison et les frais. Sur une commande remisée, le bloc des totaux ne retombait pas sur le total de la commande.
- Les montants de la commande de démonstration de l'aperçu étaient incohérents (même TVA déclarée avant et après remise, total annoncé supérieur à la somme des lignes) et masquaient l'écart des totaux.

#### Vérifié
- Le contrôle de syntaxe PHP passe sur l'ensemble du plugin.
- Blocs de totaux recalculés sur des commandes réelles avec remise, sans remise, à plusieurs taux de TVA, ainsi que sur l'aperçu de démonstration : la somme des lignes, la remise, la livraison et les frais retombent sur le total WooCommerce.

## [1.1.0] — 2026-09-18

### Codes promo, formats d'image et corrections d'administration

#### Ajouté
- Nouvelle ligne « Code promo » dans le bloc des totaux, qui liste les codes utilisés sur la commande. Elle n'apparaît que si un code a été appliqué et se désactive dans l'onglet Contenu (`total_discount_code_enabled`, activée par défaut).
- Le badge des lignes remisées affiche le code promo de la commande à la place du libellé générique « Remise ». Les modèles personnalisés peuvent l'utiliser via `$item['coupon_code']`.
- La commande de démonstration de l'aperçu contient un code promo (`BIENVENUE10`).
- Prise en charge des images AVIF. Tout format autre que JPEG ou PNG (GIF, WebP, AVIF) est converti avant d'être transmis à Dompdf, et une image impossible à convertir est omise au lieu d'apparaître comme une image cassée.

#### Corrigé
- Les vignettes produit transparentes sont aplaties sur un fond blanc (JPEG), ce qui évite un fond noir dans certaines visionneuses PDF ou à l'impression.
- Un plugin d'optimisation qui impose WebP ou AVIF à toutes les sauvegardes d'images n'empêche plus la conversion en PNG des vignettes.
- Le bouton « Régénérer » de l'encart de commande est un lien et non plus un formulaire imbriqué dans le formulaire d'édition. Il ne soumet plus la commande à la place de la régénération.
- La colonne « Facture » de la liste des commandes HPOS ne déclenche plus l'avertissement WooCommerce sur l'accès direct aux propriétés de la commande.
- Le plugin ne se charge plus deux fois lorsqu'une seconde copie est présente (garde sur `FPL_VERSION`).
- Les URI du plugin et de l'auteur pointent vers https://agence-lumia.com.

#### Vérifié
- Le contrôle de syntaxe PHP passe sur l'ensemble du plugin.

## [1.0.4] — 2026-08-24

### Correctif des images produit dans les PDF

#### Corrigé
- Les vignettes produit redimensionnées sont désormais encodées avec le type MIME du fichier réellement lu. Auparavant, une image JPG redimensionnée était sauvegardée temporairement en PNG mais déclarée `image/jpeg` dans la data-URI, ce qui pouvait conduire Dompdf à ignorer silencieusement l'image.
- Le même mécanisme fiabilise les images WebP lorsqu'elles sont converties en PNG par l'éditeur d'images WordPress.

#### Vérifié
- Une image JPEG redimensionnée en PNG produit maintenant une data-URI `data:image/png;base64,...` cohérente avec ses octets.
- Une image PNG redimensionnée reste correctement déclarée en PNG.
- Le contrôle de syntaxe PHP passe sur l'ensemble du plugin.

## [1.0.3] — 2026-08-24

### Correctif Bricks / page « Voir la commande »

#### Corrigé
- Le dynamic tag Bricks ne confond plus la page « Mon compte » avec une commande. Sur un endpoint WooCommerce tel que `/mon-compte/voir-commande/18324/`, le plugin teste d'abord l'objet transmis par Bricks puis se replie correctement sur l'identifiant de commande de l'endpoint.
- Compatibilité Bricks 2.4 : `bricks/dynamic_data/render_tag` peut transmettre une valeur non textuelle. Le plugin l'ignore désormais proprement au lieu de provoquer `Warning: Array to string conversion`.
- Le shortcode `[facture_pdf_lumia]` utilise le même résolveur de contexte robuste que les dynamic tags, avec prise en charge des endpoints WooCommerce personnalisés.
- L'accès invité sur la page de confirmation transmet désormais la clé de commande au contrôle d'accès avant d'afficher le lien de facture.

#### Vérifié
- Un `WP_Post` représentant la page Mon compte ne bloque plus la résolution de la commande portée par `view-order`.
- Le chemin personnalisé `voir-commande/18324/` est reconnu si les query vars WooCommerce ne sont pas disponibles dans le contexte Bricks.
- Une valeur tableau transmise au filtre Bricks est retournée telle quelle sans conversion ni warning.

## [1.0.2] — 2026-08-24

### Documentation utilisateur

#### Ajouté
- Nouvel onglet **Documentation**, placé en dernière position dans les réglages de Facture PDF Lumia.
- Guide de mise en route en 6 étapes orienté gestionnaire de boutique : statuts facturables, informations d’entreprise, numérotation, contenu, design et aperçu.
- Explication du fonctionnement quotidien depuis WooCommerce → Commandes : générer, télécharger et régénérer une facture.
- Aide sur les pièces jointes des e-mails WooCommerce, l’accès client via le shortcode `[facture_pdf_lumia]` et l’intégration optionnelle avec Bricks Builder.
- Section de dépannage utilisateur avec liens directs vers les onglets concernés et le diagnostic.
- Mise en garde explicite avant la première émission réelle concernant la numérotation définitive des factures.

# Journal des modifications

Toutes les évolutions notables de Facture PDF Lumia.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [1.0.1] — 2026-08-24

### Correctif critique : démarrage de l’extension

#### Corrigé
- Correction de cinq imports de classes manquants dans `Plugin.php` (`Attacher`, `Shortcode`, `DynamicTag`, `GitUpdater`, `OrderMetabox`). Leur instanciation était résolue à tort dans le namespace racine `Lumia\FacturePDF`, provoquant une erreur fatale `Class not found` dès le démarrage du plugin.
- WooCommerce est désormais vérifié également au démarrage normal du plugin, pas seulement à l’activation, afin d’éviter d’enregistrer des services dépendants de WooCommerce lorsque celui-ci est absent ou incompatible.
- Le démarrage est protégé par un garde-fou `Throwable` : une erreur interne est journalisée et signalée dans l’administration au lieu de rendre tout le site inaccessible.

#### Vérifié
- Reproduction du fatal original dans un bootstrap minimal : `Class "Lumia\FacturePDF\Attacher" not found`.
- Bootstrap du plugin réussi après correction des imports.
- Vérification syntaxique PHP de tous les fichiers du plugin hors dépendances tierces.

## [1.0.0] — 2026-08-24

Les neuf lots du cahier des charges sont livrés. Version stable pour un premier déploiement en production.

### Lot 7 — Intégration site complète

#### Ajouté
- `Attacher` : jointe la facture aux emails WooCommerce sélectionnés dans les réglages. Un échec de génération n'empêche jamais l'email de partir — un client qui ne reçoit pas sa confirmation de commande est un problème plus grave qu'une facture manquante.
- `Shortcode` `[facture_pdf_lumia]` : détecte automatiquement la commande depuis le contexte (page de confirmation, « Voir la commande ») si `order_id` n'est pas fourni. Ne rend jamais un bouton mort : chaîne vide si la facture n'est pas disponible.
- `DynamicTag` : trois tags Bricks Builder (`{fpl_invoice_url}`, `{fpl_invoice_number}`, `{fpl_invoice_status}`), utilisables isolément sur un élément ou mêlés à du texte libre. Sans effet si Bricks n'est pas actif.
- `OrderMetabox` : encart sur la fiche commande (numéro, date, état du cache, téléchargement, régénération) et colonne dans la liste des commandes, compatibles HPOS et stockage legacy.

#### Vérifié
- Pièce jointe ajoutée seulement pour les emails configurés, jamais sur une commande non facturable, sans casser l'envoi en cas d'échec.
- Shortcode et dynamic tag : chaîne vide sur commande non facturable, détection de contexte sur la page de confirmation.
- Encart et colonne : rendu correct sur facture émise et non émise.

### Lot 8 — Mentions légales et placeholders

#### Ajouté
- `PlaceholderResolver` : tags `{customer_first_name}`, `{shop_name}`, `{invoice_number}`, `{order_total}`, `{current_year}`… résolus dans le message de remerciement, le pied de page et les mentions légales. Extensible via `fpl_placeholders`.
- Bibliothèque de 10 mentions légales pré-remplies (franchise en base de TVA, pénalités de retard, indemnité forfaitaire, clause de réserve de propriété, droit de rétractation, médiateur de la consommation…), **toutes désactivées par défaut** : une mention légale engage l'entreprise et dépend de sa situation réelle, l'activer à tort serait plus grave qu'un oubli.
- Champ de type `repeater` (nouveau dans le moteur de formulaire) : ajout et suppression de mentions personnalisées en direct, sans limite autre qu'un plafond de sécurité à 100 lignes. Trois emplacements possibles par mention : sous les totaux, bloc dédié en fin de document, ou pied de page.

#### Vérifié
- Résolution correcte des placeholders, y compris à l'intérieur des mentions légales elles-mêmes.
- Sanitisation d'une soumission de répéteur : ligne désactivée absente du rendu, tentative d'injection de script neutralisée.
- Un répéteur vidé de toutes ses lignes vide bien le réglage, plutôt que de conserver les anciennes lignes.
- Rendu PDF réel avec des mentions actives sur les trois emplacements simultanément.

### Lot 9 — Prêt au déploiement multi-sites

#### Ajouté
- `PortManager` : export JSON des réglages, avec possibilité d'exclure les données propres au site (logo, SIRET, adresse, compteur de numérotation en cours). Le logo est transféré en base64 et recréé comme un nouvel attachement local à l'import — un identifiant de média n'a aucun sens d'un site à l'autre.
- Import avec validation (plugin d'origine, version de format, structure) avant toute application, et nettoyage de chaque valeur par le même chemin qu'une soumission de formulaire classique.
- `GitUpdater` : mise à jour depuis les releases d'un dépôt GitHub privé, avec authentification par jeton (constantes `FPL_UPDATE_REPO` et `FPL_UPDATE_TOKEN` dans `wp-config.php`, jamais en base). Repli silencieux si le dépôt est injoignable. Mise en cache de la vérification (12 h) pour ne pas épuiser le quota de l'API.
- Onglet Outils complété : sections Export, Import et Mises à jour.

#### Vérifié
- Cycle complet export → remise à zéro → import : réglages, mentions légales et logo correctement restaurés.
- Import avec et sans les données de site : le second cas laisse bien le SIRET vide plutôt que d'écraser une valeur existante avec du contenu d'un autre site.
- Rejet propre d'un fichier invalide, d'un fichier d'un autre plugin, et d'un format d'export plus récent que celui supporté.
- Nettoyage d'un import contenant une tentative d'injection.
- Mise à jour détectée seulement si une version plus récente existe, servie depuis le cache au second appel, comportement silencieux si le dépôt est injoignable.
- **Le jeton d'authentification n'est jamais transmis à un domaine tiers** — vérifié explicitement sur une requête vers un domaine hors du dépôt configuré.

## [0.6.3] — 2026-08-24

### Correctif critique : champs de saisie invisibles

#### Corrigé
- **Aucun champ de saisie n'apparaissait sous les interrupteurs activés**, dans tous les onglets de réglages (Entreprise, Contenu, Design). L'attribut `x-data` qui donne à Alpine.js sa réactivité sur le formulaire était posé après coup, depuis un gestionnaire jQuery. Or Alpine s'auto-démarre dès que son script (chargé en différé) s'exécute — ce qui arrive dès la fin de l'analyse du document, donc **avant** que jQuery ne déclenche son propre événement « document prêt ». Alpine parcourait systématiquement le formulaire avant que jQuery n'ait eu l'occasion d'y poser l'attribut, ne l'initialisait donc jamais, et la règle CSS qui masque les champs le temps qu'Alpine prenne le relais (`x-cloak`) ne se retirait plus — masquant tous les champs conditionnels en permanence, quel que soit l'état réel des interrupteurs.
- L'état est désormais rendu directement dans le HTML généré par PHP, présent dès le premier chargement de la page plutôt que posé après coup. Cela élimine la course entre les deux scripts et simplifie au passage le JavaScript, qui n'a plus besoin de deviner comment PHP a sérialisé chaque valeur.

#### Vérifié
- Reproduction de la panne avec un navigateur réel (Playwright), en isolant précisément l'ordre de chargement des scripts tel que produit par le plugin : confirmé que l'ancien code laissait le champ masqué (`display: none`, `x-cloak` toujours présent) alors que l'interrupteur associé était bien activé.
- Même scénario après correctif : champ visible (`display: table-row`), `x-cloak` retiré.
- Interaction en direct : décocher puis recocher un interrupteur masque et réaffiche correctement son champ, sans rechargement de page.
- Capture d'écran d'une reproduction fidèle de l'onglet Entreprise confirmant le comportement attendu sur plusieurs champs simultanément.

## [0.6.2] — 2026-08-24

### Correctif : images produit manquantes

#### Corrigé
- **Images produit et logo absents du PDF sur certains sites.** `get_attached_file()` renvoie le chemin que WordPress *croit* correct, sans garantie que le fichier s'y trouve encore : sur un site utilisant un stockage déporté (S3, CDN, synchronisation partielle entre environnements de production et de développement), le fichier local n'existe plus une fois l'image téléversée vers le stockage distant, et la vignette disparaissait silencieusement, sans erreur ni trace dans les journaux.
- `ImageEncoder` récupère maintenant l'image par son URL (`wp_remote_get()`) quand le fichier local est introuvable. L'URL provient de nos propres données d'attachement, jamais d'une saisie utilisateur, donc sans le risque qu'ouvrirait la récupération d'une URL arbitraire. Le résultat est mis en cache par attachement pour la durée de la génération : un même produit apparaissant sur plusieurs lignes ne déclenche qu'une seule requête réseau.
- Chaque échec est désormais journalisé avec sa cause précise (aucune image associée au produit / fichier local introuvable / format non pris en charge / URL distante inaccessible), consultable dans WooCommerce → État → Journaux — jusqu'ici, un échec ne laissait aucune trace exploitable.
- `ContextBuilder::logo()` et `item_image()` délèguent tous deux à ce nouveau point d'entrée unique (`ImageEncoder::for_attachment()`), qui centralise la tentative locale, le repli distant et la journalisation.

#### Infrastructure de test
- Nouveaux scénarios couvrant : fichier local présent (aucun appel réseau), fichier local absent avec repli distant réussi, ni local ni distant (échec propre sans exception), mise en cache (un même attachement demandé trois fois ne déclenche qu'une requête), et attachement sans image.
- PDF de bout en bout avec deux lignes produit — l'une avec image locale, l'autre uniquement accessible à distance — vérifié visuellement.

## [0.6.1] — 2026-08-19

### Correctif critique

#### Corrigé
- **Erreur fatale sur l'aperçu et le PDF de test.** `DemoOrder::add_items()` appelait `set_total_tax()` directement sur les lignes de livraison et de frais. WooCommerce déclare cette méthode en portée protégée sur `WC_Order_Item_Shipping` et `WC_Order_Item_Fee` : elle ne peut être appelée que depuis l'intérieur de la classe elle-même, via `set_taxes()`, qui maintient la cohérence entre le total et le détail par taux utilisé pour les rapports de TVA. Corrigé en passant par `set_taxes()` pour ces deux types de ligne. C'est la cause unique des deux symptômes remontés : l'erreur fatale sur le PDF de test, et l'« Aperçu indisponible » sur l'aperçu HTML — la même erreur y interrompait la réponse AJAX avant qu'elle ne devienne du JSON valide.
- Sur une commande de démonstration (jamais enregistrée, identifiant à 0), la ligne « N° de commande : 0 » s'affichait comme si quelque chose avait échoué. Cette ligne est maintenant omise pour ce cas précis.
- Message d'erreur générique côté aperçu HTML. Une réponse non-JSON (page d'erreur PHP, notice de débogage) faisait échouer silencieusement l'analyse et affichait toujours « Aperçu indisponible », sans indication. La réponse est maintenant lue en texte, la réponse brute journalisée dans la console navigateur en cas d'échec d'analyse, et le message d'erreur réel du serveur affiché quand il est disponible.

#### Infrastructure de test
- Le harnais de test utilisait un stub simplifié de `WC_Order`/`WC_Order_Item_Product` ne reproduisant pas les restrictions de portée réelles de l'API WooCommerce, ce qui a laissé passer ce bug. Un second stub, fidèle à l'API fluide réelle (`add_item()`, `set_billing_*()`, restriction protégée sur `set_total_tax()` des lignes de livraison et de frais), a été ajouté et sert désormais à éprouver `DemoOrder` et le contrôleur d'aperçu de bout en bout — construction de la commande, rendu HTML, génization PDF réelle, et le chemin exact emprunté par la requête AJAX et par `admin-post.php`.
- Correction du même ordre dans le harnais lui-même : `add_filter()`/`apply_filters()` étaient partiellement des bouchons inertes, ce qui masquait un défaut de propagation des réglages non enregistrés vers l'aperçu. Le harnais reproduit maintenant fidèlement le système de hooks.

## [0.6.0] — 2026-08-19

### Lot 6 — Aperçu

#### Ajouté
- **Mode aperçu sur les documents** (`Document::set_preview()`). Un document en aperçu se rend normalement mais ne touche jamais à la numérotation : il n'attribue aucun numéro, n'en consomme aucun dans la séquence et n'écrit aucune meta sur la commande. Sans cette garantie, chaque rafraîchissement de l'aperçu brûlerait un numéro de facture et créerait des trous dans une suite qui doit rester continue.
- `NumberGenerator::preview_next()` : calcule le prochain numéro en lecture seule, sans réserver.
- **Aperçu HTML en direct** : rendu du même gabarit que le PDF dans une iframe, rafraîchi 400 ms après la dernière modification, avec abandon de la requête précédente pour qu'une réponse lente n'écrase jamais un rendu plus récent.
- **Prise en compte des réglages non enregistrés** : les valeurs en cours d'édition sont transmises à l'aperçu et appliquées le temps du rendu via un filtre temporaire, après passage par le nettoyage habituel du dépôt. Régler une couleur ne demande donc plus d'enregistrer à chaque essai.
- **PDF de test** ouvert dans un nouvel onglet, généré par Dompdf, seul rendu faisant foi.
- **Commande de démonstration** (`DemoOrder`) montée en mémoire et jamais enregistrée : deux taux de TVA, remise, frais, adresse de livraison distincte, note client et variation de produit. Un aperçu sur une commande simple ne révèle jamais les problèmes de mise en page.
- Possibilité de prévisualiser **une commande réelle** en saisissant son numéro, y compris une commande dont le statut n'autorise pas encore la facturation.
- Onglet Aperçu dédié dans les réglages.
- Filtre `fpl_demo_tax_totals`.

#### Vérifié
- 20 rafraîchissements d'aperçu consécutifs : compteur de séquence inchangé, aucune meta écrite sur la commande.
- Le numéro affiché en aperçu suit bien le compteur réel (une facture émise, l'aperçu suivant annonce le numéro d'après).
- Aperçu disponible sur une commande au statut non facturable, alors que le document réel reste indisponible.
- Application puis retrait propre d'une surcharge de réglage non enregistrée.
- Aucune régression sur les lots 3, 4 et 5.

## [0.5.0] — 2026-08-19

### Lot 5 — Interface d'administration

#### Ajouté
- **Éditeur riche** : les champs de contenu (message de remerciement, texte de pied de page) passent de la zone de texte brute à `wp_editor()`, avec barre d'outils réduite. L'identifiant est normalisé pour TinyMCE et le champ est volontairement tenu hors de l'état réactif Alpine, les deux se disputant sinon la valeur du textarea.
- **Variations de design** : quatre jeux complets de couleurs et de typographies (Épuré, Classique, Contrasté, Chaleureux), applicables en un clic avec aperçu de la palette. Les valeurs non couvertes par la variation — CSS additionnel, marges, largeur des vignettes, template actif — sont préservées.
- **Navigation interne** entre les sections d'un onglet, affichée à partir de trois sections.
- **Barre d'enregistrement fixe** en bas de l'écran, avec indicateur de modifications non enregistrées et avertissement natif du navigateur avant fermeture. L'onglet Contenu compte 67 champs : un bouton en pied de page imposait de faire défiler toute la page.
- **Indicateur de largeur des colonnes** : somme en direct des largeurs des colonnes actives du tableau produits, avec alerte au-delà de 5 points d'écart avec 100 %.
- Filtre `fpl_design_variations` et action `fpl_variation_applied`.

#### Vérifié
- 134 champs répartis sur 6 onglets, couvrant 10 types de champs, tous rendus sans erreur.
- Application des quatre variations avec génération d'un PDF pour chacune : couleurs, polices et arrondis effectivement répercutés jusqu'au document final.
- Préservation du CSS additionnel, des marges et de la largeur des vignettes après application d'une variation.
- Rejet d'un identifiant de variation inconnu.
- Cohérence entre les polices déclarées par les variations et celles réellement embarquées.

## [0.4.0] — 2026-08-19

### Lot 4 — Stockage, sécurité et purge

#### Ajouté
- `FileStore` : écriture du PDF sous `uploads/facture-pdf-lumia/AAAA/MM/`, nom de fichier suffixé d'un jeton aléatoire de 32 caractères hexadécimaux, protection du dossier par `.htaccess` et `index.php`, garde-fou empêchant toute suppression hors du dossier de stockage.
- `PdfGenerator` : point d'entrée unique pour obtenir un PDF. Sert le cache quand il est valide, régénère sinon, attribue le numéro avant le rendu. `file()` renvoie un chemin (pièces jointes d'email) avec repli sur un fichier temporaire quand le cache est désactivé.
- `AccessGuard` : autorisation par gestionnaire de boutique, par client propriétaire, ou par clé de commande pour les invités (comparaison en temps constant via `hash_equals`). Limitation à 10 générations par minute et par utilisateur ou adresse IP.
- `Cleaner` : purge quotidienne des PDF dépassant la durée de conservation, balayage du système de fichiers plutôt que des metas, suppression des dossiers année/mois devenus vides.
- `DownloadHandler` : endpoint public `?fpl_download=1`, diffusion du binaire par PHP après contrôle (jamais de redirection vers l'URL réelle), en-têtes `nosniff` et `Content-Disposition` configurable.
- API publique : `fpl_has_invoice()`, `fpl_get_invoice_url()`, `fpl_get_invoice_number()`, `fpl_get_invoice_date()`.
- Filtres : `fpl_retention_days`, `fpl_can_access_invoice`, `fpl_download_url`. Actions : `fpl_invoice_generated`, `fpl_files_purged`.

#### Modifié
- **Pied de page fixé en bas de page**, répété sur chaque page.
- Marge basse par défaut portée de 16 à 26 mm pour réserver la hauteur du pied de page, avec une aide explicite dans les réglages.

#### Corrigé
- **La pagination ne s'affichait pas du tout.** Elle reposait sur une règle `@page { @bottom-center }`, or Dompdf n'implémente pas les boîtes de marge de la spécification CSS Paged Media et ignore ces règles silencieusement, sans erreur. Dans un élément positionné, Dompdf résout `counter(page)` mais renvoie 0 pour `counter(pages)`. La pagination est désormais dessinée après le rendu via `page_text()` de l'API canvas, seul moment où le nombre total de pages est connu, avec centrage calculé sur les métriques de la police.

#### Vérifié
- Mise en cache et réutilisation effective, régénération forcée renouvelant le jeton et supprimant l'ancien fichier, numéro de facture inchangé.
- Expiration après la durée configurée, purge cron supprimant les fichiers anciens et les dossiers vides.
- Contrôle d'accès sur huit scénarios : anonyme sans clé, avec bonne clé, avec clé erronée, client propriétaire, autre client connecté, gestionnaire, commande invité face à un client connecté quelconque, et commande non facturable.
- Fonctionnement avec le cache désactivé (rétention à 0) et nettoyage des fichiers temporaires.

## [0.3.1] — 2026-08-19

### Refonte du design et colonne TVA

#### Modifié
- Colonne TVA : le taux seul est affiché, la colonne « Montant TVA » est supprimée. Le détail chiffré de la TVA reste ventilé par taux dans le bloc des totaux, où il a sa place.
- La colonne TVA est désormais active par défaut.
- Mise en page réorganisée : les blocs client, livraison et métadonnées passent sur une seule ligne à trois colonnes au lieu d'être empilés. Une facture courante tient maintenant sur une page au lieu de deux.
- Nouveau bandeau de titre contrasté portant le libellé du document et son numéro, qui sépare l'identité de l'émetteur du corps du document.
- Bloc des totaux présenté en encart, avec la ligne de total en inverse vidéo.
- En-têtes de blocs en petites capitales espacées, filets allégés, interlignage resserré dans le tableau produits.
- Pied de page compacté, séparateurs à puces entre les mentions.

#### Corrigé
- **Taux de TVA erroné.** Le taux était déduit d'une division `taxe / total`, ce qui donnait 16,6 % et 16,7 % là où le taux réel était 20 %, avec en prime deux valeurs différentes pour un même taux à cause des arrondis. Le taux est maintenant lu dans les taxes enregistrées sur la ligne de commande via `WC_Tax::get_rate_percent_value()`, avec repli sur le calcul aligné sur les taux normalisés (20, 10, 5,5, 2,1 %…) pour les commandes importées sans identifiant de taux.
- **Adresses affichées sur une seule ligne** selon le format d'adresse du pays ou l'extension qui filtre la sortie de WooCommerce. Les séparateurs sont désormais normalisés dans `ContextBuilder::format_address()`.
- **Séparateur décimal incohérent** : les totaux affichaient « TVA 5.5% » (point anglo-saxon) tandis que la colonne du tableau affichait « 5,5 % ». Les deux passent maintenant par le même formateur.

## [0.3.0] — 2026-08-18

### Lot 3 — Moteur de rendu

#### Ajouté
- `ContextBuilder` : transforme une commande WooCommerce en données d'affichage — adresses, TVA ventilée par taux réel (`get_tax_totals()`), remboursements, devise de la commande, colonnes et totaux respectant chacun leur interrupteur.
- `TemplateLoader` : cascade thème enfant → thème parent → plugin, repli fichier par fichier vers le template par défaut, détection automatique des templates disponibles.
- `StyleCompiler` : substitution des variables `var(--fpl-*)` en PHP (Dompdf ne les résout pas nativement), pilotée par l'onglet Design.
- `FontLibrary` : 8 polices embarquées (Inter, Source Sans 3, Lato, Open Sans, Roboto, IBM Plex Sans, Playfair Display, Merriweather), génération des `@font-face` à la demande.
- `ImageEncoder` : encodage des images produit et du logo en base64 après redimensionnement, rejet propre du SVG et des fichiers illisibles.
- `Renderer` : assemble le document HTML complet, gère la pagination via une règle `@page` dédiée.
- `PdfEngine` : intégration de Dompdf 3.x, accès réseau distant désactivé (images déjà en base64), relève la limite mémoire si l'hébergement le permet.
- Template par défaut complet : en-tête, bloc client/livraison, métadonnées, message de remerciement, tableau produits à colonnes dynamiques, totaux, notes, pied de page.
- Onglets Contenu et Design complets dans les réglages, avec toggle indépendant par champ.
- Points d'accroche de rendu : `fpl_before_document`, `fpl_after_header`, `fpl_before_items_table`, `fpl_after_items_table`, `fpl_before_totals`, `fpl_after_totals`, `fpl_before_footer`, `fpl_after_document`.

#### Corrigé
- Les montants renvoyés par `wc_price()` contiennent des entités HTML littérales (`&nbsp;`, `&euro;`) ; `wp_strip_all_tags()` ne les décode pas, ce qui faisait apparaître le texte « &nbsp; » sur le document une fois rééchappé par le template. Décodage ajouté dans `ContextBuilder::price()`.
- Tableau produits : sans `table-layout: fixed`, Dompdf ignore les largeurs de colonnes déclarées en pourcentage dès que le contenu varie fortement, provoquant un texte compressé sur une seule colonne de largeur imprévisible.

#### Vérifié
- Génération réelle d'un PDF via Dompdf (et non une simulation), rendu contrôlé par rasterisation de chaque page.
- Cohérence arithmétique des totaux sur un cas à deux taux de TVA, remise et frais de port.
- Permutation de police (Inter ↔ Playfair Display / Source Sans 3) effective de bout en bout.

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

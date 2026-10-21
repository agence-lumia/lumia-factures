# Lot 4a (2.1) : avoirs, accès client, commande de démo — plan d'implémentation

> **Pour les agents :** sous-skill requise : superpowers:subagent-driven-development (recommandé) ou superpowers:executing-plans. Les étapes utilisent des cases `- [ ]`.

**Objectif :** émettre un avoir numéroté et archivé pour chaque remboursement d'une commande facturée (et permettre d'annuler une facture sans remboursement bancaire), donner au client ses documents (Mon compte, page de remerciement, e-mails, shortcode, Bricks), et afficher un aperçu même sur une boutique sans commande.

**Architecture :** tout reste dans le module verrouillé `Invoices` (écart assumé avec la spec v2, qui prévoyait des modules `CreditNotes` et `CustomerAccess` : documenté dans `docs/modules/invoices.md`). Un avoir est une ligne du registre `type = 'credit_note'`, `order_id` = id du **remboursement**, nouvelle colonne `parent_id` = id de la commande. Le cœur d'émission d'`Issuer` est extrait dans `Documents/Pipeline` et partagé avec `CreditNoteIssuer`. Les montants viennent d'un `CreditNoteStatementBuilder` dont la somme égale exactement le montant remboursé. Côté client, une seule liste en lecture seule (`Front/DocumentList`) alimente toutes les surfaces.

**Stack :** PHP 8.1+, WordPress 6.9+, WooCommerce 9.0+ (HPOS et stockage legacy), Dompdf 3.1, PHPUnit via wp-env, PHPCS + PHPStan niveau 8.

**Spec :** `docs/superpowers/specs/2026-10-19-lumia-factures-v2-design.md` (lot 4). Conceptions détaillées et relues (workflow `wf_c16ac635-cf1`, 2026-10-20) résumées ici ; recherche juridique : BOI-TVA-DECLA-30-20-20-20 (§ 180, 220, 260), CGI art. 242 nonies A ann. II, 272-1, 289-I-5.

## Contraintes globales

- Invariants 2.x (CLAUDE.md) inchangés : document émis immuable ; numérotation continue et unique **par type** ; montants = total WooCommerce (pour un avoir : = montant du remboursement) ; aucune mise à jour ne perd ni ne renumérote ; PHP ≥ 8.1, WP ≥ 6.9, WC ≥ 9.0, HPOS + legacy.
- Code, commentaires, chaînes source en anglais ; le français uniquement dans `languages/lumia-factures-fr_FR.po` (« Credit note » → « Avoir », référence → « Avoir sur la facture n° %1$s du %2$s »).
- Tout changement sous `includes/Modules/Invoices/` vient avec ses tests d'intégration (`npm test` et `npm run test:legacy`).
- Uniquement les API WooCommerce (`wc_get_order`, `wc_get_orders`, `wc_create_refund`, méthodes `WC_Order_Refund`), jamais `wc_orders` / `wp_posts` en direct.
- Hook d'émission : **`woocommerce_order_refunded`** uniquement, jamais `woocommerce_create_refund` ni `woocommerce_refund_created` (WooCommerce supprime le remboursement si la passerelle échoue après ces hooks).
- Formulaires admin : `admin-post.php`, nonce par objet, `manage_woocommerce`, `wp_unslash` + `absint`, notices sur `all_admin_notices`. Design system uniquement, icônes Lucide.
- Vitesse (demande du propriétaire) : pendant une tâche, ne lancer que les fichiers de test touchés (`--filter`) ; les deux suites complètes une seule fois en fin de lot, en arrière-plan, jamais deux suites en parallèle.

## Décisions du propriétaire (2026-10-20)

1. Avoirs : **série séparée** `AV-{year}-{number:5}`, remise à zéro annuelle.
2. Remboursements antérieurs à la 2.1 : **à la demande** (bouton de la commande ou `wp lumia-factures issue-credit-notes --dry-run` puis réel), datés du jour d'émission, la date du remboursement imprimée sur le PDF.
3. Facture d'une commande annulée sans remboursement : **bouton « Annuler la facture »** (remboursement total sans passerelle → avoir).
4. Découpage : **2.1** = ce plan ; **2.2** = TVA (franchise 293 B, n° TVA client, mentions 2026 dont « nature de l'opération », Factur-X / e-reporting), à livrer avant le 1er septembre 2027.

Décisions par défaut (recommandations des conceptions, réversibles) : montants de l'avoir en **positif** (« Total remboursé ») ; e-mail de remboursement : avoir **joint**, lien vers avoir + facture ; remboursement « au montant » : TVA **répartie au prorata** des taux de la commande ; suppression d'un remboursement porteur d'un avoir **bloquée** (filtre pour lever) ; remboursement à 0 € : pas d'avoir ; commande jamais facturée créée avant l'installation de la 2.1 et remboursée : pas d'émission automatique, signalée ; liens Mon compte / remerciement **désactivés à la mise à jour** sur les installations existantes (Neonat les affiche déjà via Bricks), liens dans les e-mails actifs ; pas de bouton avoir dans la liste des commandes de Mon compte ; rien sur la page de remerciement tant que la facture n'existe pas ; message 404 unique « Document not found. » ; démo seulement en repli (aucune commande), 3 derniers produits, « Sample customer ».

## Review Focus

- **Remboursement d'une commande jamais facturée (statut déclencheur `refunded`)** : la facture doit être émise d'abord, puis exactement un avoir, sans double numéro (appel imbriqué `lumia_factures_invoice_issued` → `issue_pending`). Test : `test_full_refund_of_never_invoiced_order_with_refunded_trigger` (tâche 6).
- **E-mail de remboursement envoyé avant `woocommerce_order_refunded`** (et e-mails différés) : un seul avoir, le même numéro joint et lié. Tests : `test_email_issues_credit_note_before_woocommerce_order_refunded_and_hook_reuses_it`, `test_email_links_idempotent_with_attachments` (tâches 7 et 11).
- **Aperçu e-mail WooCommerce (WC 9.6+) avec une commande factice à id non nul** : ne doit jamais numéroter une vraie commande. Test : `test_preview_dummy_order_never_issues` (tâche 11).
- **Id de remboursement réutilisé après suppression** (AUTO_INCREMENT recalculé par MySQL au redémarrage) : jamais de reprise de l'ancien avoir. Test : `test_reused_refund_id_with_other_parent_is_refused_never_returns_stale_row` (tâche 6).
- **Accès croisé** : un id de remboursement passé comme facture, un id de commande passé comme avoir, la clé d'une autre commande → 404 identique. Tests tâche 9.

---

## Partie A — Avoirs

### Tâche 1 : schéma v2 (`parent_id`), Register, IssuedDocument

**Fichiers :** `Database/Installer.php`, `Documents/Register.php`, `Documents/IssuedDocument.php`, `docs/core.md` ; tests `DatabaseTest.php`, `RegisterTest.php`.

**Interfaces produites :**
- `Installer::DB_VERSION = 2` ; `Installer::documents_schema( string $table ): string` (le `CREATE TABLE` exact passé à dbDelta) ; colonne `parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0` après `order_id`, `KEY parent_id (parent_id)` ; clés uniques `type_order` et `type_number` inchangées. La version n'est enregistrée qu'après `SHOW COLUMNS ... LIKE 'parent_id'`.
- `Register::reserve( string $type, int $order_id, int $parent_id = 0 ): ?int` ; `Register::for_parent( string $type, int $parent_id ): array<int, IssuedDocument>` (ORDER BY id) ; `Register::number_exists_in_other_type( string $type, string $number ): bool`.
- `IssuedDocument` : nouveau dernier paramètre `public readonly int $parent_id = 0`.

- [ ] Tests d'abord : `DatabaseTest::test_upgrade_from_v1_keeps_issued_rows` sur une **copie nommée à part** (`{prefix}lumia_factures_documents_v1test`, créée en `setUpBeforeClass` avec le DDL v1, supprimée en `tearDownAfterClass` ; jamais d'ALTER sur la vraie table dans la transaction de test) : `parent_id` ajouté à 0, id/number/sequence/issued_at/file/checksum identiques ; `test_documents_schema_matches_install` (capturé par `dbdelta_queries`) ; `test_parent_id_column_and_key_exist` ; `test_version_not_recorded_when_column_missing` ; `RegisterTest::test_reserve_stores_parent_id`, `test_reserve_without_parent_defaults_to_zero`, `test_for_parent_lists_rows_in_id_order`, `test_one_credit_note_per_refund`, `test_number_exists_in_other_type`. Les tests existants (`test_install_is_idempotent`, `test_maybe_upgrade_does_nothing_when_up_to_date`, `test_unique_keys_exist`, `test_attach_file_cannot_repoint_an_issued_invoice`) restent verts.
- [ ] Implémenter, lancer `--filter 'DatabaseTest|RegisterTest'`, commit `feat(invoices): documents schema v2 with parent_id`.

Retour arrière vers 2.0.1 sûr (2.0.1 ne lit que `invoice`, laisse `parent_id` à 0, ne relance pas dbDelta car 2 > 1) : à documenter.

### Tâche 2 : extraire le cœur d'émission (`Pipeline`) — refactor pur + garde inter-types

**Fichiers :** `Documents/Pipeline.php`, `Documents/Notes.php` (nouveaux), `Documents/Issuer.php` ; test `IssuerTest.php`.

**Interfaces :** `Pipeline::reserve( string $type, int $object_id, int $parent_id ): ?int` (libération des réservations périmées, `Issuer::STALE_AFTER`) ; `Pipeline::number( string $type, int $row_id, WC_Order $context, array $numbering, int $now, Notes $notes ): ?array` (boucle de saut `MAX_NUMBER_ATTEMPTS`, libération en cas d'échec) ; `Pipeline::archive( IssuedDocument $doc, callable $render ): string` (`stored` | `store_failed` | `render_failed`) ; `guard()`, `log()`. `Notes` (readonly) porte les textes de notes par type. Nouvelle règle dans `number()` : **avant** `assign()`, si le numéro existe dans un autre type, la ligne est libérée, note + log, arrêt (jamais de saut, qui brûlerait des valeurs du compteur).

- [ ] Tous les tests `IssuerTest`, `SequencesTest`, `RegisterTest` existants passent sans modification ; ajouter `test_invoice_order_notes_unchanged` (textes identiques à 2.0.1) et `test_number_used_by_other_type_is_refused_not_skipped` (compteur avancé d'exactement un).
- [ ] Commit `refactor(invoices): shared issuing pipeline`.

### Tâche 3 : réglages des avoirs, règle de préfixe, compteurs par type

**Fichiers :** `Settings/Schema.php`, `Module.php`, `Numbering/NumberFormat.php`, `Admin/Counters.php`, `templates/admin/invoices/{numbering,general,content}.php`, `assets/admin/js/invoices.js` ; tests `SettingsSchemaTest.php`, `SettingsScreenTest.php`.

**Interfaces :** `Schema::NUMBERED = [ 'invoice' => 'numbering', 'credit_note' => 'credit_notes' ]` ; groupe `credit_notes` : `format` (défaut `AV-{year}-{number:5}`), `start_number` (1..999999999), `reset_enabled` (true), `reset_period` (yearly|monthly), `title` (''), `reason_enabled` (true), `email_attachments` (type `emails`, défaut `['customer_refunded_order']`). **Pas de champ `enabled`** (les avoirs ne se désactivent pas). Règle : les préfixes littéraux (texte avant le premier tag) des deux formats sont non vides, différents, et aucun n'est préfixe de l'autre ; si le format facture commence par un tag, le préfixe avoir doit contenir un caractère non numérique. Sinon seul le groupe `credit_notes` revient en arrière avec « The credit note number format must start with a prefix that differs from the invoice number format, e.g. AV- and FA-. ». `Module::carry_counter( string $type, array $before, array $after )` par type. `get_admin_js_data()` : `documents => [ 'invoice' => [ 'group' => 'numbering' ], 'credit_note' => [ 'group' => 'credit_notes' ] ]` ; `invoices.js` : `counterKey( type, resetEnabled, period )`, un exemple de numéro par `[data-lumia-factures-sample]`. La liste e-mails de la facture masque `customer_refunded_order` (et `Attachments` l'ignore s'il est stocké).

- [ ] Tests : `test_credit_notes_defaults` (sans clé `enabled`), `test_credit_note_format_validated_independently`, `test_credit_note_format_must_differ_from_invoice_format` (FA-/FA-, FA-{number:6}, FA- vs FA-AV- refusés), `test_credit_note_prefix_needs_non_digit_when_invoice_format_starts_with_tag`, `test_existing_settings_get_credit_note_defaults_without_migration`, `test_refund_emails_hidden_from_invoice_email_list`, `test_carry_counter_runs_per_type`, `SettingsScreenTest::test_numbering_tab_renders_two_samples_and_document_column`.
- [ ] Commit `feat(invoices): credit note settings and numbering`.

### Tâche 4 : `CreditNoteStatementBuilder` et helpers partagés

**Fichiers :** `Amounts/CreditNoteStatementBuilder.php`, `Amounts/Lines.php` (nouveaux), `Amounts/StatementBuilder.php`, `tests/Support/OrderFactory.php` ; tests `CreditNoteStatementTest.php`, `StatementTest.php`.

**Interfaces :** `CreditNoteStatementBuilder::build( WC_Order_Refund $refund, WC_Order $parent, array $settings ): Statement`. Montants **positifs** ; total = `Money::to_minor( $refund->get_amount() )` (= `-to_minor( get_total() )`) ; devise = celle du parent ; lignes de `get_items()`, `get_shipping_methods()`, `get_fees()` en valeur absolue ; pas de lignes remise/coupon ; quantité 0 → cellule vide et prix unitaire = total. Lignes de récap : `subtotal`, `shipping`, une `fee` par frais, puis `adjustment` **signé** « Other refunded amount » si |écart montant − lignes| > 2 centimes, puis `rounding` = total − somme. **Taux et libellés de TVA pris sur les taxes du parent** (rate_id), jamais sur `WC_Tax` actuel ; l'ajustement est réparti sur les taux du parent au prorata de la base TTC par taux (plus forts restes), chaque part découpée HT + TVA ; en mode HT la TVA de l'ajustement rejoint les lignes de TVA. Helpers déplacés dans `Amounts/Lines` (sortie de `StatementBuilder` identique). `OrderFactory::refund( WC_Order $order, array $line_items, ?float $amount, string $reason ): WC_Order_Refund` (`refund_payment` false).

- [ ] Helper `assertAddsUp` : ligne total === `sum()` === `to_minor( get_amount() )` === `-to_minor( get_total() )`, vérifié en modes TTC **et** HT pour chaque cas : `test_full_refund_by_lines`, `test_partial_quantity_refund`, `test_shipping_only_refund`, `test_fee_refund`, `test_amount_only_refund_single_rate`, `test_amount_only_refund_two_rates_allocated_exactly`, `test_negative_adjustment_when_amount_below_line_sum`, `test_adjustment_in_excl_mode_moves_its_vat_to_tax_rows`, `test_gap_of_one_or_two_minor_units_is_rounding_not_adjustment`, `test_gap_above_two_is_adjustment`, `test_rate_taken_from_parent_after_tax_rate_changed_or_deleted`, `test_no_discount_or_coupon_rows_on_credit_note`, `test_untaxed_parent_shows_no_vat`, `test_tax_only_line_refund`, `test_statement_currency_is_parent_currency`, `test_sum_of_credit_notes_equals_total_refunded` (trois remboursements partiels). `StatementTest` inchangé et vert.
- [ ] Commit `feat(invoices): credit note statement`.

### Tâche 5 : rendu d'un avoir

**Fichiers :** `Rendering/{DocumentData,Context,Renderer,Placeholders}.php`, `templates/pdf/partials/{header,parties,totals,notes}.php` ; test `RenderingTest.php`.

**Interfaces :** `DocumentData` gagne, en derniers paramètres avec défauts, `string $type = 'invoice'`, `string $reference_number = ''`, `int $reference_date = 0`, `int $refund_date = 0`, `string $reason = ''`. `Context::build( WC_Order $order, DocumentData $doc, array $settings, ?Statement $statement = null )` ; `Renderer::html/pdf` prennent le même `?Statement`. Pour un avoir : titre = `DocumentData::title` sinon `credit_notes.title` sinon « Credit note » ; libellés « Credit note no. », « Credit note date », « Refund date » ; ligne **obligatoire, non désactivable** « Credit note for invoice %1$s of %2$s » (numéro **et** date de la facture, lus dans le registre, donc numéro legacy pour une facture importée) ; « Refund reason » si `reason_enabled` et motif non vide ; parties, paiement et pied de page du parent ; pas de note client ; total « Total refunded ». Les filtres `lumia_factures_invoice_context` / `_html` reçoivent `$doc->type`. Placeholders `{credit_note_number}`, `{credit_note_date}`, `{refund_amount}`, `{refund_reason}` ; sur un avoir `{invoice_number}` / `{invoice_date}` = facture référencée.

- [ ] Tests : `test_credit_note_title_and_meta_labels`, `test_credit_note_always_references_invoice_number_and_date` (quels que soient les toggles), `test_credit_note_references_imported_legacy_invoice_number_and_date`, `test_refund_reason_follows_toggle`, `test_credit_note_parties_come_from_parent_order`, `test_customer_note_not_printed_on_credit_note`, `test_invoice_context_unchanged_with_default_document_data`, `test_invoice_filters_receive_credit_note_type`, `test_credit_note_placeholders_resolve`.
- [ ] Commit `feat(invoices): render credit notes`.

### Tâche 6 : `CreditNoteIssuer` (porte, en attente, garde de réutilisation, hooks, blocage de suppression, balayage)

**Fichiers :** `Documents/CreditNoteIssuer.php` (nouveau), `Module.php`, `Core/Deactivator.php` ; tests `CreditNoteIssuerTest.php`, `tests/Integration/Core/StorageModeTest.php`.

**Interfaces :** `TYPE = 'credit_note'`, `PENDING_META = '_lumia_factures_credit_note_pending'` (raison du refus, écrite une fois par raison) ; `find_for( WC_Order_Refund $refund ): ?IssuedDocument` (ne renvoie la ligne que si `parent_id` = parent du remboursement et, si la méta `_lumia_factures_credit_note_number` existe, si elle égale le numéro) ; `issue( WC_Order_Refund $refund ): ?IssuedDocument` ; `refusal( WC_Order_Refund $refund ): string` ; `restore( IssuedDocument $doc ): bool` ; `issue_pending( WC_Order $order ): array` (par id de remboursement croissant) ; `pending_refund_ids( int $limit ): int[]` (`wc_get_orders` type `shop_order_refund` + `meta_key`) ; callbacks `on_refunded`, `on_invoice_issued`, `on_refund_deleted`, `sweep`. Action `lumia_factures_credit_note_issued( $doc, $refund, $parent )` ; filtre `lumia_factures_can_issue_credit_note( true, $refund, $parent )` (veto seulement, après la porte de migration).

Flux `issue()` : (1) `find_for` → renvoyer si émis ; (2) porte, chaque refus pose `PENDING_META` : parent `WC_Order` ; `Migration::blocks_issuing()` faux et `Issuer::gate_reason( $parent ) !== 'conflict'` ; montant > 0 (sinon rien, **pas** en attente) ; filtre ; facture du parent émise, sinon `Issuer::issue( $parent )` **si** le parent a été créé après `lumia_factures_credit_notes_since`, sinon raison `no_invoice` ; puis `find_for` à nouveau (l'appel imbriqué a pu émettre) ; (3) `Pipeline::reserve( 'credit_note', refund_id, parent_id )` ; (4) `Pipeline::number` avec `Sequences::key( 'credit_note', $now, $settings['credit_notes'] )`, `NumberFormat::build()` sur le **parent** ; (5) métas du remboursement `_lumia_factures_credit_note_number` / `_date`, suppression de `PENDING_META` ; (6) note sur le parent rechargé « Credit note %1$s issued for refund #%2$d (invoice %3$s). » ; (7) rendu depuis un remboursement et un parent rechargés, archive, attach ; (8) action. Étapes 5-8 sous `Pipeline::guard`.

Hooks (`Module::init`) : `woocommerce_order_refunded` (10, 2) → `on_refunded` ; `lumia_factures_invoice_issued` (20, 2) → `on_invoice_issued` → `issue_pending()` ; `woocommerce_refund_deleted` et `woocommerce_delete_order_refund` → `on_refund_deleted` (note seulement : « Refund #%1$d was deleted; credit note %2$s stays issued and the order no longer matches it. ») ; `woocommerce_pre_delete_order_refund` → `false` tant qu'un avoir existe (notice « This refund carries credit note %s and cannot be deleted. »), sauf filtre `lumia_factures_allow_credited_refund_deletion` ; cron quotidien `lumia_factures_credit_notes_sweep` (20 remboursements max, uniquement ceux créés après `lumia_factures_credit_notes_since` ou en attente), désinscrit à la désactivation. Option `lumia_factures_credit_notes_since` (timestamp UTC) posée à l'installation/mise à jour si absente.

- [ ] Tests : `test_refund_of_invoiced_order_issues_credit_note` (AV-AAAA-00001, order_id = remboursement, parent_id = commande, archive vérifiée, métas, note), `test_two_refunds_give_two_consecutive_numbers`, `test_concurrent_refunds_on_two_orders_never_share_a_number`, `test_issue_is_idempotent` (chemin e-mail puis hook puis `on_invoice_issued` → une ligne), `test_archived_pdf_never_changes`, `test_refused_while_migration_blocks_issuing_sets_pending_and_sweep_issues_after`, `test_refused_for_legacy_conflict_order`, `test_refused_without_invoice_when_parent_not_issuable`, `test_order_created_before_since_is_flagged_not_invoiced`, `test_partial_refund_before_invoice_gets_credit_note_when_invoice_issued`, `test_full_refund_of_never_invoiced_order_with_refunded_trigger`, `test_gateway_refund_failure_leaves_no_credit_note` (passerelle de test dont `process_refund` renvoie `WP_Error`), `test_zero_amount_refund_issues_nothing_and_is_not_pending`, `test_filter_can_veto_but_not_bypass_migration_gate`, `test_numbering_skips_numbers_already_issued_and_never_reuses_after_release`, `test_reused_refund_id_with_other_parent_is_refused_never_returns_stale_row`, `test_refund_deletion_blocked_while_credit_note_exists`, `test_deletion_allowed_by_filter_keeps_row_and_file_and_adds_note`, `test_rest_api_refund_issues_credit_note`, `test_restore_rebuilds_missing_file_only_and_refuses_when_refund_deleted_or_reused`, `StorageModeTest::test_credit_note_issued_in_hpos_and_legacy`, `test_pending_refunds_query_works_in_both_storages`.
- [ ] Commit `feat(invoices): issue credit notes for refunds`.

### Tâche 7 : e-mails de remboursement → avoir joint, jamais la facture

**Fichiers :** `Emails/Attachments.php`, `Emails/EmailIds.php` (nouveau, partagé avec la tâche 11) ; test `EmailAttachmentsTest.php`.

**Interfaces :** `EmailIds::normalize( string $id ): string` (`customer_partially_refunded_order` → `customer_refunded_order`). Si `$email->refund` est un `WC_Order_Refund` et l'id normalisé est dans `credit_notes.email_attachments` : `CreditNoteIssuer::issue()` (idempotent) puis pièce jointe de l'archive vérifiée. Sans `$email->refund` : rien, log. La facture n'est jamais jointe à un e-mail de remboursement. Garde aperçu (`woocommerce_is_email_preview`) et commande persistée (clé identique en base) avant toute émission.

- [ ] Tests : `test_full_refund_email_attaches_credit_note`, `test_partial_refund_email_id_normalized_and_attaches_credit_note`, `test_refund_email_never_attaches_invoice_even_if_stored_in_invoice_emails`, `test_refund_email_not_in_credit_note_list_attaches_nothing`, `test_refund_email_without_refund_object_attaches_nothing`, `test_email_issues_credit_note_before_woocommerce_order_refunded_and_hook_reuses_it`, `test_deferred_transactional_emails_still_attach_the_same_credit_note` ; tests de pièces jointes existants verts.
- [ ] Commit `feat(invoices): attach credit notes to refund emails`.

### Tâche 8 : outils de commande, « Annuler la facture », notice en attente, téléchargement admin

**Fichiers :** `Admin/OrderTools.php`, `Admin/Download.php`, `Admin/CreditNoteNotice.php` (nouveau), `Documents/InvoiceCancellation.php` (nouveau) ; test `OrderToolsTest.php`, `InvoiceCancellationTest.php`.

**Interfaces :**
- Meta box : section « Credit notes » listant chaque `get_refunds()` avec son avoir (numéro, date, Télécharger, « Rebuild missing PDF ») ou « Issue credit note » + raison en attente ; lignes de `Register::for_parent` dont le remboursement n'existe plus → « refund deleted ». Actions admin-post `lumia_factures_invoices_issue_credit_note`, `lumia_factures_invoices_restore_credit_note`, `lumia_factures_invoices_download_credit_note` (nonce `..._{refund_id}`, `manage_woocommerce`, l'objet doit être un `WC_Order_Refund` dont le parent est la commande postée ; le téléchargement lit la ligne du registre, donc marche après suppression du remboursement). Colonne de la liste des commandes : numéros d'avoir sous le numéro de facture.
- **`InvoiceCancellation::cancel( WC_Order $order, string $reason ): ?WC_Order_Refund`** : uniquement si la facture est émise, `! $order->is_paid()` (en attente, annulée, échouée, en attente de paiement) et un montant reste remboursable. Crée via `wc_create_refund()` un remboursement total du **reste** (lignes avec quantités/totaux/taxes restants, livraison, frais), `refund_payment => false`, `restock_items => false`, motif « Invoice cancelled » ; l'avoir suit par le flux normal. Bouton « Cancel invoice » dans la meta box avec la modale de confirmation du design system (« No money is moved: this records a full refund and issues a credit note that cancels invoice %s. »), action admin-post `lumia_factures_invoices_cancel_invoice` (nonce par commande, `manage_woocommerce`).
- Notice gestionnaire (`all_admin_notices`) : nombre de remboursements en attente (après `since` ou marqués), bouton admin-post `lumia_factures_invoices_issue_pending_credit_notes`.

- [ ] Tests : `test_meta_box_lists_refunds_with_and_without_credit_note_and_pending_reason`, `test_meta_box_lists_credit_note_of_deleted_refund`, `test_issue_credit_note_action_requires_nonce_and_manage_woocommerce`, `test_issue_credit_note_action_refuses_refund_of_another_order`, `test_restore_credit_note_action_rebuilds_only_missing_file`, `test_download_credit_note_serves_register_file_after_refund_deleted`, `test_pending_notice_counts_only_refunds_after_since_or_flagged_and_button_issues_them`, `test_orders_list_column_shows_credit_note_numbers` ; `InvoiceCancellationTest::test_cancel_unpaid_invoiced_order_issues_full_credit_note` (somme avoir = total facture, aucune passerelle appelée, aucun restockage), `test_cancel_after_partial_refund_credits_the_remainder`, `test_cancel_refused_for_paid_order`, `test_cancel_refused_without_invoice`, `test_cancel_refused_when_nothing_left`, `test_cancel_action_requires_nonce_and_capability`, `test_cancel_twice_issues_one_credit_note`.
- [ ] Commit `feat(invoices): credit note order tools and invoice cancellation`.

### Tâche 9 : endpoint client pour les avoirs

**Fichiers :** `Front/CustomerDownload.php`, `Front/Availability.php` (nouveau) ; tests `Front/CustomerDownloadTest.php`, `Front/AvailabilityTest.php`, `Bricks/TagsTest.php`.

**Interfaces :** `CREDIT_NOTE_VAR = 'lumia_factures_credit_note'` (= id du remboursement) ; précédence `QUERY_VAR`, puis `CREDIT_NOTE_VAR`, puis `fpl_download` (qui reste facture seulement). `locate_document( string $type, int $id, string $key, bool $inline ): array` : pour un avoir, ligne `('credit_note', id)` émise avec `parent_id > 0`, accès décidé sur le **parent du registre** via `can_access( WC_Order $order, ?string $key, string $type = 'invoice' )` (manager, propriétaire ou `hash_equals` sur la clé du parent) ; `lumia_factures_can_access_invoice` (factures seulement, compatibilité) puis nouveau `lumia_factures_can_access_document( $can, $order, $key, $type )` ; fichier vérifié, seul un fichier `NULL` est construit (quota partagé) ; message unique **« Document not found. »**. `url( WC_Order $order, bool $inline = true, bool $with_key = false )`, `credit_note_url( IssuedDocument $doc, WC_Order $parent, bool $inline = true, bool $with_key = false )` + filtre `lumia_factures_customer_credit_note_url`. `Availability::is_available( IssuedDocument $doc ): bool` (émis ET (fichier NULL constructible OU `Archive::verify`)) ; `Bricks\Tags::resolve()` passe dessus.

- [ ] Tests : `test_credit_note_url_uses_refund_id_and_parent_key`, `test_url_with_key_always_carries_key_for_manager`, `test_url_default_omits_key_for_owner`, `test_credit_note_download_with_parent_key_serves_archived_file`, `test_credit_note_download_wrong_key_404`, `test_credit_note_other_customer_logged_in_404`, `test_credit_note_var_with_order_id_404`, `test_invoice_var_with_refund_id_404`, `test_both_vars_present_resolves_invoice_only`, `test_legacy_fpl_download_document_credit_note_404`, `test_credit_note_still_served_after_refund_deleted`, `test_credit_note_recorded_file_damaged_is_never_rebuilt_404`, `test_credit_note_null_file_built_under_rate_limit`, `test_not_found_message_identical_for_both_endpoints`, `test_can_access_document_filter_receives_type`, `test_can_access_invoice_filter_still_applies_to_invoices_only` ; `AvailabilityTest` (vérifié, endommagé, NULL facture, NULL avoir dont le remboursement est supprimé, réservé) ; `TagsTest` existants verts. Les assertions « Invoice not found. » existantes passent à « Document not found. ».
- [ ] Commit `feat(invoices): customer credit note download`.

### Tâche 10 : WP-CLI, désinstallation

**Fichiers :** `Cli/Command.php`, `Module.php` (`get_uninstall_keys`) ; tests `Migration/CliTest.php`, `Core/UninstallerTest.php`.

**Interfaces :** `wp lumia-factures issue-credit-notes [--order=<id>] [--since=<Y-m-d>] [--pending] [--dry-run]` (réel refusé, `blocked`, si les prérequis échouent ou les tables manquent ; le dry-run ne réserve ni ne numérote). Clés de désinstallation : métas `_lumia_factures_credit_note_number`, `_date`, `_pending`, options `lumia_factures_credit_notes_since`, `lumia_factures_invoices_settings_version` (tâche 12), cron `lumia_factures_credit_notes_sweep`.

- [ ] Tests : `test_issue_credit_notes_dry_run_lists_without_reserving_or_numbering`, `test_issue_credit_notes_order_issues_existing_refunds_in_id_order`, `test_issue_credit_notes_refused_while_requirements_fail_or_tables_missing`, `test_issue_credit_notes_pending_and_since_filters`, `UninstallerTest::test_credit_note_meta_option_and_cron_removed_only_with_remove_all_data` (lignes et fichiers gardés par défaut).
- [ ] Commit `feat(invoices): credit notes CLI`.

## Partie B — Accès client

### Tâche 11 : liste en lecture seule, liens dans les e-mails

**Fichiers :** `Front/DocumentList.php`, `Emails/EmailLinks.php` (nouveaux), `Emails/Attachments.php`, `Module.php`, `templates/front/emails/order-documents.php`, `templates/front/emails/plain/order-documents.php` ; tests `Front/DocumentListTest.php`, `EmailLinksTest.php`, `EmailAttachmentsTest.php`.

**Interfaces :** `DocumentList::for_order( WC_Order $order, bool $with_key, ?int $only_refund_id = null ): list<array{type: string, label: string, number: string, date: string, url: string}>` (facture puis avoirs, uniquement les disponibles ; n'appelle **jamais** `Issuer::issue`, `Issuer::can_issue` ni `save()`). `EmailLinks::render( $order, $sent_to_admin = false, $plain_text = false, $email = null )` sur `woocommerce_email_order_meta` (20, 4) : rien si admin, si `$email` n'est pas un `WC_Email`, si remboursement, si l'id normalisé n'est pas dans `customer.email_links`, si aperçu ; sinon `Issuer::issue( $order )` (idempotent, persisté, clé identique en base), puis `CreditNoteIssuer::issue( $email->refund )` pour un e-mail de remboursement, puis la liste avec clé (pour l'e-mail de remboursement : facture + cet avoir). Erreurs attrapées et loguées, l'e-mail part toujours.

- [ ] Tests : `DocumentListTest::test_lists_invoice_then_credit_notes_in_issue_order`, `test_only_refund_id_filters_to_that_credit_note`, `test_excludes_reserved_and_damaged_rows`, `test_includes_null_file_invoice`, `test_never_writes` (notes, métas, date de modification, nombre de lignes du registre inchangés sur une commande éligible non facturée et sur une commande bloquée par la migration), `test_with_key_true_puts_parent_key_in_every_url`, `test_empty_when_nothing_issued` ; `EmailLinksTest::test_processing_email_issues_and_links_invoice_before_status_changed`, `test_email_link_carries_key_when_triggered_by_admin`, `test_sent_to_admin_prints_nothing`, `test_missing_email_argument_prints_nothing`, `test_email_not_in_setting_prints_nothing`, `test_plain_text_outputs_label_colon_url_without_html`, `test_refund_email_links_its_credit_note_issued_lazily`, `test_partial_refund_email_lists_only_that_refund_credit_note`, `test_preview_dummy_order_never_issues`, `test_order_key_mismatch_with_db_never_issues`, `test_email_links_idempotent_with_attachments`, `test_issue_failure_never_breaks_email`.
- [ ] Commit `feat(invoices): document links in customer emails`.

### Tâche 12 : réglages « Accès client », garde de mise à jour, exception de l'import

**Fichiers :** `Settings/Schema.php`, `Settings/Upgrader.php` (nouveau), `Module.php`, `Migration/Importer.php`, `templates/admin/invoices/general.php` ; tests `SettingsSchemaTest.php`, `SettingsUpgraderTest.php`, `Migration/ImporterTest.php`, `SettingsScreenTest.php`.

**Interfaces :** groupe `customer` : `account_orders` (true), `order_details` (true), `email_links` (type `emails`, défaut `['customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_refunded_order']`) ; filtre `lumia_factures_show_customer_links( bool $show, string $placement, WC_Order $order )` (`account_orders` | `order_details` | `email` | `shortcode`). `Upgrader::maybe_upgrade()` appelé **avant** `Installer::install()` dans `on_activate()` et avant `Installer::maybe_upgrade()` dans `Module::init()` : installation existante = option de version DB présente, ou registre non vide, ou migration ≠ `none`, ou données 1.x ; alors seuls `customer.account_orders` et `customer.order_details` passent à false (sauf valeur déjà stockée), écriture du tableau **complet** stocké ; `email_links` intouché ; marqueur `lumia_factures_invoices_settings_version` = 2 dans tous les cas, une seule fois. `Importer::settings()` compare sans le groupe `customer` et conserve le groupe stocké. Section admin « Customer access » (toggles + checklist ; ligne d'aide si `BRICKS_VERSION`).

- [ ] Tests : `test_customer_defaults_all_on`, `test_email_links_rejects_unknown_email_id`, `test_fresh_activation_keeps_links_on`, `test_upgrade_from_2_0_turns_page_links_off_when_db_version_present`, `test_upgrade_turns_page_links_off_when_documents_exist`, `..._when_migration_not_none`, `..._when_legacy_1x_data_present`, `test_upgrade_keeps_email_links_untouched`, `test_upgrade_preserves_every_other_group_byte_for_byte`, `test_upgrade_runs_once`, `test_upgrade_runs_before_installer_in_on_activate`, `test_upgrade_never_touches_register_sequences_or_archive`, `ImporterTest::test_settings_imported_despite_upgrader_customer_group`, `test_import_keeps_stored_customer_group`, rendu et aller-retour de la section dans `SettingsScreenTest`.
- [ ] Commit `feat(invoices): customer access settings`.

### Tâche 13 : Mon compte et page de remerciement

**Fichiers :** `Front/CustomerLinks.php` (nouveau), `Module.php`, `templates/front/order-documents.php` ; test `Front/CustomerLinksTest.php`.

**Interfaces :** filtre `woocommerce_my_account_my_orders_actions` (20, 2) → action `lumia-factures-invoice` (facture seulement, si disponible, aria-label « Download invoice %s ») ; action `woocommerce_order_details_after_order_table` (20) → bloc « Documents » (vue commande classique, remerciement classique, bloc Order Confirmation Totals) gardé par `can_access( $order, OrderContext::current_order_key() )`, dédoublonné par requête ; **pas** de `woocommerce_thankyou`. Gabarit surchargeable `wc_get_template( 'order-documents.php', [...], 'lumia-factures/', LUMIA_FACTURES_TEMPLATES_DIR . 'front/' )`. Rien n'est émis depuis une page.

- [ ] Tests : `test_orders_action_added_when_invoice_available`, `test_orders_action_absent_when_not_issued_damaged_or_setting_off`, `test_details_block_lists_invoice_and_credit_notes`, `test_details_block_hidden_for_guest_without_or_with_wrong_key`, `test_details_block_guest_with_valid_key_on_order_received`, `test_details_block_hidden_on_order_tracking_page`, `test_details_block_rendered_once_per_order`, `test_no_thankyou_hook_registered`, `test_render_never_issues`, `test_block_order_confirmation_totals_renders_documents`, `test_show_customer_links_filter_can_hide_each_placement`, `test_template_overridable_from_theme_path`.
- [ ] Commit `feat(invoices): documents in My Account and thank-you page`.

### Tâche 14 : shortcode documents et balises Bricks

**Fichiers :** `Front/Shortcode.php`, `Modules/Bricks/Tags.php`, `docs/modules/bricks.md` ; tests `Front/ShortcodeTest.php`, `Bricks/TagsTest.php`.

**Interfaces :** `[lumia_factures_documents order_id=""]` (même garde d'accès ; un `order_id` explicite ne contourne jamais `can_access`). Bricks : `{lumia_factures_credit_note_url|number|status}` (dernier avoir disponible ; statut `available` | `none`) et `{lumia_factures_documents}` (HTML de la liste, à placer dans un élément Rich text / Code). Correctif requis : la table des balises devient une map nom complet → résolveur, et le court-circuit de `render_content()` porte sur la vraie liste des noms. Pas de nouvel alias `fpl_`.

- [ ] Tests : `test_documents_shortcode_renders_list_for_owner`, `test_documents_shortcode_empty_without_access_even_with_explicit_order_id`, `test_documents_shortcode_resolves_order_from_endpoint_context`, `test_existing_invoice_shortcodes_unchanged`, `test_new_tags_resolve_inside_content`, `test_credit_note_tags_resolve_latest_available_credit_note`, `test_credit_note_status_none_when_no_credit_note`, `test_documents_tag_renders_list_html`, `test_new_tags_do_not_write`, `test_credit_note_tags_hidden_without_access`, `test_legacy_fpl_tags_unchanged`.
- [ ] Commit `feat(bricks): credit note and documents tags`.

## Partie C — Commande de démo

### Tâche 15 : `DemoOrder` et repli de l'aperçu

**Fichiers :** `Admin/DemoOrder.php` (nouveau), `Admin/Preview.php`, `templates/admin/invoices/preview.php`, `assets/admin/js/invoices.js` ; tests `DemoOrderTest.php`, `PreviewTest.php`.

**Interfaces :** `final class DemoOrder extends WC_Order`, `DemoOrder::build(): self`. Surcharges sans écriture : `save()` → 0, `save_meta_data()` → rien, `delete()` → false, `add_order_note()` → 0, `add_product()` construit l'item en mémoire sans `$item->save()`, `update_status()` / `payment_complete()` → false, `get_order_number()` → '' sans filtre ; `$this->items` préinitialisé (aucun `read_items( 0 )`). Contenu : devise = option `woocommerce_currency` ; adresse de base de la boutique, « Sample customer » / « Sample recipient », `customer@example.com`, sans téléphone ; premier moyen de paiement activé ; jusqu'à 3 derniers produits publiés avec prix (variation : premier enfant publié avec prix), quantités 1/2/1, sinon une ligne « Sample product » à 10 ; taxes via `WC_Tax::find_rates` à l'adresse de base selon `is_taxable()` et la classe, montants en centimes, pas de remise ; livraison 5 HT seulement si livraison active et produit physique, classe de taxe de livraison résolue comme WooCommerce (`inherit` compris) ; un `WC_Order_Item_Tax` par taux ; total = somme exacte (aucune ligne d'arrondi). `Preview` : `source( int $order_id ): WC_Order|WP_Error` (dernière commande **hors `checkout-draft`**, sinon démo ; un id explicite inconnu reste une erreur), `render_order()` sous `try/catch Throwable` pour la démo (erreur `lumia_factures_preview_failed`), réponse AJAX `demo: bool` ; note du volet « No order yet: demo order built from your products and shop address, without order number. Nothing is saved. ».

- [ ] Tests `DemoOrderTest` : `test_build_writes_nothing` (commandes, items, itemmeta, postmeta, wc_orders(_meta), commentaires, séquences, registre inchangés, HPOS et legacy), `test_write_methods_are_noops`, `test_add_product_is_in_memory`, `test_demo_adds_up_incl_and_excl` (20 % et 5,5 %), `test_compound_rate_adds_up`, `test_follows_prices_include_tax_option`, `test_respects_product_tax_status`, `test_without_taxes_has_no_tax_items`, `test_virtual_only_has_no_shipping`, `test_shipping_inherit_class`, `test_zero_decimal_currency`, `test_currency_is_base_option`, `test_without_products_uses_sample_line`, `test_uses_latest_priced_products_and_variations`, `test_ignores_orphan_items_of_order_zero`, `test_customer_is_shop_base_address`, `test_order_number_is_empty_and_unfiltered`. `PreviewTest` : `test_preview_without_orders_renders_demo_order` (remplace `test_preview_without_orders_returns_error`), `test_checkout_draft_is_not_the_latest_order`, `test_demo_preview_has_no_side_effect`, `test_demo_preview_number_is_the_next_sample`, `test_demo_preview_survives_throwing_hook`, `test_handle_reports_demo_flag`, `test_latest_real_order_wins_over_demo` ; tests existants (refus id inconnu / remboursement, nonce et capacité) inchangés.
- [ ] Commit `feat(invoices): demo order for the preview`.

## Partie D — Clôture

### Tâche 16 : docs, traductions, vérification complète, revue

- [ ] `docs/modules/invoices.md` : sections « Credit notes » (modèle de ligne, garde de réutilisation d'id, choix du hook, ordre e-mail/hook, schéma v2 et retour arrière, test de mise à jour sans DDL, TVA du parent, ajustement signé, blocage de suppression, annulation de facture) et « Customer access » (clé dans les URL d'e-mail = jeton permanent, un seul hook de détails, bloc Totals requis côté blocs, rien émis depuis une page, garde aperçu e-mail, normalisation de l'id partiel et effet sur les pièces jointes, désactivation à la mise à jour et exception de l'import, nouveau filtre d'accès) ; pièges de la démo dans « Live preview ». `docs/core.md` : DB_VERSION 2. `CLAUDE.md` : la phrase sur le lot 4 (avoirs et accès client livrés en 2.1, TVA en 2.2), invariants 1 et 2 mentionnent les avoirs.
- [ ] `composer i18n:pot`, traductions françaises dans le `.po`, `i18n:mo`, `i18n:check` ; porte de langue (pas d'accent français hors `languages/`).
- [ ] `composer check`, puis `npm test` puis `npm run test:legacy` (séquentiels, en arrière-plan).
- [ ] Revue indépendante (lecture seule, avec CLAUDE.md) sur toute la branche ; corriger les points bloquants ; PR vers `dev` (`Closes #n`), fusion, issue fermée à la main.

### Tâche 17 : déploiement Neonat (2.1)

- [ ] Fusion `dev` → `main` (refusionner `main` dans `dev` d'abord : conflit de version attendu sur `lumia-factures.php`), `gh workflow run release-please.yml -f bump=minor` → 2.1.0, vérifier le zip.
- [ ] Neonat : sauvegarde DB, mise à jour (purge du cache de l'updater si besoin, puis `chown www-data`), vérifier `parent_id` présent et DB version 2, `migration-status`, liens Mon compte / remerciement désactivés.
- [ ] `wp lumia-factures issue-credit-notes --dry-run` : liste des remboursements existants à transmettre au propriétaire et au comptable ; émission réelle seulement après leur accord.
- [ ] Avec accord du propriétaire : ajouter `{lumia_factures_documents}` (ou les balises avoir) aux modèles Bricks vue commande et remerciement de Neonat ; vérifier que le n° de TVA de Neonat est renseigné (onglet Société) et activer « N° TVA » dans le pied de page (mention obligatoire pour un assujetti).
- [ ] Prochain remboursement réel : vérifier AV-2026-00001, la référence à la facture, l'e-mail.

## Hors périmètre (2.2)

Franchise en base (293 B) avec profil fiscal figé dans le registre au moment de la numérotation ; n° de TVA intracommunautaire client (champ classique et bloc, enregistré au `woocommerce_init` impossible depuis `Module::init` : à prévoir) ; mentions 262 ter I / autoliquidation (option, jamais de décision fiscale) ; mentions 2026 (nature de l'opération, option TVA sur les débits, SIREN client, adresse de livraison) ; Factur-X et export e-reporting B2C, avant le 1er septembre 2027 (calendrier PME). Avoirs : copier le profil fiscal de la facture parente.

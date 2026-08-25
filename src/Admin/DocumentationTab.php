<?php
/**
 * Onglet Documentation.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guide d'utilisation destiné aux gestionnaires de boutique.
 *
 * Cette documentation reste volontairement centrée sur les actions réalisées
 * dans WordPress et WooCommerce. Les hooks, classes PHP et autres points
 * d'extension développeur n'y sont pas documentés.
 */
final class DocumentationTab {

	/**
	 * Rend l'onglet de documentation.
	 */
	public static function render(): void {
		echo '<div class="fpl-docs">';

		self::render_intro();
		self::render_quick_start();
		self::render_daily_use();
		self::render_customer_access();
		self::render_email_help();
		self::render_numbering_warning();
		self::render_troubleshooting();

		echo '</div>';
	}

	/**
	 * Introduction.
	 */
	private static function render_intro(): void {
		printf(
			'<div class="fpl-docs__hero"><div><span class="fpl-docs__eyebrow">%s</span><h2>%s</h2><p>%s</p></div><div class="fpl-docs__hero-badge">%s</div></div>',
			esc_html__( 'Guide utilisateur', 'facture-pdf-lumia' ),
			esc_html__( 'Utiliser Facture PDF Lumia au quotidien', 'facture-pdf-lumia' ),
			esc_html__( 'Ce guide explique les réglages et les actions utiles dans WooCommerce, sans connaissances techniques. Commencez par la mise en route ci-dessous avant d’émettre votre première facture.', 'facture-pdf-lumia' ),
			esc_html__( 'Aucun code requis', 'facture-pdf-lumia' )
		);
	}

	/**
	 * Démarrage rapide.
	 */
	private static function render_quick_start(): void {
		echo '<section class="fpl-docs__section">';
		printf( '<h2>%s</h2>', esc_html__( 'Mise en route en 6 étapes', 'facture-pdf-lumia' ) );
		printf( '<p class="fpl-docs__lead">%s</p>', esc_html__( 'À faire une seule fois lors de l’installation ou lorsque vous reprenez la configuration de votre boutique.', 'facture-pdf-lumia' ) );

		echo '<div class="fpl-docs__steps">';

		self::step(
			'1',
			__( 'Choisir quand une facture peut être créée', 'facture-pdf-lumia' ),
			__( 'Dans Général → Génération, sélectionnez les statuts de commande autorisés. Par défaut, une facture est disponible lorsque la commande est terminée. Tant que la commande n’a pas un statut autorisé, le plugin n’émet pas de facture.', 'facture-pdf-lumia' ),
			'general',
			__( 'Ouvrir Général', 'facture-pdf-lumia' )
		);

		self::step(
			'2',
			__( 'Renseigner votre entreprise', 'facture-pdf-lumia' ),
			__( 'Dans Entreprise, ajoutez votre logo, votre raison sociale, votre adresse et les informations légales que vous souhaitez afficher sur la facture. Utilisez les interrupteurs pour masquer les informations inutiles.', 'facture-pdf-lumia' ),
			'company',
			__( 'Ouvrir Entreprise', 'facture-pdf-lumia' )
		);

		self::step(
			'3',
			__( 'Vérifier la numérotation avant la première facture', 'facture-pdf-lumia' ),
			__( 'Dans Numérotation, contrôlez le format et le premier numéro. Exemple : FA-{year}-{number:4} peut produire FA-2026-0001. Le numéro devient définitif dès la première génération réelle.', 'facture-pdf-lumia' ),
			'numbering',
			__( 'Ouvrir Numérotation', 'facture-pdf-lumia' )
		);

		self::step(
			'4',
			__( 'Choisir les informations affichées', 'facture-pdf-lumia' ),
			__( 'Dans Contenu, choisissez les blocs visibles : adresse client, colonnes produits, totaux, livraison, notes, pied de page et autres informations de la facture.', 'facture-pdf-lumia' ),
			'content',
			__( 'Ouvrir Contenu', 'facture-pdf-lumia' )
		);

		self::step(
			'5',
			__( 'Personnaliser le rendu', 'facture-pdf-lumia' ),
			__( 'Dans Design, adaptez les couleurs, polices, marges et dimensions. Vous pouvez aussi appliquer une variation prête à l’emploi puis l’ajuster.', 'facture-pdf-lumia' ),
			'design',
			__( 'Ouvrir Design', 'facture-pdf-lumia' )
		);

		self::step(
			'6',
			__( 'Tester avant d’utiliser de vraies commandes', 'facture-pdf-lumia' ),
			__( 'Dans Aperçu, vérifiez d’abord le rendu avec la commande de démonstration, puis générez un PDF de test. Vous pouvez également saisir le numéro d’une vraie commande. Les aperçus et PDF de test ne consomment aucun numéro de facture.', 'facture-pdf-lumia' ),
			'preview',
			__( 'Ouvrir Aperçu', 'facture-pdf-lumia' )
		);

		echo '</div></section>';
	}

	/**
	 * Utilisation quotidienne depuis l'administration WooCommerce.
	 */
	private static function render_daily_use(): void {
		echo '<section class="fpl-docs__section">';
		printf( '<h2>%s</h2>', esc_html__( 'Générer et télécharger une facture depuis une commande', 'facture-pdf-lumia' ) );
		printf( '<p>%s</p>', esc_html__( 'Le fonctionnement quotidien se fait directement depuis WooCommerce → Commandes.', 'facture-pdf-lumia' ) );

		echo '<ol class="fpl-docs__ordered">';
		self::ordered_item( __( 'Ouvrez la commande concernée.', 'facture-pdf-lumia' ) );
		self::ordered_item( __( 'Vérifiez que son statut fait partie des statuts autorisés dans l’onglet Général.', 'facture-pdf-lumia' ) );
		self::ordered_item( __( 'Dans l’encart « Facture PDF » de la commande, cliquez sur « Générer maintenant » si aucune facture n’a encore été émise.', 'facture-pdf-lumia' ) );
		self::ordered_item( __( 'Une fois générée, le numéro et la date sont affichés. Utilisez « Télécharger » pour ouvrir le PDF.', 'facture-pdf-lumia' ) );
		self::ordered_item( __( 'Si vous avez modifié le design ou le contenu après la génération, utilisez « Régénérer ». Le PDF est reconstruit mais le numéro de facture déjà attribué reste le même.', 'facture-pdf-lumia' ) );
		echo '</ol>';

		printf(
			'<div class="fpl-docs__notice fpl-docs__notice--info"><strong>%s</strong><p>%s</p></div>',
			esc_html__( 'Depuis la liste des commandes', 'facture-pdf-lumia' ),
			esc_html__( 'Une colonne « Facture » affiche un lien PDF lorsqu’une facture existe déjà. C’est le moyen le plus rapide de retrouver une facture sans ouvrir chaque commande.', 'facture-pdf-lumia' )
		);
		echo '</section>';
	}

	/**
	 * Accès côté client.
	 */
	private static function render_customer_access(): void {
		echo '<section class="fpl-docs__section">';
		printf( '<h2>%s</h2>', esc_html__( 'Permettre au client de télécharger sa facture', 'facture-pdf-lumia' ) );
		printf( '<p>%s</p>', esc_html__( 'Si votre thème ou votre constructeur permet d’ajouter un shortcode sur la page de confirmation de commande ou la page « Voir la commande » du compte client, vous pouvez utiliser le shortcode ci-dessous. Le plugin détecte automatiquement la commande affichée et ne montre rien si la facture n’est pas disponible ou si le visiteur n’a pas le droit d’y accéder.', 'facture-pdf-lumia' ) );
		self::copy_code( '[facture_pdf_lumia]' );

		printf( '<h3>%s</h3>', esc_html__( 'Changer le texte du bouton', 'facture-pdf-lumia' ) );
		self::copy_code( '[facture_pdf_lumia label="Télécharger ma facture"]' );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Cette partie est optionnelle : si votre boutique n’utilise pas de zone personnalisée sur ces pages, vous pouvez simplement laisser le téléchargement côté administration et l’envoi par e-mail.', 'facture-pdf-lumia' )
		);

		echo '<details class="fpl-docs__details"><summary>' . esc_html__( 'J’utilise Bricks Builder', 'facture-pdf-lumia' ) . '</summary>';
		printf( '<p>%s</p>', esc_html__( 'Dans Bricks, le groupe « Facture PDF Lumia » fournit des données dynamiques utilisables dans vos éléments. Sur la page « Voir la commande », la commande affichée est détectée automatiquement : vous n’avez pas besoin de renseigner son identifiant.', 'facture-pdf-lumia' ) );
		echo '<ul>';
		printf( '<li><code>{fpl_invoice_url}</code> — %s</li>', esc_html__( 'adresse de téléchargement de la facture', 'facture-pdf-lumia' ) );
		printf( '<li><code>{fpl_invoice_number}</code> — %s</li>', esc_html__( 'numéro de la facture', 'facture-pdf-lumia' ) );
		printf( '<li><code>{fpl_invoice_status}</code> — %s</li>', esc_html__( 'état de disponibilité de la facture : « available », « pending » ou « none »', 'facture-pdf-lumia' ) );
		echo '</ul>';
		printf( '<p>%s</p>', esc_html__( 'Pour créer votre bouton, placez {fpl_invoice_url} dans la valeur dynamique du lien. Si le lien reste vide, affichez temporairement {fpl_invoice_status} : « none » signifie que le statut de la commande n’autorise pas encore la facturation ; vérifiez alors Général → Génération → Statuts autorisés.', 'facture-pdf-lumia' ) );
		echo '</details>';
		echo '</section>';
	}

	/**
	 * Aide pour les pièces jointes d'email.
	 */
	private static function render_email_help(): void {
		echo '<section class="fpl-docs__section">';
		printf( '<h2>%s</h2>', esc_html__( 'Envoyer automatiquement la facture par e-mail', 'facture-pdf-lumia' ) );
		printf( '<p>%s</p>', esc_html__( 'Dans Général → Emails, choisissez les e-mails WooCommerce auxquels la facture doit être jointe. La configuration par défaut cible notamment l’e-mail de commande terminée et l’e-mail de facture client.', 'facture-pdf-lumia' ) );
		printf(
			'<div class="fpl-docs__notice fpl-docs__notice--warning"><strong>%s</strong><p>%s</p></div>',
			esc_html__( 'Le statut de la commande compte au moment de l’envoi', 'facture-pdf-lumia' ),
			esc_html__( 'Si l’e-mail part alors que la commande n’a pas encore un statut autorisé pour la facturation, l’e-mail est envoyé normalement mais sans PDF. Pour joindre la facture, choisissez donc un e-mail déclenché lorsque la commande a déjà atteint un statut facturable.', 'facture-pdf-lumia' )
		);
		printf( '<p><a class="button button-secondary" href="%s">%s</a></p>', esc_url( self::tab_url( 'general' ) ), esc_html__( 'Configurer les e-mails', 'facture-pdf-lumia' ) );
		echo '</section>';
	}

	/**
	 * Avertissement numérotation.
	 */
	private static function render_numbering_warning(): void {
		echo '<section class="fpl-docs__section">';
		printf( '<h2>%s</h2>', esc_html__( 'Important : numérotation des factures', 'facture-pdf-lumia' ) );
		printf(
			'<div class="fpl-docs__notice fpl-docs__notice--warning"><strong>%s</strong><p>%s</p></div>',
			esc_html__( 'Vérifiez la numérotation avant la première émission réelle', 'facture-pdf-lumia' ),
			esc_html__( 'Le premier téléchargement ou la première génération d’une vraie facture attribue définitivement son numéro. Les modifications du format ou du compteur faites ensuite ne renumérotent pas les factures déjà émises.', 'facture-pdf-lumia' )
		);
		printf( '<p>%s</p>', esc_html__( 'Si vous reprenez une numérotation existante, réglez « Premier numéro » et contrôlez les compteurs avant de générer la première facture. Évitez de faire revenir un compteur en arrière : vous pourriez créer des doublons.', 'facture-pdf-lumia' ) );
		printf( '<p><a class="button button-secondary" href="%s">%s</a></p>', esc_url( self::tab_url( 'numbering' ) ), esc_html__( 'Vérifier la numérotation', 'facture-pdf-lumia' ) );
		echo '</section>';
	}

	/**
	 * Dépannage utilisateur.
	 */
	private static function render_troubleshooting(): void {
		echo '<section class="fpl-docs__section">';
		printf( '<h2>%s</h2>', esc_html__( 'Si quelque chose ne fonctionne pas', 'facture-pdf-lumia' ) );

		echo '<div class="fpl-docs__faq">';
		self::faq(
			__( 'Le bouton « Générer maintenant » n’apparaît pas', 'facture-pdf-lumia' ),
			__( 'La commande n’a probablement pas encore un statut autorisé. Ouvrez Général → Génération et comparez les statuts sélectionnés avec le statut actuel de la commande.', 'facture-pdf-lumia' )
		);
		self::faq(
			__( 'La facture n’est pas jointe à l’e-mail', 'facture-pdf-lumia' ),
			__( 'Vérifiez que cet e-mail est sélectionné dans Général → Emails et que la commande avait déjà un statut facturable au moment exact où WooCommerce a envoyé l’e-mail.', 'facture-pdf-lumia' )
		);
		self::faq(
			__( 'Le PDF ne ressemble pas exactement à l’aperçu HTML', 'facture-pdf-lumia' ),
			__( 'Utilisez toujours « Générer un PDF de test » pour valider le résultat final. La pagination, les coupures de page et les polices peuvent différer d’un aperçu affiché dans le navigateur.', 'facture-pdf-lumia' )
		);
		self::faq(
			__( 'Une image produit ou le logo ne s’affiche pas', 'facture-pdf-lumia' ),
			__( 'Pour les produits, vérifiez d’abord que la colonne Image est activée dans Contenu et que la largeur des vignettes dans Design est supérieure à 0. Les images JPG, PNG, GIF et WebP sont prises en charge. Si une facture avait déjà été générée avant une correction ou un changement d’image, utilisez « Régénérer la facture » sur la commande pour remplacer son PDF en cache. En cas d’échec, ouvrez Outils puis consultez WooCommerce → État → Journaux.', 'facture-pdf-lumia' )
		);
		self::faq(
			__( 'La génération du PDF échoue', 'facture-pdf-lumia' ),
			__( 'Ouvrez l’onglet Outils et vérifiez que PHP, WooCommerce, les extensions nécessaires et le dossier de stockage sont indiqués comme opérationnels. Le diagnostic est prévu pour identifier rapidement les problèmes d’environnement.', 'facture-pdf-lumia' )
		);
		echo '</div>';

		printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( self::tab_url( 'tools' ) ), esc_html__( 'Ouvrir le diagnostic', 'facture-pdf-lumia' ) );
		echo '</section>';
	}

	/**
	 * Une carte d'étape.
	 */
	private static function step( string $number, string $title, string $text, string $tab, string $button ): void {
		printf(
			'<article class="fpl-docs__step"><span class="fpl-docs__step-number">%s</span><div><h3>%s</h3><p>%s</p><a href="%s">%s <span aria-hidden="true">→</span></a></div></article>',
			esc_html( $number ),
			esc_html( $title ),
			esc_html( $text ),
			esc_url( self::tab_url( $tab ) ),
			esc_html( $button )
		);
	}

	/**
	 * Élément d'une liste ordonnée.
	 */
	private static function ordered_item( string $text ): void {
		printf( '<li>%s</li>', esc_html( $text ) );
	}

	/**
	 * Bloc de code à copier.
	 */
	private static function copy_code( string $code ): void {
		printf( '<div class="fpl-docs__code"><code>%s</code></div>', esc_html( $code ) );
	}

	/**
	 * Question/réponse de dépannage.
	 */
	private static function faq( string $question, string $answer ): void {
		printf(
			'<details class="fpl-docs__details"><summary>%s</summary><p>%s</p></details>',
			esc_html( $question ),
			esc_html( $answer )
		);
	}

	/**
	 * URL d'un onglet du plugin.
	 */
	private static function tab_url( string $tab ): string {
		return (string) add_query_arg(
			array(
				'page' => SettingsPage::SLUG,
				'tab'  => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);
	}
}

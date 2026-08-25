<?php
/**
 * Intégration Bricks Builder — dynamic tags.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Frontend;

use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Storage\AccessGuard;
use WC_Order;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expose `{fpl_invoice_url}`, `{fpl_invoice_number}` et `{fpl_invoice_status}`
 * dans l'éditeur Bricks.
 *
 * Les hooks sont accrochés sans condition : si Bricks n'est pas actif, les
 * filtres `bricks/*` ne sont simplement jamais déclenchés, donc cette classe
 * n'a aucun effet — pas besoin de vérifier la présence du thème au chargement.
 */
final class DynamicTag {

	/**
	 * Les trois tags exposés, avec leur libellé.
	 *
	 * Une méthode plutôt qu'une constante : une constante de classe ne peut
	 * pas contenir d'appel à `__()`, qui exige une chaîne littérale pour être
	 * repérable par les outils de génération de traductions.
	 *
	 * @return array<string, string>
	 */
	private static function tags(): array {
		return array(
			'fpl_invoice_url'    => __( 'URL de la facture', 'facture-pdf-lumia' ),
			'fpl_invoice_number' => __( 'Numéro de facture', 'facture-pdf-lumia' ),
			'fpl_invoice_status' => __( 'Statut de la facture', 'facture-pdf-lumia' ),
		);
	}

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_filter( 'bricks/dynamic_tags_list', array( $this, 'list_tags' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( $this, 'render_content' ), 20, 2 );
	}

	/**
	 * Déclare les tags dans le panneau Bricks.
	 *
	 * @param array<int, array<string, string>> $tags Tags déjà enregistrés.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function list_tags( array $tags ): array {
		foreach ( self::tags() as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => 'Facture PDF Lumia',
			);
		}

		return $tags;
	}

	/**
	 * Résout un tag isolé, posé seul sur un élément (ex. un bouton).
	 *
	 * @param string           $tag     Tag tel qu'écrit dans Bricks (avec ou sans accolades).
	 * @param WP_Post|WC_Order|mixed $post Objet courant fourni par Bricks.
	 * @param string           $context Contexte de rendu (« text », « media »…).
	 */
	public function render_tag( $tag, $post, string $context = 'text' ) {
		// Bricks 2.4 peut faire passer des valeurs structurées par ce filtre
		// lorsqu'il résout certains contrôles. Elles ne nous appartiennent pas :
		// les convertir en chaîne déclenche « Array to string conversion ».
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$name = trim( $tag, '{}' );

		if ( ! array_key_exists( $name, self::tags() ) ) {
			return $tag;
		}

		return $this->resolve( $name, $post );
	}

	/**
	 * Résout les tags trouvés à l'intérieur d'un contenu plus large.
	 *
	 * @param string                  $content Contenu pouvant contenir un ou plusieurs tags.
	 * @param WP_Post|WC_Order|mixed  $post    Objet courant fourni par Bricks.
	 * @param string                  $context Contexte de rendu.
	 */
	public function render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || ! str_contains( $content, 'fpl_invoice_' ) ) {
			return $content;
		}

		foreach ( array_keys( self::tags() ) as $name ) {
			$content = str_replace( '{' . $name . '}', (string) $this->resolve( $name, $post ), $content );
		}

		return $content;
	}

	/**
	 * Calcule la valeur d'un tag pour l'objet courant.
	 *
	 * @param string                 $name Nom du tag, sans accolades.
	 * @param WP_Post|WC_Order|mixed $post Objet courant fourni par Bricks.
	 */
	private function resolve( string $name, $post ): string {
		$order = $this->resolve_order( $post );

		if ( null === $order ) {
			return '';
		}

		$document = DocumentRegistry::invoice( $order );

		if ( null === $document ) {
			return '';
		}

		switch ( $name ) {
			case 'fpl_invoice_url':
				if ( ! $document->is_available() || ! AccessGuard::can_access( $document, OrderContext::current_order_key() ) ) {
					return '';
				}

				return DownloadHandler::url( $document );

			case 'fpl_invoice_number':
				return $document->get_number();

			case 'fpl_invoice_status':
				if ( $document->exists() ) {
					return 'available';
				}

				return $document->is_available() ? 'pending' : 'none';

			default:
				return '';
		}
	}

	/**
	 * Retrouve la commande WooCommerce à partir de l'objet fourni par Bricks.
	 *
	 * Bricks passe généralement un `WP_Post`, mais peut aussi passer `null`
	 * (élément global, boucle non initialisée) ou un identifiant brut selon
	 * le contexte d'appel. Sans commande identifiable, aucun tag n'a de sens.
	 *
	 * @param WP_Post|WC_Order|int|mixed $post Objet courant.
	 */
	private function resolve_order( $post ): ?WC_Order {
		return OrderContext::resolve( $post );
	}
}

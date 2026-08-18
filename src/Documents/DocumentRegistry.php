<?php
/**
 * Registre des types de documents.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Documents;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fabrique et catalogue les types de documents disponibles.
 */
final class DocumentRegistry {

	/**
	 * Types enregistrés : identifiant => nom de classe.
	 *
	 * @var array<string, class-string<Document>>|null
	 */
	private static ?array $types = null;

	/**
	 * Types disponibles.
	 *
	 * @return array<string, class-string<Document>>
	 */
	public static function types(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = array(
			Invoice::TYPE => Invoice::class,
		);

		/**
		 * Filtre les types de documents enregistrés.
		 *
		 * Point d'extension pour ajouter un avoir, un bon de livraison ou un
		 * document sur mesure. Chaque classe doit étendre Document.
		 *
		 * @param array<string, class-string<Document>> $types Types enregistrés.
		 */
		$filtered = (array) apply_filters( 'fpl_registered_documents', $types );

		self::$types = array_filter(
			$filtered,
			static fn( $class ): bool => is_string( $class ) && is_subclass_of( $class, Document::class )
		);

		return self::$types;
	}

	/**
	 * Instancie un document pour une commande.
	 *
	 * @param string       $type  Type de document.
	 * @param WC_Order|int $order Commande ou identifiant.
	 */
	public static function make( string $type, $order ): ?Document {
		$types = self::types();

		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$class = $types[ $type ];

		return new $class( $order );
	}

	/**
	 * Raccourci pour la facture.
	 *
	 * @param WC_Order|int $order Commande ou identifiant.
	 */
	public static function invoice( $order ): ?Invoice {
		$document = self::make( Invoice::TYPE, $order );

		return $document instanceof Invoice ? $document : null;
	}

	/**
	 * Vide le cache du registre.
	 */
	public static function reset(): void {
		self::$types = null;
	}
}

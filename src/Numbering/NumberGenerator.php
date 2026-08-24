<?php
/**
 * Analyse du format de numérotation et rendu des tags.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Numbering;

use Lumia\FacturePDF\Documents\Document;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforme un format à tags en numéro concret.
 *
 * Le format est saisi librement par l'utilisateur, par exemple
 * `FA-{year}-{number:4}`. Chaque tag accepte un argument optionnel après un
 * deux-points, dont la signification dépend du tag : longueur de remplissage
 * pour `{number}`, nombre de chiffres pour `{year}`.
 */
final class NumberGenerator {

	/**
	 * Expression de détection des tags.
	 */
	private const TAG_PATTERN = '/\{([a-z_]+)(?::([^}]*))?\}/';

	/**
	 * Construit un numéro.
	 *
	 * @param string   $format   Format brut avec tags.
	 * @param int      $sequence Valeur de séquence réservée.
	 * @param WC_Order $order    Commande associée.
	 * @param int|null $time     Horodatage de référence (défaut : maintenant).
	 */
	public static function build( string $format, int $sequence, WC_Order $order, ?int $time = null ): string {
		$time = $time ?? self::now();

		$number = (string) preg_replace_callback(
			self::TAG_PATTERN,
			static function ( array $matches ) use ( $sequence, $order, $time ): string {
				return self::resolve_tag( $matches[1], $matches[2] ?? '', $sequence, $order, $time );
			},
			$format
		);

		/**
		 * Filtre le numéro final.
		 *
		 * @param string   $number   Numéro construit.
		 * @param string   $format   Format d'origine.
		 * @param int      $sequence Valeur de séquence.
		 * @param WC_Order $order    Commande.
		 */
		return (string) apply_filters( 'fpl_invoice_number', $number, $format, $sequence, $order );
	}

	/**
	 * Résout un tag isolé.
	 *
	 * @param string   $tag      Nom du tag.
	 * @param string   $argument Argument après les deux-points.
	 * @param int      $sequence Valeur de séquence.
	 * @param WC_Order $order    Commande.
	 * @param int      $time     Horodatage de référence.
	 */
	private static function resolve_tag( string $tag, string $argument, int $sequence, WC_Order $order, int $time ): string {
		switch ( $tag ) {
			case 'number':
				$padding = '' !== $argument ? absint( $argument ) : 0;

				return $padding > 0
					? str_pad( (string) $sequence, $padding, '0', STR_PAD_LEFT )
					: (string) $sequence;

			case 'year':
				$year = wp_date( 'Y', $time );

				return '2' === $argument ? substr( (string) $year, -2 ) : (string) $year;

			case 'month':
				return (string) wp_date( 'm', $time );

			case 'day':
				return (string) wp_date( 'd', $time );

			case 'order_number':
				return (string) $order->get_order_number();

			case 'order_id':
				return (string) $order->get_id();

			case 'customer_id':
				return (string) $order->get_customer_id();

			default:
				/**
				 * Filtre la valeur d'un tag inconnu du cœur.
				 *
				 * Permet d'ajouter des tags sans modifier le plugin. Retourner
				 * une chaîne vide laisse le tag disparaître du numéro.
				 *
				 * @param string   $value    Valeur (chaîne vide par défaut).
				 * @param string   $tag      Nom du tag.
				 * @param string   $argument Argument éventuel.
				 * @param int      $sequence Valeur de séquence.
				 * @param WC_Order $order    Commande.
				 */
				return (string) apply_filters(
					'fpl_number_tag_value',
					'',
					$tag,
					$argument,
					$sequence,
					$order
				);
		}
	}

	/**
	 * Liste des tags disponibles, pour l'aide contextuelle.
	 *
	 * @return array<string, array{example: string, description: string}>
	 */
	public static function available_tags(): array {
		$time = self::now();

		$tags = array(
			'{number}'       => array(
				'example'     => '42',
				'description' => __( 'Compteur, sans remplissage', 'facture-pdf-lumia' ),
			),
			'{number:4}'     => array(
				'example'     => '0042',
				'description' => __( 'Compteur complété à 4 chiffres', 'facture-pdf-lumia' ),
			),
			'{year}'         => array(
				'example'     => (string) wp_date( 'Y', $time ),
				'description' => __( 'Année sur 4 chiffres', 'facture-pdf-lumia' ),
			),
			'{year:2}'       => array(
				'example'     => substr( (string) wp_date( 'Y', $time ), -2 ),
				'description' => __( 'Année sur 2 chiffres', 'facture-pdf-lumia' ),
			),
			'{month}'        => array(
				'example'     => (string) wp_date( 'm', $time ),
				'description' => __( 'Mois sur 2 chiffres', 'facture-pdf-lumia' ),
			),
			'{day}'          => array(
				'example'     => (string) wp_date( 'd', $time ),
				'description' => __( 'Jour sur 2 chiffres', 'facture-pdf-lumia' ),
			),
			'{order_number}' => array(
				'example'     => '14243',
				'description' => __( 'Numéro de commande WooCommerce', 'facture-pdf-lumia' ),
			),
			'{order_id}'     => array(
				'example'     => '14243',
				'description' => __( 'Identifiant brut de la commande', 'facture-pdf-lumia' ),
			),
			'{customer_id}'  => array(
				'example'     => '27',
				'description' => __( 'Identifiant client, 0 si commande invité', 'facture-pdf-lumia' ),
			),
		);

		/**
		 * Filtre la liste des tags documentés.
		 *
		 * @param array<string, array{example: string, description: string}> $tags Tags.
		 */
		return (array) apply_filters( 'fpl_number_available_tags', $tags );
	}

	/**
	 * Le format est-il exploitable ?
	 *
	 * Un format sans `{number}` produirait le même résultat pour toutes les
	 * commandes d'une même journée. On refuse plutôt que de laisser une
	 * numérotation muette générer des doublons.
	 *
	 * @param string $format Format à contrôler.
	 *
	 * @return true|string Vrai si valide, message d'erreur sinon.
	 */
	public static function validate( string $format ) {
		$format = trim( $format );

		if ( '' === $format ) {
			return __( 'Le format ne peut pas être vide.', 'facture-pdf-lumia' );
		}

		if ( ! preg_match( '/\{number(?::\d+)?\}/', $format ) ) {
			return __( 'Le format doit contenir le tag {number}, sinon plusieurs factures porteraient le même numéro.', 'facture-pdf-lumia' );
		}

		// Un tag mal orthographié serait remplacé par une chaîne vide et
		// passerait inaperçu jusqu'à la première facture émise.
		$known   = array_map(
			static fn( string $tag ): string => trim( strtok( trim( $tag, '{}' ), ':' ) ?: '' ),
			array_keys( self::available_tags() )
		);
		$unknown = array();

		if ( preg_match_all( self::TAG_PATTERN, $format, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( ! in_array( $tag, $known, true ) && ! in_array( '{' . $tag . '}', $unknown, true ) ) {
					$unknown[] = '{' . $tag . '}';
				}
			}
		}

		if ( array() !== $unknown ) {
			return sprintf(
				/* translators: %s: liste des tags inconnus */
				__( 'Tag inconnu : %s. Vérifier l\'orthographe dans la liste des tags disponibles.', 'facture-pdf-lumia' ),
				implode( ', ', $unknown )
			);
		}

		$rendered = preg_replace( self::TAG_PATTERN, '', $format );

		if ( preg_match( '#[/\\\\:*?"<>|]#', (string) $rendered ) ) {
			return __( 'Le format contient un caractère interdit dans un nom de fichier ( / \\ : * ? " < > | ).', 'facture-pdf-lumia' );
		}

		return true;
	}

	/**
	 * Numéro que porterait le prochain document, sans rien réserver.
	 *
	 * Destiné à l'aperçu et à l'administration. Lit le compteur en lecture
	 * seule : appeler cette méthode cent fois ne consomme aucun numéro, à la
	 * différence de `NumberStore::assign()`.
	 *
	 * @param \Lumia\FacturePDF\Documents\Document $document Document concerné.
	 */
	public static function preview_next( $document ): string {
		$settings = \Lumia\FacturePDF\Settings\SettingsRepository::instance();

		$format = (string) $settings->get( 'numbering.format', 'FA-{year}-{number:4}' );
		$start  = (int) $settings->get( 'numbering.start_number', 1 );

		$key     = \Lumia\FacturePDF\Numbering\NumberStore::sequence_key( $document );
		$current = \Lumia\FacturePDF\Numbering\Sequence::current( $key );

		// Une séquence jamais entamée démarre à la valeur configurée ; sinon
		// le prochain numéro est la valeur courante augmentée de un.
		$next = 0 === $current ? max( 1, $start ) : $current + 1;

		return self::build( $format, $next, $document->get_order() );
	}

	/**
	 * Aperçu d'un format, avec un numéro de séquence arbitraire.
	 *
	 * @param string $format   Format à prévisualiser.
	 * @param int    $sequence Numéro de séquence simulé.
	 */
	public static function preview( string $format, int $sequence ): string {
		$time = self::now();

		return (string) preg_replace_callback(
			self::TAG_PATTERN,
			static function ( array $matches ) use ( $sequence, $time ): string {
				$tag      = $matches[1];
				$argument = $matches[2] ?? '';

				switch ( $tag ) {
					case 'number':
						$padding = '' !== $argument ? absint( $argument ) : 0;

						return $padding > 0
							? str_pad( (string) $sequence, $padding, '0', STR_PAD_LEFT )
							: (string) $sequence;

					case 'year':
						$year = (string) wp_date( 'Y', $time );

						return '2' === $argument ? substr( $year, -2 ) : $year;

					case 'month':
						return (string) wp_date( 'm', $time );

					case 'day':
						return (string) wp_date( 'd', $time );

					case 'order_number':
					case 'order_id':
						return '14243';

					case 'customer_id':
						return '27';

					default:
						return '';
				}
			},
			$format
		);
	}

	/**
	 * Horodatage courant, fuseau du site.
	 */
	private static function now(): int {
		return function_exists( 'current_datetime' )
			? current_datetime()->getTimestamp()
			: time();
	}
}

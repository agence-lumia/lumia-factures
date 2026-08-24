<?php
/**
 * Classe abstraite de document.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Documents;

use Lumia\FacturePDF\Numbering\NumberGenerator;
use Lumia\FacturePDF\Numbering\NumberStore;
use Lumia\FacturePDF\Settings\SettingsRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Socle commun à tous les types de documents.
 *
 * Seule la facture est implémentée en v1. Cette abstraction existe pour que
 * l'ajout ultérieur d'un avoir ou d'un bon de livraison ne demande qu'une
 * nouvelle classe et un enregistrement, sans toucher au moteur de rendu ni au
 * stockage.
 */
abstract class Document {

	/**
	 * Commande associée.
	 */
	protected WC_Order $order;

	/**
	 * Dépôt de réglages.
	 */
	protected SettingsRepository $settings;

	/**
	 * Mode aperçu.
	 *
	 * Un document en aperçu se comporte comme un document réel pour le rendu,
	 * mais ne touche jamais à la numérotation : il n'attribue pas de numéro et
	 * n'en consomme pas dans la séquence. Sans cette garantie, chaque
	 * rafraîchissement de l'aperçu — il y en a un par modification de réglage —
	 * brûlerait un numéro de facture, ce qui créerait des trous dans une suite
	 * qui doit rester continue.
	 */
	protected bool $preview = false;

	/**
	 * Constructeur.
	 *
	 * @param WC_Order $order Commande WooCommerce.
	 */
	public function __construct( WC_Order $order ) {
		$this->order    = $order;
		$this->settings = SettingsRepository::instance();
	}

	/**
	 * Identifiant machine du type de document.
	 */
	abstract public function get_type(): string;

	/**
	 * Libellé affiché sur le document.
	 */
	abstract public function get_title(): string;

	/**
	 * Préfixe des metas de commande propres à ce document.
	 */
	abstract protected function meta_prefix(): string;

	/**
	 * Commande associée.
	 */
	public function get_order(): WC_Order {
		return $this->order;
	}

	/**
	 * Bascule le document en mode aperçu.
	 *
	 * @param bool $preview Actif ou non.
	 */
	public function set_preview( bool $preview = true ): self {
		$this->preview = $preview;

		return $this;
	}

	/**
	 * Le document est-il en mode aperçu ?
	 */
	public function is_preview(): bool {
		return $this->preview;
	}

	/**
	 * Le document peut-il être généré pour cette commande ?
	 *
	 * Un document déjà numéroté reste toujours accessible, même si la commande
	 * change de statut par la suite : une facture émise ne se rétracte pas.
	 */
	public function is_available(): bool {
		// Un aperçu doit pouvoir être rendu quel que soit le statut de la
		// commande servant de support, y compris un panier abandonné.
		if ( $this->preview ) {
			return true;
		}

		if ( '' !== $this->get_number() ) {
			return true;
		}

		$available = $this->status_allows_generation();

		/**
		 * Filtre la disponibilité d'un document.
		 *
		 * @param bool     $available Disponibilité calculée.
		 * @param Document $document  Instance du document.
		 * @param WC_Order $order     Commande associée.
		 */
		return (bool) apply_filters( 'fpl_document_is_available', $available, $this, $this->order );
	}

	/**
	 * Le statut de la commande autorise-t-il la génération ?
	 */
	protected function status_allows_generation(): bool {
		$allowed = (array) $this->settings->get( 'general.trigger_statuses', array( 'completed' ) );

		if ( array() === $allowed ) {
			return false;
		}

		return in_array( $this->order->get_status(), $allowed, true );
	}

	/**
	 * Nom complet d'une meta de ce document.
	 *
	 * @param string $suffix Suffixe (`number`, `date`, `sequence`…).
	 */
	public function meta_key( string $suffix ): string {
		return $this->meta_prefix() . $suffix;
	}

	/**
	 * Numéro attribué, ou chaîne vide s'il n'a pas encore été émis.
	 */
	public function get_number(): string {
		$number = (string) $this->order->get_meta( $this->meta_key( NumberStore::META_NUMBER ), true );

		// En aperçu, on montre à quoi ressemblera le prochain numéro sans le
		// réserver : `preview()` lit le compteur sans l'incrémenter.
		if ( '' === $number && $this->preview ) {
			return NumberGenerator::preview_next( $this );
		}

		return $number;
	}

	/**
	 * Valeur brute du compteur, 0 si non émis.
	 */
	public function get_sequence(): int {
		return (int) $this->order->get_meta( $this->meta_key( NumberStore::META_SEQUENCE ), true );
	}

	/**
	 * Date d'émission, ou null.
	 */
	public function get_date(): ?int {
		$timestamp = $this->order->get_meta( $this->meta_key( NumberStore::META_DATE ), true );

		return $timestamp ? (int) $timestamp : null;
	}

	/**
	 * Date d'émission formatée selon les réglages du site.
	 */
	public function get_formatted_date(): string {
		$timestamp = $this->get_date();

		if ( null === $timestamp ) {
			return '';
		}

		return (string) wp_date( (string) get_option( 'date_format', 'd/m/Y' ), $timestamp );
	}

	/**
	 * Attribue un numéro si les conditions sont réunies.
	 *
	 * Point d'entrée unique de la numérotation. Retourne le numéro existant
	 * sans rien consommer si le document est déjà émis.
	 */
	public function assign_number(): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		return NumberStore::assign( $this );
	}

	/**
	 * Le document a-t-il déjà été émis ?
	 */
	public function exists(): bool {
		return '' !== $this->get_number();
	}

	/**
	 * Nom de fichier lisible, sans le hash de sécurité.
	 *
	 * @return string
	 */
	public function get_filename(): string {
		$number = $this->get_number();

		if ( '' === $number ) {
			$number = (string) $this->order->get_order_number();
		}

		$filename = sanitize_file_name(
			sprintf( '%s-%s', $this->get_type(), $number )
		);

		/**
		 * Filtre le nom de fichier du PDF.
		 *
		 * @param string   $filename Nom sans extension.
		 * @param Document $document Instance du document.
		 */
		return (string) apply_filters( 'fpl_pdf_filename', $filename, $this );
	}
}

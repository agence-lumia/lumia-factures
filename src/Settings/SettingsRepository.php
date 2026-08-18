<?php
/**
 * Lecture et écriture des réglages.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accès unifié aux réglages du plugin.
 *
 * Tout est stocké dans une option unique, en autoload : une seule lecture par
 * requête plutôt qu'une trentaine. Les valeurs par défaut du schéma sont
 * fusionnées à la lecture, de sorte qu'un réglage ajouté dans une version
 * ultérieure possède immédiatement sa valeur sans migration.
 */
final class SettingsRepository {

	/**
	 * Nom de l'option WordPress.
	 */
	public const OPTION = 'fpl_settings';

	/**
	 * Instance unique.
	 */
	private static ?self $instance = null;

	/**
	 * Réglages résolus pour la requête courante.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $settings = null;

	/**
	 * Constructeur privé.
	 */
	private function __construct() {}

	/**
	 * Instance unique.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Récupère une valeur.
	 *
	 * @param string $path    Chemin `onglet.champ`, ou `onglet` pour l'onglet entier.
	 * @param mixed  $fallback Valeur de repli si le chemin n'existe pas.
	 *
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$settings = $this->all();
		$segments = explode( '.', $path );
		$current  = $settings;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $fallback;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Raccourci booléen, pour les interrupteurs.
	 *
	 * @param string $path     Chemin du réglage.
	 * @param bool   $fallback Valeur de repli.
	 */
	public function is_enabled( string $path, bool $fallback = false ): bool {
		return (bool) $this->get( $path, $fallback );
	}

	/**
	 * Récupère la valeur d'un champ texte uniquement s'il est activé.
	 *
	 * Évite de répéter partout le couple « champ activé + champ renseigné ».
	 *
	 * @param string $tab   Onglet.
	 * @param string $field Champ.
	 */
	public function get_if_enabled( string $tab, string $field ): string {
		if ( ! $this->is_enabled( $tab . '.' . $field . '_enabled' ) ) {
			return '';
		}

		return trim( (string) $this->get( $tab . '.' . $field, '' ) );
	}

	/**
	 * Réglages complets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = Schema::defaults();
		$merged   = array();

		// Fusion par onglet : un `array_merge_recursive` fusionnerait les
		// tableaux de valeurs (multiselect) au lieu de les remplacer.
		foreach ( $defaults as $tab => $fields ) {
			$merged[ $tab ] = is_array( $stored[ $tab ] ?? null )
				? array_merge( $fields, $stored[ $tab ] )
				: $fields;
		}

		// On conserve les clés stockées absentes du schéma : un réglage ajouté
		// par un filtre tiers ne doit pas disparaître si le filtre est
		// temporairement inactif.
		foreach ( $stored as $tab => $fields ) {
			if ( ! isset( $merged[ $tab ] ) ) {
				$merged[ $tab ] = $fields;
			}
		}

		/**
		 * Filtre les réglages résolus.
		 *
		 * @param array<string, array<string, mixed>> $merged Réglages fusionnés.
		 */
		$this->settings = (array) apply_filters( 'fpl_settings', $merged );

		return $this->settings;
	}

	/**
	 * Enregistre les réglages d'un onglet.
	 *
	 * @param string               $tab   Onglet concerné.
	 * @param array<string, mixed> $input Données brutes issues du formulaire.
	 */
	public function save_tab( string $tab, array $input ): bool {
		$clean    = $this->sanitize_tab( $tab, $input );
		$settings = $this->all();

		$settings[ $tab ] = array_merge( $settings[ $tab ] ?? array(), $clean );

		return $this->save( $settings );
	}

	/**
	 * Écrit l'intégralité des réglages.
	 *
	 * @param array<string, array<string, mixed>> $settings Réglages complets.
	 */
	public function save( array $settings ): bool {
		/**
		 * Filtre les réglages juste avant écriture.
		 *
		 * @param array<string, array<string, mixed>> $settings Réglages.
		 */
		$settings = (array) apply_filters( 'fpl_pre_save_settings', $settings );

		$result = update_option( self::OPTION, $settings, true );

		// `update_option` renvoie false quand la valeur est inchangée, ce qui
		// n'est pas un échec du point de vue de l'appelant.
		$this->settings = null;

		/**
		 * Déclenché après enregistrement des réglages.
		 *
		 * @param array<string, array<string, mixed>> $settings Réglages enregistrés.
		 */
		do_action( 'fpl_settings_saved', $settings );

		return $result || $settings === get_option( self::OPTION );
	}

	/**
	 * Nettoie les données d'un onglet selon le schéma.
	 *
	 * Seuls les champs déclarés au schéma sont conservés : rien d'inconnu ne
	 * peut entrer par le formulaire.
	 *
	 * @param string               $tab   Onglet.
	 * @param array<string, mixed> $input Données brutes.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_tab( string $tab, array $input ): array {
		$schema = Schema::all();
		$clean  = array();

		if ( empty( $schema[ $tab ]['sections'] ) ) {
			return $clean;
		}

		foreach ( $schema[ $tab ]['sections'] as $section ) {
			if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}

			foreach ( $section['fields'] as $key => $field ) {
				$type = $field['type'] ?? 'text';

				// Une case non cochée n'est pas envoyée par le navigateur :
				// son absence vaut « false », pas « inchangé ».
				if ( 'checkbox' === $type ) {
					$clean[ $key ] = ! empty( $input[ $key ] );

					continue;
				}

				if ( ! array_key_exists( $key, $input ) ) {
					continue;
				}

				$clean[ $key ] = $this->sanitize_value( $input[ $key ], $field );
			}
		}

		/**
		 * Filtre les valeurs nettoyées d'un onglet.
		 *
		 * @param array<string, mixed> $clean Valeurs nettoyées.
		 * @param array<string, mixed> $input Valeurs brutes.
		 * @param string               $tab   Onglet concerné.
		 */
		return (array) apply_filters( 'fpl_sanitize_settings_' . $tab, $clean, $input, $tab );
	}

	/**
	 * Nettoie une valeur selon son type déclaré.
	 *
	 * @param mixed                $value Valeur brute.
	 * @param array<string, mixed> $field Définition du champ.
	 *
	 * @return mixed
	 */
	private function sanitize_value( $value, array $field ) {
		if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value, $field );
		}

		$type = $field['type'] ?? 'text';

		switch ( $type ) {
			case 'checkbox':
				return ! empty( $value );

			case 'number':
			case 'range':
				$number = is_numeric( $value ) ? $value + 0 : ( $field['default'] ?? 0 );

				if ( isset( $field['min'] ) ) {
					$number = max( $field['min'], $number );
				}

				if ( isset( $field['max'] ) ) {
					$number = min( $field['max'], $number );
				}

				return is_float( $number ) ? (float) $number : (int) $number;

			case 'media':
				return absint( $value );

			case 'color':
				$color = sanitize_hex_color( (string) $value );

				return $color ?? ( $field['default'] ?? '' );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'richtext':
				return wp_kses_post( (string) $value );

			case 'code':
				// Le CSS additionnel ne doit pas être échappé, mais on retire
				// toute balise pour empêcher l'injection de script.
				return wp_strip_all_tags( (string) $value );

			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( (string) $value );

			case 'select':
				return sanitize_text_field( (string) $value );

			case 'multiselect':
				if ( ! is_array( $value ) ) {
					return array();
				}

				return array_values( array_map( 'sanitize_text_field', $value ) );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Réinitialise tous les réglages aux valeurs par défaut.
	 */
	public function reset(): bool {
		$this->settings = null;

		delete_option( self::OPTION );

		/**
		 * Déclenché après réinitialisation complète.
		 */
		do_action( 'fpl_settings_reset' );

		return true;
	}

	/**
	 * Vide le cache mémoire (utile après une écriture externe).
	 */
	public function flush(): void {
		$this->settings = null;
	}
}

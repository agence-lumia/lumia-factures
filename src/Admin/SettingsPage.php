<?php
/**
 * Page de réglages.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use Lumia\FacturePDF\Settings\Schema;
use Lumia\FacturePDF\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Génère la page d'administration à partir du schéma.
 *
 * Aucun HTML de champ n'est écrit à la main : la structure vient
 * intégralement de Schema, ce qui garantit que l'interface, la validation et
 * l'export restent alignés.
 */
final class SettingsPage {

	/**
	 * Identifiant de la page.
	 */
	public const SLUG = 'fpl-settings';

	/**
	 * Capacité requise.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Ajoute le sous-menu WooCommerce.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Facture PDF Lumia', 'facture-pdf-lumia' ),
			__( 'Facture PDF', 'facture-pdf-lumia' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Onglet actif.
	 */
	private function current_tab(): string {
		$tabs = $this->tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple navigation.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( isset( $tabs[ $requested ] ) ) {
			return $requested;
		}

		return (string) array_key_first( $tabs );
	}

	/**
	 * Onglets triés par priorité.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function tabs(): array {
		$tabs = Schema::all();

		uasort(
			$tabs,
			static fn( array $a, array $b ): int => ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 )
		);

		return $tabs;
	}

	/**
	 * Traite l'enregistrement du formulaire.
	 */
	public function handle_save(): void {
		if ( ! isset( $_POST['fpl_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		$tab = isset( $_POST['fpl_tab'] ) ? sanitize_key( wp_unslash( $_POST['fpl_tab'] ) ) : '';

		check_admin_referer( 'fpl_save_settings_' . $tab, 'fpl_nonce' );

		// Le nettoyage complet est délégué au dépôt, qui filtre chaque valeur
		// selon le type déclaré au schéma. Rien d'inconnu ne peut entrer.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input = isset( $_POST['fpl'] ) && is_array( $_POST['fpl'] ) ? wp_unslash( $_POST['fpl'] ) : array();

		SettingsRepository::instance()->save_tab( $tab, (array) $input );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					'tab'        => $tab,
					'fpl-notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Rend la page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab();
		$tab     = $tabs[ $current ];

		echo '<div class="wrap fpl-wrap">';

		printf(
			'<h1 class="fpl-title">%s <span class="fpl-version">v%s</span></h1>',
			esc_html__( 'Facture PDF Lumia', 'facture-pdf-lumia' ),
			esc_html( FPL_VERSION )
		);

		$this->render_notices();
		$this->render_tab_nav( $tabs, $current );

		// Un onglet peut déléguer entièrement son rendu (cas de l'onglet Outils).
		if ( isset( $tab['render'] ) && is_callable( $tab['render'] ) ) {
			echo '<div class="fpl-panel">';
			call_user_func( $tab['render'] );
			echo '</div>';
			echo '</div>';

			return;
		}

		$this->render_form( $current, $tab );

		echo '</div>';
	}

	/**
	 * Notices de confirmation.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['fpl-notice'] ) ? sanitize_key( wp_unslash( $_GET['fpl-notice'] ) ) : '';

		$messages = array(
			'saved' => __( 'Réglages enregistrés.', 'facture-pdf-lumia' ),
			'reset' => __( 'Réglages réinitialisés.', 'facture-pdf-lumia' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * Navigation par onglets.
	 *
	 * @param array<string, array<string, mixed>> $tabs    Onglets.
	 * @param string                              $current Onglet actif.
	 */
	private function render_tab_nav( array $tabs, string $current ): void {
		echo '<nav class="nav-tab-wrapper fpl-tabs">';

		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => self::SLUG,
							'tab'  => $key,
						),
						admin_url( 'admin.php' )
					)
				),
				$key === $current ? ' nav-tab-active' : '',
				esc_html( $tab['label'] ?? $key )
			);
		}

		echo '</nav>';
	}

	/**
	 * Formulaire d'un onglet.
	 *
	 * @param string               $tab_key Clé d'onglet.
	 * @param array<string, mixed> $tab     Définition de l'onglet.
	 */
	private function render_form( string $tab_key, array $tab ): void {
		$values = (array) SettingsRepository::instance()->get( $tab_key, array() );

		echo '<form method="post" action="" class="fpl-form">';

		wp_nonce_field( 'fpl_save_settings_' . $tab_key, 'fpl_nonce' );

		printf( '<input type="hidden" name="fpl_tab" value="%s">', esc_attr( $tab_key ) );

		if ( empty( $tab['sections'] ) ) {
			printf(
				'<div class="fpl-panel"><p class="fpl-empty">%s</p></div>',
				esc_html__( 'Cette section sera disponible dans une prochaine étape de développement.', 'facture-pdf-lumia' )
			);

			echo '</form>';

			return;
		}

		foreach ( $tab['sections'] as $section ) {
			$this->render_section( $section, $values );
		}

		printf(
			'<p class="fpl-actions"><button type="submit" name="fpl_save_settings" value="1" class="button button-primary button-large">%s</button></p>',
			esc_html__( 'Enregistrer les réglages', 'facture-pdf-lumia' )
		);

		echo '</form>';
	}

	/**
	 * Une section de réglages.
	 *
	 * @param array<string, mixed> $section Définition.
	 * @param array<string, mixed> $values  Valeurs courantes.
	 */
	private function render_section( array $section, array $values ): void {
		echo '<div class="fpl-panel">';

		if ( ! empty( $section['label'] ) ) {
			printf( '<h2 class="fpl-panel__title">%s</h2>', esc_html( $section['label'] ) );
		}

		if ( ! empty( $section['description'] ) ) {
			printf( '<p class="fpl-panel__desc">%s</p>', esc_html( $section['description'] ) );
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( (array) ( $section['fields'] ?? array() ) as $key => $field ) {
			$this->render_field( $key, $field, $values );
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Une ligne de champ.
	 *
	 * @param string               $key    Clé du champ.
	 * @param array<string, mixed> $field  Définition.
	 * @param array<string, mixed> $values Valeurs courantes.
	 */
	private function render_field( string $key, array $field, array $values ): void {
		$value      = $values[ $key ] ?? ( $field['default'] ?? '' );
		$row_class  = 'fpl-row fpl-row--' . sanitize_html_class( $field['type'] ?? 'text' );
		$attributes = '';

		// L'affichage conditionnel est résolu côté navigateur par Alpine :
		// masquer en PHP empêcherait la mise à jour au décochage sans
		// rechargement de page.
		if ( ! empty( $field['depends'] ) && is_array( $field['depends'] ) ) {
			$conditions = array();

			foreach ( $field['depends'] as $dep_key => $dep_value ) {
				$conditions[] = sprintf(
					'(values["%s"] == %s)',
					esc_js( $dep_key ),
					wp_json_encode( $dep_value )
				);
			}

			$attributes .= sprintf( ' x-show=\'%s\' x-cloak', implode( ' && ', $conditions ) );
		}

		printf( '<tr class="%s"%s>', esc_attr( $row_class ), $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<th scope="row"><label for="fpl-%1$s">%2$s</label></th>',
			esc_attr( $key ),
			esc_html( $field['label'] ?? $key )
		);

		echo '<td>';

		FieldRenderer::render( $key, $field, $value );

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $field['description'] ) );
		}

		echo '</td></tr>';
	}
}

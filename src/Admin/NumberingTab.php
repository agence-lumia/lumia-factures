<?php
/**
 * Compléments d'interface de l'onglet Numérotation.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use Lumia\FacturePDF\Numbering\NumberGenerator;
use Lumia\FacturePDF\Numbering\Sequence;
use Lumia\FacturePDF\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aide aux tags, état des compteurs et validation du format.
 */
final class NumberingTab {

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_fpl_update_sequence', array( $this, 'handle_update_sequence' ) );
	}

	/**
	 * Valide et nettoie le format de numérotation.
	 *
	 * Un format invalide n'est jamais enregistré : on conserve la valeur
	 * précédente et on remonte l'erreur à l'utilisateur. Accepter un format
	 * cassé signifierait le découvrir à la première facture émise, quand le
	 * numéro est déjà figé.
	 *
	 * @param mixed                $value Valeur soumise.
	 * @param array<string, mixed> $field Définition du champ.
	 */
	public static function sanitize_format( $value, array $field ): string {
		$format = trim( wp_strip_all_tags( (string) $value ) );
		$check  = NumberGenerator::validate( $format );

		if ( true === $check ) {
			return $format;
		}

		Notices::add_error( (string) $check );

		$previous = (string) SettingsRepository::instance()->get( 'numbering.format', '' );

		return '' !== $previous ? $previous : (string) ( $field['default'] ?? 'FA-{year}-{number:4}' );
	}

	/**
	 * Aperçu du prochain numéro et liste des tags.
	 */
	public static function render_tags(): void {
		$settings = SettingsRepository::instance();
		$format   = (string) $settings->get( 'numbering.format', 'FA-{year}-{number:4}' );
		$start    = (int) $settings->get( 'numbering.start_number', 1 );

		$key      = self::current_sequence_key();
		$current  = Sequence::current( $key );
		$next     = $current > 0 ? $current + 1 : $start;

		printf(
			'<div class="fpl-preview"><span class="fpl-preview__label">%s</span><code class="fpl-preview__value">%s</code></div>',
			esc_html__( 'Prochain numéro qui sera émis', 'facture-pdf-lumia' ),
			esc_html( NumberGenerator::preview( $format, $next ) )
		);

		echo '<table class="widefat striped fpl-tags"><thead><tr>';

		printf( '<th>%s</th>', esc_html__( 'Tag', 'facture-pdf-lumia' ) );
		printf( '<th>%s</th>', esc_html__( 'Exemple', 'facture-pdf-lumia' ) );
		printf( '<th>%s</th>', esc_html__( 'Description', 'facture-pdf-lumia' ) );

		echo '</tr></thead><tbody>';

		foreach ( NumberGenerator::available_tags() as $tag => $info ) {
			printf(
				'<tr><td><code class="fpl-tag" role="button" tabindex="0">%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $tag ),
				esc_html( $info['example'] ),
				esc_html( $info['description'] )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Cliquer sur un tag pour l\'insérer dans le champ Format.', 'facture-pdf-lumia' )
		);
	}

	/**
	 * Tableau des compteurs existants.
	 */
	public static function render_counters(): void {
		$sequences = Sequence::all();

		if ( array() === $sequences ) {
			printf(
				'<p class="fpl-empty">%s</p>',
				esc_html__( 'Aucune facture n\'a encore été émise.', 'facture-pdf-lumia' )
			);

			return;
		}

		echo '<table class="widefat striped fpl-counters"><thead><tr>';

		printf( '<th>%s</th>', esc_html__( 'Séquence', 'facture-pdf-lumia' ) );
		printf( '<th>%s</th>', esc_html__( 'Dernier numéro utilisé', 'facture-pdf-lumia' ) );
		printf( '<th>%s</th>', esc_html__( 'Dernière émission', 'facture-pdf-lumia' ) );
		printf( '<th>%s</th>', esc_html__( 'Corriger', 'facture-pdf-lumia' ) );

		echo '</tr></thead><tbody>';

		foreach ( $sequences as $sequence ) {
			echo '<tr>';

			printf( '<td><code>%s</code></td>', esc_html( $sequence['key'] ) );
			printf( '<td><strong>%d</strong></td>', (int) $sequence['value'] );
			printf( '<td>%s</td>', esc_html( self::format_datetime( $sequence['updated_at'] ) ) );

			echo '<td>';

			printf(
				'<form method="post" action="%s" class="fpl-counter-form" onsubmit="return confirm(\'%s\');">',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_js( __( 'Reprendre la numérotation à une valeur déjà utilisée créerait des doublons. Confirmer la modification ?', 'facture-pdf-lumia' ) )
			);

			wp_nonce_field( 'fpl_update_sequence', 'fpl_sequence_nonce' );

			echo '<input type="hidden" name="action" value="fpl_update_sequence">';

			printf( '<input type="hidden" name="sequence_key" value="%s">', esc_attr( $sequence['key'] ) );

			printf(
				'<input type="number" name="sequence_value" value="%d" min="0" step="1" class="small-text"> <button type="submit" class="button button-small">%s</button>',
				(int) $sequence['value'],
				esc_html__( 'Appliquer', 'facture-pdf-lumia' )
			);

			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Traite la correction manuelle d'un compteur.
	 */
	public function handle_update_sequence(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_update_sequence', 'fpl_sequence_nonce' );

		$key   = isset( $_POST['sequence_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sequence_key'] ) ) : '';
		$value = isset( $_POST['sequence_value'] ) ? absint( wp_unslash( $_POST['sequence_value'] ) ) : 0;

		if ( '' !== $key ) {
			Sequence::set( $key, $value );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => SettingsPage::SLUG,
					'tab'        => 'numbering',
					'fpl-notice' => 'sequence',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Clé de séquence correspondant à la période courante.
	 */
	private static function current_sequence_key(): string {
		$settings = SettingsRepository::instance();
		$key      = 'invoice';

		if ( $settings->is_enabled( 'numbering.reset_enabled' ) ) {
			$period = (string) $settings->get( 'numbering.reset_period', 'yearly' );
			$key   .= 'monthly' === $period
				? '_' . wp_date( 'Y_m' )
				: '_' . wp_date( 'Y' );
		}

		return $key;
	}

	/**
	 * Formate une date SQL UTC dans le fuseau du site.
	 *
	 * @param string $datetime Date au format MySQL.
	 */
	private static function format_datetime( string $datetime ): string {
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '—';
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return (string) wp_date(
			get_option( 'date_format', 'd/m/Y' ) . ' ' . get_option( 'time_format', 'H:i' ),
			$timestamp
		);
	}
}

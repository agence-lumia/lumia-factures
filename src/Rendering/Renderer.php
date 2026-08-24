<?php
/**
 * Assemblage du document HTML complet.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Rendering;

use Lumia\FacturePDF\Documents\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Point d'entrée du rendu : commande → HTML prêt pour Dompdf.
 *
 * Cette classe ne contient aucune logique métier — elle orchestre
 * `ContextBuilder` (les données), `TemplateLoader` (le gabarit) et
 * `StyleCompiler` (l'apparence), puis enveloppe le résultat dans un document
 * HTML complet, avec les polices réellement utilisées déclarées en
 * `@font-face` et la pagination en boîte de marge `@page` si elle est
 * activée.
 */
final class Renderer {

	/**
	 * Rend le HTML complet d'un document.
	 *
	 * @param Document $document Document à rendre.
	 */
	public static function render( Document $document ): string {
		$context = ( new ContextBuilder( $document ) )->build();

		$body = TemplateLoader::render( 'invoice.php', $context );

		if ( '' === trim( $body ) ) {
			return '';
		}

		$css = self::compile_css( $context );

		/**
		 * Filtre le HTML complet juste avant son envoi à Dompdf.
		 *
		 * @param string   $html     Document HTML assemblé.
		 * @param Document $document Document source.
		 */
		return (string) apply_filters(
			'fpl_document_html',
			self::wrap( $body, $css, $context ),
			$document
		);
	}

	/**
	 * Compile la feuille de style complète : variables, template, polices,
	 * pagination, puis CSS additionnel de l'utilisateur en dernier.
	 *
	 * @param array<string, mixed> $context Contexte de rendu.
	 */
	private static function compile_css( array $context ): string {
		$template_css = TemplateLoader::contents( 'style.css' );
		$custom_css   = (string) $context['settings']->get( 'design.custom_css', '' );

		$css = StyleCompiler::compile( $template_css, $custom_css );

		$font_keys = array_filter(
			array(
				(string) $context['settings']->get( 'design.font_heading', 'Inter' ),
				(string) $context['settings']->get( 'design.font_body', 'Inter' ),
			)
		);

		$css = FontLibrary::font_face_css( $font_keys ) . "\n" . $css;

		return $css;
	}

	/**
	 * Enveloppe le corps rendu dans un document HTML valide.
	 *
	 * @param string                $body    HTML du corps, déjà rendu.
	 * @param string                $css     CSS compilé.
	 * @param array<string, mixed>  $context Contexte de rendu.
	 */
	private static function wrap( string $body, string $css, array $context ): string {
		$title = isset( $context['document'] )
			? $context['document']->get_title() . ' ' . $context['document']->get_number()
			: '';

		return sprintf(
			'<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>%1$s</title><style>%2$s</style></head><body>%3$s</body></html>',
			esc_html( trim( $title ) ),
			$css, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, pas du HTML.
			$body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- déjà échappé par les partials.
		);
	}
}

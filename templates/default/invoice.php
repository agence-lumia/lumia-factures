<?php
/**
 * Template par défaut — corps de la facture.
 *
 * Reçoit le contexte assemblé par `ContextBuilder`, extrait par
 * `TemplateLoader::render()`.
 *
 * Organisation : les blocs client, livraison et métadonnées sont regroupés sur
 * une seule ligne à trois colonnes plutôt qu'empilés. Sur une facture
 * courante, cela libère près d'un tiers de la hauteur de la première page et
 * ramène le tableau produits au-dessus de la pliure.
 *
 * @package Lumia\FacturePDF
 *
 * @var \Lumia\FacturePDF\Documents\Document $document
 * @var \WC_Order                            $order
 * @var array<string, mixed>                 $header
 * @var array<int, array<string, string>>    $shop
 * @var array<string, mixed>                 $customer
 * @var array<string, mixed>                 $shipping
 * @var array<int, array<string, string>>    $meta
 * @var array{title: string, body: string}|null $message
 * @var array<int, array<string, mixed>>     $columns
 * @var array<int, array<string, mixed>>     $items
 * @var array<int, array<string, mixed>>     $totals
 * @var array<int, array{title: string, body: string}> $notes
 * @var array<string, mixed>                 $footer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fpl_vars = get_defined_vars();

?>
<?php do_action( 'fpl_before_document', $document, $order ); ?>

<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/header.php', $fpl_vars ); ?>

<?php do_action( 'fpl_after_header', $document, $order ); ?>

<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/parties.php', $fpl_vars ); ?>

<?php if ( null !== $message ) : ?>
	<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/thankyou.php', $fpl_vars ); ?>
<?php endif; ?>

<?php do_action( 'fpl_before_items_table', $document, $order ); ?>

<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/items-table.php', $fpl_vars ); ?>

<?php do_action( 'fpl_after_items_table', $document, $order ); ?>

<?php do_action( 'fpl_before_totals', $document, $order ); ?>

<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/totals.php', $fpl_vars ); ?>

<?php do_action( 'fpl_after_totals', $document, $order ); ?>

<?php if ( array() !== $notes ) : ?>
	<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/notes.php', $fpl_vars ); ?>
<?php endif; ?>

<?php if ( array() !== ( $legal['block'] ?? array() ) ) : ?>
	<?php
	$fpl_legal_placement = 'block';
	echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/legal-mentions.php', get_defined_vars() );
	?>
<?php endif; ?>

<?php do_action( 'fpl_before_footer', $document, $order ); ?>

<?php echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/footer.php', $fpl_vars ); ?>

<?php do_action( 'fpl_after_document', $document, $order ); ?>

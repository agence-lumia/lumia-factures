<?php
/**
 * Notes client et internes.
 *
 * @package Lumia\FacturePDF
 *
 * @var array<int, array{title: string, body: string}> $notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( array() === $notes ) {
	return;
}

?>
<div class="fpl-notes">
	<?php foreach ( $notes as $note ) : ?>
		<div class="fpl-notes__block">
			<h3><?php echo esc_html( $note['title'] ); ?></h3>
			<p><?php echo nl2br( esc_html( $note['body'] ) ); ?></p>
		</div>
	<?php endforeach; ?>
</div>

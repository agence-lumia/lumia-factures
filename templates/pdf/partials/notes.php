<?php
/**
 * Customer note.
 *
 * @package Lumia\Factures
 *
 * @var array<int, array{title: string, body: string}> $notes
 */

defined( 'ABSPATH' ) || exit;

if ( array() === $notes ) {
	return;
}
?>
<div class="lf-notes">
	<?php foreach ( $notes as $note ) : ?>
		<div class="lf-notes__block">
			<h3><?php echo esc_html( $note['title'] ); ?></h3>
			<p><?php echo nl2br( esc_html( $note['body'] ) ); ?></p>
		</div>
	<?php endforeach; ?>
</div>

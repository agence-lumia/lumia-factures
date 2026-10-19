<?php
/**
 * Free message under the parties.
 *
 * @package Lumia\Factures
 *
 * @var array{title: string, body: string}|null $message
 */

defined( 'ABSPATH' ) || exit;

if ( null === $message ) {
	return;
}
?>
<div class="lf-message">
	<?php if ( '' !== $message['title'] ) : ?>
		<h3><?php echo esc_html( $message['title'] ); ?></h3>
	<?php endif; ?>
	<?php if ( '' !== $message['body'] ) : ?>
		<p><?php echo nl2br( esc_html( $message['body'] ) ); ?></p>
	<?php endif; ?>
</div>

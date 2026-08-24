<?php
/**
 * Message de remerciement.
 *
 * @package Lumia\FacturePDF
 *
 * @var array{title: string, body: string}|null $message
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( null === $message ) {
	return;
}

?>
<div class="fpl-thankyou">
	<?php if ( '' !== $message['title'] ) : ?>
		<h3><?php echo esc_html( $message['title'] ); ?></h3>
	<?php endif; ?>
	<?php if ( '' !== $message['body'] ) : ?>
		<p><?php echo wp_kses_post( $message['body'] ); ?></p>
	<?php endif; ?>
</div>

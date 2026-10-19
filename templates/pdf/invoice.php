<?php
/**
 * Invoice document.
 *
 * Included by Renderer with the context keys as variables. Every value is plain text and is
 * escaped where it is printed.
 *
 * @package Lumia\Factures
 *
 * @var string                                   $css        Compiled stylesheet.
 * @var array<string, mixed>                     $header
 * @var array<int, array<string, string>>        $shop
 * @var array<string, mixed>                     $customer
 * @var array<string, mixed>                     $shipping
 * @var array<int, array<string, string>>        $meta
 * @var array{title: string, body: string}|null  $message
 * @var array<int, array<string, mixed>>         $columns
 * @var array<int, array<string, mixed>>         $items
 * @var array<int, array<string, mixed>>         $totals
 * @var array<int, array<string, string>>        $notes
 * @var array<string, mixed>                     $footer
 * @var string                                   $legal_text
 */

use Lumia\Factures\Modules\Invoices\Rendering\Renderer;

defined( 'ABSPATH' ) || exit;

$lumia_factures_vars = get_defined_vars();
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<title><?php echo esc_html( $header['title'] . ( '' !== $header['number'] ? ' ' . $header['number'] : '' ) ); ?></title>
	<style><?php echo wp_strip_all_tags( $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stylesheet compiled from validated settings, tags stripped. ?></style>
</head>
<body>
<?php
Renderer::partial( 'partials/header.php', $lumia_factures_vars );
Renderer::partial( 'partials/parties.php', $lumia_factures_vars );
Renderer::partial( 'partials/message.php', $lumia_factures_vars );
Renderer::partial( 'partials/items.php', $lumia_factures_vars );
Renderer::partial( 'partials/totals.php', $lumia_factures_vars );
Renderer::partial( 'partials/notes.php', $lumia_factures_vars );
Renderer::partial( 'partials/footer.php', $lumia_factures_vars );
?>
</body>
</html>

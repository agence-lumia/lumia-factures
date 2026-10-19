<?php
/**
 * Form fields of the Invoices settings screen.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Settings\Schema;

/**
 * Renders the settings fields with the design-system form classes. Names, values and bounds
 * (min, max, options) come from the Schema; only labels and help texts are written by the templates.
 * Every field posts as `lumia_factures_module_settings[group][key]`.
 */
final class Fields {

	/**
	 * Current settings, keyed by group.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $settings Current settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Field name.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 */
	public static function name( string $group, string $key ): string {
		return 'lumia_factures_module_settings[' . $group . '][' . $key . ']';
	}

	/**
	 * Field id.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 */
	public static function id( string $group, string $key ): string {
		return 'lumia_factures_invoices_' . $group . '_' . $key;
	}

	/**
	 * Current value of a field.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return mixed
	 */
	public function value( string $group, string $key ) {
		return $this->settings[ $group ][ $key ] ?? ( Schema::defaults()[ $group ][ $key ] ?? '' );
	}

	/**
	 * Schema definition of a field.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return array<string, mixed>
	 */
	private function definition( string $group, string $key ): array {
		return Schema::fields()[ $group ][ $key ] ?? [];
	}

	/**
	 * Help paragraph.
	 *
	 * @param string $help Help text, nothing printed when empty.
	 */
	private function help( string $help ): void {
		if ( '' !== $help ) {
			echo '<p class="lumia-form__help">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Toggle row. The hidden `0` makes an unchecked box post a value.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 */
	public function toggle( string $group, string $key, string $label, string $help = '' ): void {
		$id   = self::id( $group, $key );
		$name = self::name( $group, $key );
		?>
		<div class="lumia-form__group lumia-form__group--toggle">
			<div class="lumia-form__toggle-label">
				<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
				<?php $this->help( $help ); ?>
			</div>
			<label class="lumia-toggle">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $this->value( $group, $key ) ) ); ?>>
				<span class="lumia-toggle__slider"></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Single-line text field.
	 *
	 * @param string $group       Group.
	 * @param string $key         Key.
	 * @param string $label       Label.
	 * @param string $help        Help text.
	 * @param string $placeholder Placeholder (the default label, for a label setting stored empty).
	 */
	public function text( string $group, string $key, string $label, string $help = '', string $placeholder = '' ): void {
		$id = self::id( $group, $key );
		?>
		<div class="lumia-form__group">
			<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
			<input type="text" class="lumia-input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>" value="<?php echo esc_attr( (string) $this->value( $group, $key ) ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<?php $this->help( $help ); ?>
		</div>
		<?php
	}

	/**
	 * Multi-line text field.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @param int    $rows  Visible rows.
	 */
	public function textarea( string $group, string $key, string $label, string $help = '', int $rows = 4 ): void {
		$id = self::id( $group, $key );
		?>
		<div class="lumia-form__group">
			<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
			<textarea class="lumia-input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>" rows="<?php echo esc_attr( (string) $rows ); ?>"><?php echo esc_textarea( (string) $this->value( $group, $key ) ); ?></textarea>
			<?php $this->help( $help ); ?>
		</div>
		<?php
	}

	/**
	 * Number field, bounds from the Schema.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 */
	public function number( string $group, string $key, string $label, string $help = '' ): void {
		$id    = self::id( $group, $key );
		$field = $this->definition( $group, $key );
		$float = 'float' === ( $field['type'] ?? 'int' );
		?>
		<div class="lumia-form__group">
			<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
			<input type="number" class="lumia-input lumia-input--sm" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>" value="<?php echo esc_attr( (string) $this->value( $group, $key ) ); ?>" min="<?php echo esc_attr( (string) ( $field['min'] ?? 0 ) ); ?>"<?php echo isset( $field['max'] ) && $field['max'] < PHP_INT_MAX ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : ''; ?> step="<?php echo $float ? 'any' : '1'; ?>">
			<?php $this->help( $help ); ?>
		</div>
		<?php
	}

	/**
	 * Select, options from the Schema.
	 *
	 * @param string                $group   Group.
	 * @param string                $key     Key.
	 * @param string                $label   Label.
	 * @param array<string, string> $choices Option value => label (only the Schema options are accepted on save).
	 * @param string                $help    Help text.
	 */
	public function select( string $group, string $key, string $label, array $choices, string $help = '' ): void {
		$id      = self::id( $group, $key );
		$current = (string) $this->value( $group, $key );
		?>
		<div class="lumia-form__group">
			<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
			<select class="lumia-select lumia-select--sm" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>">
				<?php foreach ( $choices as $value => $text ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php $this->help( $help ); ?>
		</div>
		<?php
	}

	/**
	 * Color field.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param string $label Label.
	 */
	public function color( string $group, string $key, string $label ): void {
		$id    = self::id( $group, $key );
		$value = (string) $this->value( $group, $key );
		?>
		<div class="lumia-form__group">
			<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
			<div class="lumia-inline">
				<input type="color" class="lumia-input lumia-factures-inv-color" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<code class="lumia-factures-inv-color__hex" data-lumia-factures-hex-for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $value ); ?></code>
			</div>
		</div>
		<?php
	}

	/**
	 * List of toggles posting `name[]`. The hidden empty entry comes first so that a form with
	 * every box unchecked still posts the field and saves an empty list (the Schema drops empty strings).
	 *
	 * @param string                $group   Group.
	 * @param string                $key     Key.
	 * @param string                $label   Label of the list.
	 * @param array<string, string> $choices Value => label.
	 * @param string                $help    Help text.
	 */
	public function checklist( string $group, string $key, string $label, array $choices, string $help = '' ): void {
		$name     = self::name( $group, $key ) . '[]';
		$selected = (array) $this->value( $group, $key );
		?>
		<div class="lumia-form__group">
			<span class="lumia-form__label"><?php echo esc_html( $label ); ?></span>
			<?php $this->help( $help ); ?>
		</div>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="">
		<?php foreach ( $choices as $value => $text ) : ?>
			<?php $id = self::id( $group, $key ) . '_' . $value; ?>
			<div class="lumia-form__group lumia-form__group--toggle">
				<div class="lumia-form__toggle-label">
					<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $text ); ?></label>
				</div>
				<label class="lumia-toggle">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" <?php checked( in_array( (string) $value, array_map( 'strval', $selected ), true ) ); ?>>
					<span class="lumia-toggle__slider"></span>
				</label>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Row with a text (or textarea) value and an on/off toggle: `{key}` and `{key}_enabled`.
	 *
	 * @param string $group    Group.
	 * @param string $key      Key of the text, the toggle is `{key}_enabled`.
	 * @param string $label    Label.
	 * @param string $help     Help text.
	 * @param bool   $textarea Use a textarea.
	 */
	public function toggled_text( string $group, string $key, string $label, string $help = '', bool $textarea = false ): void {
		$id      = self::id( $group, $key );
		$toggle  = $key . '_enabled';
		$toggled = self::id( $group, $toggle );
		?>
		<div class="lumia-form__group lumia-factures-inv-toggled">
			<div class="lumia-factures-inv-toggled__head">
				<label for="<?php echo esc_attr( $id ); ?>" class="lumia-form__label"><?php echo esc_html( $label ); ?></label>
				<label class="lumia-toggle" for="<?php echo esc_attr( $toggled ); ?>">
					<input type="hidden" name="<?php echo esc_attr( self::name( $group, $toggle ) ); ?>" value="0">
					<input type="checkbox" id="<?php echo esc_attr( $toggled ); ?>" name="<?php echo esc_attr( self::name( $group, $toggle ) ); ?>" value="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: field label. */ __( 'Show "%s" on the invoice', 'lumia-factures' ), $label ) ); ?>" <?php checked( ! empty( $this->value( $group, $toggle ) ) ); ?>>
					<span class="lumia-toggle__slider"></span>
				</label>
			</div>
			<?php if ( $textarea ) : ?>
				<textarea class="lumia-input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>" rows="3"><?php echo esc_textarea( (string) $this->value( $group, $key ) ); ?></textarea>
			<?php else : ?>
				<input type="text" class="lumia-input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $group, $key ) ); ?>" value="<?php echo esc_attr( (string) $this->value( $group, $key ) ); ?>">
			<?php endif; ?>
			<?php $this->help( $help ); ?>
		</div>
		<?php
	}
}

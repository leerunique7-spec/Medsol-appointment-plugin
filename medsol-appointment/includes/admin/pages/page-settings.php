<?php
/**
 * Settings admin page.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register settings, sections, and fields early so options.php recognizes the group.
 */
add_action( 'admin_init', 'medsol_register_medsol_settings' );

function medsol_register_medsol_settings() {
	// Register the option with a sanitizer.
	register_setting(
		'medsol_settings',                        // settings group (used by settings_fields)
		'medsol_appointments_settings',           // option name
		array(
			'type'              => 'array',
			'capability'        => 'manage_options',
			'sanitize_callback' => 'medsol_sanitize_medsol_settings',
			'default'           => array(
				'busy_slots'         => 'location',
				'default_status'     => 'pending',
				'erase_on_uninstall' => 0,
			),
		)
	);

	// Section.
	add_settings_section(
		'medsol_settings_general',
		'General Settings',
		null,
		'medsol_settings' // page slug used in do_settings_sections()
	);

	// Fields.
	add_settings_field(
		'medsol_busy_slots',
		'Busy Slots Calculated By',
		'medsol_busy_slots_callback',
		'medsol_settings',
		'medsol_settings_general'
	);

	add_settings_field(
		'medsol_default_status',
		'Default Status of Booking',
		'medsol_default_status_callback',
		'medsol_settings',
		'medsol_settings_general'
	);

	add_settings_field(
		'medsol_erase_on_uninstall',
		'Remove All Data on Uninstall',
		'medsol_erase_on_uninstall_callback',
		'medsol_settings',
		'medsol_settings_general'
	);
}

/**
 * Sanitize & whitelist Medsol settings.
 *
 * @param array $input Raw POSTed settings.
 * @return array Clean settings to persist.
 */
function medsol_sanitize_medsol_settings( $input ) {
	$input  = is_array( $input ) ? $input : array();
	$output = array();

	// busy_slots: only 'location' or 'service'
	$allowed_busy          = array( 'location', 'service' );
	$output['busy_slots']  = in_array( $input['busy_slots'] ?? '', $allowed_busy, true )
		? $input['busy_slots']
		: 'location';

	// default_status: only known statuses
	$allowed_status             = array( 'pending', 'approved', 'declined', 'canceled' );
	$output['default_status']   = in_array( $input['default_status'] ?? '', $allowed_status, true )
		? $input['default_status']
		: 'pending';

	// erase_on_uninstall: checkbox → 0/1
	$output['erase_on_uninstall'] = ! empty( $input['erase_on_uninstall'] ) ? 1 : 0;

	return $output;
}

/**
 * Render settings page.
 */
function medsol_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to access this page.' );
	}

	// Handle separate save for max booking days (custom mini-form).
	if ( isset( $_POST['save_max_days'] ) && check_admin_referer( 'medsol_max_days_nonce' ) ) {
		update_option( 'medsol_max_booking_days', absint( $_POST['max_booking_days'] ) );
	}
	?>
	<div class="wrap">
		<h1>Settings</h1>

		<!-- Main Settings form uses options.php -->
		<form method="post" action="options.php">
			<?php
			settings_fields( 'medsol_settings' );   // outputs nonce + option_page for medsol_settings
			do_settings_sections( 'medsol_settings' );
			submit_button();
			?>
		</form>

		<h2>Frontend Booking</h2>
		<p>Use the shortcode [medsol_appointment_booking] on any page to display the booking form.</p>

		<h2>Max Booking Days Ahead</h2>
		<form method="post">
			<?php wp_nonce_field( 'medsol_max_days_nonce' ); ?>
			<input type="number" name="max_booking_days" value="<?php echo esc_attr( get_option( 'medsol_max_booking_days', 90 ) ); ?>" min="1">
			<input type="submit" name="save_max_days" class="button button-primary" value="Save Max Days">
		</form>

		<h2>Shortcode Usage Examples</h2>
		<ul>
			<li>[medsol_appointment_booking] - Full form.</li>
			<li>[medsol_appointment_booking %form_location% %form_employee% %form_service:1:hidden%] - Location and Employee visible, Service preselected and hidden.</li>
			<li>[medsol_appointment_booking %form_service% %form_location%] - Only Service and Location visible.</li>
			<li>[medsol_appointment_booking %form_service:3%] - Service preselected to ID 3, visible.</li>
		</ul>
		<p>Tokens: %form_location%, %form_service%, %form_employee%, %form_date%, %form_time%, %form_customer_name%, %form_customer_email%, %form_customer_email_confirmation%, %form_customer_phone%, %form_note%.</p>
		<p>Modifiers: :ID for preselect, :ID:hidden for hidden preselect.</p>
		<p>Note: Service is required for slots; if not visible, must be preselected.</p>
	</div>
	<?php
}

function medsol_busy_slots_callback() {
	$options = get_option( 'medsol_appointments_settings', array() );
	$value   = $options['busy_slots'] ?? 'location';
	?>
	<label><input type="radio" name="medsol_appointments_settings[busy_slots]" value="location" <?php checked( 'location', $value ); ?>> By Location</label>
	<label><input type="radio" name="medsol_appointments_settings[busy_slots]" value="service"  <?php checked( 'service',  $value ); ?>> By Service</label>
	<?php
}

function medsol_default_status_callback() {
	$options = get_option( 'medsol_appointments_settings', array() );
	$value   = $options['default_status'] ?? 'pending';
	?>
	<select name="medsol_appointments_settings[default_status]">
		<option value="pending"  <?php selected( 'pending',  $value ); ?>>Pending</option>
		<option value="approved" <?php selected( 'approved', $value ); ?>>Approved</option>
		<option value="declined" <?php selected( 'declined', $value ); ?>>Declined</option>
		<option value="canceled" <?php selected( 'canceled', $value ); ?>>Canceled</option>
	</select>
	<?php
}

function medsol_erase_on_uninstall_callback() {
	$options = get_option( 'medsol_appointments_settings', array() );
	$checked = ! empty( $options['erase_on_uninstall'] ) ? 1 : 0;
	?>
	<label>
		<input type="checkbox"
			   name="medsol_appointments_settings[erase_on_uninstall]"
			   value="1"
			   <?php checked( 1, $checked ); ?>>
		Erase all plugin data and drop tables on uninstall.
	</label>
	<?php
}

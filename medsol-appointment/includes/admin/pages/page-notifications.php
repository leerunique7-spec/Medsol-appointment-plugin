<?php
/**
 * Notifications admin page (enhanced UI).
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register notifications settings, sections, and fields early.
 */
add_action( 'admin_init', 'medsol_register_notifications_settings' );

/**
 * AJAX: Send test email for a selected template (Email channel).
 */
add_action( 'wp_ajax_medsol_notifications_send_test', 'medsol_notifications_send_test_ajax' );

function medsol_register_notifications_settings() {
	// Register the option so options.php recognizes the group.
	register_setting(
		'medsol_notifications',                       // settings group (used by settings_fields)
		'medsol_appointments_notifications',          // option name
		array(
			'type'              => 'array',
			'capability'        => 'manage_options',
			'sanitize_callback' => 'medsol_sanitize_notifications',
			'default'           => array(
				'enable'           => 0,
				'admin_recipients' => array(),
				'email'            => array(),
				'sms'              => array(),
				'whatsapp'         => array(),
			),
		)
	);
}

/**
 * Sanitize notifications option.
 *
 * @param array $input Raw POSTed settings.
 * @return array Clean settings to persist.
 */
function medsol_sanitize_notifications( $input ) {
	$input  = is_array( $input ) ? $input : array();
	$output = array();

	// Enable checkbox -> 0/1
	$output['enable'] = ! empty( $input['enable'] ) ? 1 : 0;

	// Admin recipients: comma-separated or array -> array of sanitized emails.
	$recips_raw = $input['admin_recipients'] ?? '';
	if ( is_array( $recips_raw ) ) {
		$recips_raw = implode( ',', $recips_raw );
	}
	$emails = array_filter( array_map( 'trim', preg_split( '/[,\n]+/', (string) $recips_raw ) ) );
	$emails = array_values( array_filter( array_map( 'sanitize_email', $emails ) ) );
	$output['admin_recipients'] = $emails;

	// Whitelist template keys and recipients.
	$recipients = array( 'customer', 'employee', 'admin' );
	$templates  = medsol_notifications_templates_keys(); // ['approved','pending','declined','canceled','follow_up','reminder']

	// Channels we care about (we only implement Email UI now, but we store shape for SMS/WhatsApp).
	$channels = array( 'email', 'sms', 'whatsapp' );

	foreach ( $channels as $channel ) {
		$output[ $channel ] = array();
		if ( empty( $input[ $channel ] ) || ! is_array( $input[ $channel ] ) ) {
			continue;
		}
		foreach ( $recipients as $r ) {
			foreach ( $templates as $t ) {
				$enabled = ! empty( $input[ $channel ][ $r ][ $t ]['enabled'] ) ? 1 : 0;
				$subject = $input[ $channel ][ $r ][ $t ]['subject'] ?? '';
				$body    = $input[ $channel ][ $r ][ $t ]['body'] ?? '';

				// Subject: plain text
				$subject = sanitize_text_field( $subject );

				// Body: allow basic HTML when present; SMS/WhatsApp are plain text but still safe to sanitize.
				$body = ( 'email' === $channel ) ? wp_kses_post( $body ) : sanitize_textarea_field( $body );

				// Only persist if something is set (including enabled).
				if ( $enabled || '' !== $subject || '' !== $body ) {
					$output[ $channel ][ $r ][ $t ] = array(
						'enabled' => $enabled,
						'subject' => $subject,
						'body'    => $body,
					);
				}
			}
		}
	}

	return $output;
}

/**
 * Templates we support (keys => labels).
 *
 * @return array
 */
function medsol_notifications_templates() {
	return array(
		'approved'  => __( 'Appointment Approved', 'medsol-appointments' ),
		'pending'   => __( 'Appointment Pending', 'medsol-appointments' ),
		'declined'  => __( 'Appointment Declined', 'medsol-appointments' ),
		'canceled'  => __( 'Appointment Canceled', 'medsol-appointments' ),
		'follow_up' => __( 'Appointment Follow Up', 'medsol-appointments' ),
		'reminder'  => __( 'Appointment Reminder', 'medsol-appointments' ),
	);
}

/**
 * Template keys only (helper).
 *
 * @return array
 */
function medsol_notifications_templates_keys() {
	return array_keys( medsol_notifications_templates() );
}

/**
 * Placeholders list (one time JSON for drawer/search).
 * @return array[]
 */
function medsol_notifications_placeholders() {
	// label => code
	return array(
		'Customer'    => array(
			array( 'label' => __( 'Customer Name', 'medsol-appointments' ),   'code' => '{customer_name}' ),
			array( 'label' => __( 'Customer Email', 'medsol-appointments' ),  'code' => '{customer_email}' ),
			array( 'label' => __( 'Customer Phone', 'medsol-appointments' ),  'code' => '{customer_phone}' ),
			array( 'label' => __( 'Customer Note', 'medsol-appointments' ),   'code' => '{customer_note}' ),
		),
		'Appointment' => array(
			array( 'label' => __( 'Appointment ID', 'medsol-appointments' ),       'code' => '{appointment_id}' ),
			array( 'label' => __( 'Status', 'medsol-appointments' ),               'code' => '{appointment_status}' ),
			array( 'label' => __( 'Date', 'medsol-appointments' ),                 'code' => '{appointment_date}' ),
			array( 'label' => __( 'Time', 'medsol-appointments' ),                 'code' => '{appointment_time}' ),
			array( 'label' => __( 'Date & Time', 'medsol-appointments' ),          'code' => '{appointment_datetime}' ),
			array( 'label' => __( 'Duration (min)', 'medsol-appointments' ),       'code' => '{appointment_duration}' ),
			array( 'label' => __( 'Start Time (fmt)', 'medsol-appointments' ),     'code' => '{appointment_start_time}' ),
			array( 'label' => __( 'End Time (fmt)', 'medsol-appointments' ),       'code' => '{appointment_end_time}' ),
		),
		'Service'     => array(
			array( 'label' => __( 'Service Name', 'medsol-appointments' ),         'code' => '{service_name}' ),
			array( 'label' => __( 'Service Duration (min)', 'medsol-appointments' ), 'code' => '{service_duration}' ),
		),
		'Employee'    => array(
			array( 'label' => __( 'Employee Name', 'medsol-appointments' ),        'code' => '{employee_name}' ),
			array( 'label' => __( 'Employee Email', 'medsol-appointments' ),       'code' => '{employee_email}' ),
			array( 'label' => __( 'Employee Phone', 'medsol-appointments' ),       'code' => '{employee_phone}' ),
		),
		'Location'    => array(
			array( 'label' => __( 'Location Name', 'medsol-appointments' ),        'code' => '{location_name}' ),
			array( 'label' => __( 'Location Address', 'medsol-appointments' ),     'code' => '{location_address}' ),
			array( 'label' => __( 'Location Phone', 'medsol-appointments' ),       'code' => '{location_phone}' ),
		),
		'Site'        => array(
			array( 'label' => __( 'Site Name', 'medsol-appointments' ),            'code' => '{site_name}' ),
			array( 'label' => __( 'Site URL', 'medsol-appointments' ),             'code' => '{site_url}' ),
		),
	);
}

/**
 * Build sample tags for test email rendering (no appointment required).
 * @return array tag => value
 */
function medsol_notifications_sample_tags() {
	$tz          = wp_timezone();
	$time_format = get_option( 'time_format', 'H:i' );
	$now         = time();
	return array(
		'{appointment_id}'         => '12345',
		'{appointment_status}'     => 'approved',
		'{appointment_date}'       => wp_date( 'Y-m-d', $now, $tz ),
		'{appointment_time}'       => wp_date( $time_format, $now, $tz ),
		'{appointment_datetime}'   => wp_date( 'Y-m-d ' . $time_format, $now, $tz ),
		'{appointment_duration}'   => '30',
		'{appointment_start_time}' => wp_date( $time_format, $now, $tz ),
		'{appointment_end_time}'   => wp_date( $time_format, $now + 30 * 60, $tz ),
		'{customer_name}'          => 'John Doe',
		'{customer_email}'         => 'john@example.com',
		'{customer_phone}'         => '+31 6 12345678',
		'{customer_note}'          => 'Looking forward to it.',
		'{service_name}'           => 'Consultation',
		'{service_duration}'       => '30',
		'{employee_name}'          => 'Jane Smith',
		'{employee_email}'         => 'jane@example.com',
		'{employee_phone}'         => '+31 70 1234567',
		'{location_name}'          => 'Rotterdam Office',
		'{location_address}'       => 'Coolsingel 1, Rotterdam',
		'{location_phone}'         => '+31 10 7654321',
		'{site_name}'              => get_bloginfo( 'name' ),
		'{site_url}'               => home_url(),
	);
}

/**
 * AJAX handler to send test email.
 * expects: $_POST['nonce'], $_POST['send_to'], $_POST['template_key'], $_POST['recipient_key']
 */
function medsol_notifications_send_test_ajax() {
	check_ajax_referer( 'medsol_notifications_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'medsol-appointments' ) ), 403 );
	}

	$send_to      = isset( $_POST['send_to'] ) ? sanitize_email( wp_unslash( $_POST['send_to'] ) ) : '';
	$template_key = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
	$recipient    = isset( $_POST['recipient_key'] ) ? sanitize_key( wp_unslash( $_POST['recipient_key'] ) ) : 'customer';

	if ( empty( $send_to ) || ! is_email( $send_to ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'medsol-appointments' ) ), 400 );
	}

	$templates = medsol_notifications_templates_keys();
	if ( ! in_array( $template_key, $templates, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid template selected.', 'medsol-appointments' ) ), 400 );
	}

	$options = get_option( 'medsol_appointments_notifications', array() );
	$email   = $options['email'][ $recipient ][ $template_key ] ?? array();

	$subject_tpl = (string) ( $email['subject'] ?? sprintf( '[Test] %s', ucfirst( $template_key ) ) );
	$body_tpl    = (string) ( $email['body'] ?? __( 'This is a test body.', 'medsol-appointments' ) );

	$tags    = medsol_notifications_sample_tags();
	$subject = strtr( $subject_tpl, $tags );
	$body    = strtr( $body_tpl, $tags );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	$sent    = wp_mail( $send_to, $subject, $body, $headers );

	if ( $sent ) {
		wp_send_json_success( array( 'message' => __( 'Test email sent.', 'medsol-appointments' ) ) );
	}
	wp_send_json_error( array( 'message' => __( 'Failed to send test email. Check mail configuration.', 'medsol-appointments' ) ), 500 );
}

/**
 * Render notifications page (HTML + JS/CSS).
 */
function medsol_notifications_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have permission to access this page.', 'medsol-appointments' ) );
	}

	$options    = get_option( 'medsol_appointments_notifications', array() );
	$placeholds = medsol_notifications_placeholders();
	$templates  = medsol_notifications_templates();
	$recipients = array(
		'customer' => __( 'To Customer', 'medsol-appointments' ),
		'employee' => __( 'To Employee', 'medsol-appointments' ),
		'admin'    => __( 'To Admin', 'medsol-appointments' ),
	);

	// Ensure editor scripts can be initialized later (lazy).
	if ( function_exists( 'wp_enqueue_editor' ) ) {
		wp_enqueue_editor();
	}
	$nonce = wp_create_nonce( 'medsol_notifications_nonce' );
	?>
	<div class="wrap medsol-notifications-wrap">
		<h1><?php esc_html_e( 'Notifications', 'medsol-appointments' ); ?></h1>

		<form id="medsol-notifications-form" method="post" action="options.php" autocomplete="off">
			<?php settings_fields( 'medsol_notifications' ); ?>

			<!-- Channel Tabs -->
			<nav class="nav-tab-wrapper medsol-channel-tabs" role="tablist">
				<a href="#channel-email" class="nav-tab nav-tab-active" role="tab" aria-controls="channel-email" data-channel="email"><?php esc_html_e( 'Email', 'medsol-appointments' ); ?></a>
				<a href="#channel-sms" class="nav-tab" role="tab" aria-controls="channel-sms" data-channel="sms"><?php esc_html_e( 'SMS', 'medsol-appointments' ); ?></a>
				<a href="#channel-whatsapp" class="nav-tab" role="tab" aria-controls="channel-whatsapp" data-channel="whatsapp"><?php esc_html_e( 'WhatsApp', 'medsol-appointments' ); ?></a>
			</nav>

			<!-- General toggles -->
			<div class="medsol-general">
				<label class="medsol-toggle">
					<input type="checkbox" name="medsol_appointments_notifications[enable]" value="1" <?php checked( ! empty( $options['enable'] ) ); ?>>
					<span><?php esc_html_e( 'Enable notifications (all channels)', 'medsol-appointments' ); ?></span>
				</label>

				<div class="medsol-admin-recipients">
					<label for="medsol-admin-recipients-input"><strong><?php esc_html_e( 'Admin recipients', 'medsol-appointments' ); ?></strong></label>
					<?php
					$admin_value = $options['admin_recipients'] ?? '';
					if ( is_array( $admin_value ) ) {
						$admin_value = implode( ', ', array_map( 'sanitize_email', $admin_value ) );
					}
					?>
					<input id="medsol-admin-recipients-input" type="text" name="medsol_appointments_notifications[admin_recipients]" value="<?php echo esc_attr( $admin_value ); ?>" placeholder="admin1@example.com, admin2@example.com" />
					<p class="description"><?php esc_html_e( 'Comma-separated emails. Falls back to the site admin email if empty.', 'medsol-appointments' ); ?></p>
				</div>
			</div>

			<!-- Channel Panels -->
			<div id="channel-email" class="medsol-channel-panel" style="display:block">
				<!-- Audience Tabs -->
				<nav class="medsol-subtabs" role="tablist">
					<?php foreach ( $recipients as $rkey => $rlabel ) : ?>
						<a href="#aud-<?php echo esc_attr( $rkey ); ?>" class="medsol-subtab <?php echo ( 'customer' === $rkey ) ? 'is-active' : ''; ?>" role="tab" aria-controls="aud-<?php echo esc_attr( $rkey ); ?>" data-recipient="<?php echo esc_attr( $rkey ); ?>">
							<?php echo esc_html( $rlabel ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php foreach ( $recipients as $rkey => $rlabel ) : ?>
					<?php
					$aud_active = ( 'customer' === $rkey ) ? 'style="display:grid"' : 'style="display:none"';
					?>
					<section id="aud-<?php echo esc_attr( $rkey ); ?>" class="medsol-audience-grid" <?php echo $aud_active; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<!-- Left Rail -->
						<aside class="medsol-left-rail">
							<ul class="medsol-template-list" role="listbox" aria-label="<?php echo esc_attr( sprintf( __( 'Templates for %s', 'medsol-appointments' ), $rlabel ) ); ?>">
								<?php foreach ( $templates as $tkey => $tlabel ) :
									$tpl = $options['email'][ $rkey ][ $tkey ] ?? array();
									$enabled = ! empty( $tpl['enabled'] );
									?>
									<li class="medsol-template-item" data-template="<?php echo esc_attr( $tkey ); ?>">
										<label class="medsol-checkbox">
											<input type="checkbox" name="medsol_appointments_notifications[email][<?php echo esc_attr( $rkey ); ?>][<?php echo esc_attr( $tkey ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
											<span><?php echo esc_html( $tlabel ); ?></span>
										</label>
									</li>
								<?php endforeach; ?>
							</ul>
						</aside>

						<!-- Editor Pane -->
						<main class="medsol-editor-pane" aria-live="polite">
							<?php foreach ( $templates as $tkey => $tlabel ) :
								$tpl   = $options['email'][ $rkey ][ $tkey ] ?? array();
								$subj  = (string) ( $tpl['subject'] ?? '' );
								$body  = (string) ( $tpl['body'] ?? '' );
								$eid   = 'medsol-email-' . $rkey . '-' . $tkey . '-body';
								$tid   = 'medsol-email-' . $rkey . '-' . $tkey . '-title';
								?>
								<section class="medsol-template-editor" data-template="<?php echo esc_attr( $tkey ); ?>" style="display:<?php echo ( 'approved' === $tkey ) ? 'block' : 'none'; ?>">
									<header class="medsol-editor-header">
										<h2 id="<?php echo esc_attr( $tid ); ?>"><?php echo esc_html( $tlabel ); ?></h2>
										<div class="medsol-header-actions">
											<button type="button" class="button button-link medsol-show-placeholders" aria-expanded="false" aria-controls="medsol-drawer"><?php esc_html_e( 'Show Email Placeholders', 'medsol-appointments' ); ?></button>
											<span class="medsol-toggle-mode">
												<label><input type="radio" name="mode-<?php echo esc_attr( $rkey . '-' . $tkey ); ?>" value="text" class="medsol-mode-switch" checked> <?php esc_html_e( 'Text Mode', 'medsol-appointments' ); ?></label>
												<label><input type="radio" name="mode-<?php echo esc_attr( $rkey . '-' . $tkey ); ?>" value="html" class="medsol-mode-switch"> <?php esc_html_e( 'HTML Mode', 'medsol-appointments' ); ?></label>
											</span>
										</div>
									</header>

									<div class="medsol-field">
										<label><strong><?php esc_html_e( 'Subject', 'medsol-appointments' ); ?></strong></label>
										<input type="text" class="medsol-input medsol-subject" name="medsol_appointments_notifications[email][<?php echo esc_attr( $rkey ); ?>][<?php echo esc_attr( $tkey ); ?>][subject]" value="<?php echo esc_attr( $subj ); ?>" />
									</div>

									<div class="medsol-field">
										<label><strong><?php esc_html_e( 'Message', 'medsol-appointments' ); ?></strong></label>

										<!-- TEXTAREA (Text mode) -->
										<textarea id="<?php echo esc_attr( $eid ); ?>" class="medsol-textarea" name="medsol_appointments_notifications[email][<?php echo esc_attr( $rkey ); ?>][<?php echo esc_attr( $tkey ); ?>][body]" rows="14"><?php echo esc_textarea( $body ); ?></textarea>

										<!-- Placeholder for TinyMCE (HTML mode) initialized on demand -->
										<div class="medsol-editor-holder" data-editor-for="<?php echo esc_attr( $eid ); ?>" hidden></div>
										<p class="description"><?php esc_html_e( 'Use placeholders from the drawer. Switch to HTML mode to use rich formatting.', 'medsol-appointments' ); ?></p>
									</div>

									<!-- Sticky bar (appears when dirty) -->
									<footer class="medsol-sticky">
										<span class="medsol-dirty-indicator" aria-live="polite"></span>
										<div class="medsol-sticky-actions">
											<button type="button" class="button medsol-test-email" data-recipient="<?php echo esc_attr( $rkey ); ?>"><?php esc_html_e( 'Send Test Email', 'medsol-appointments' ); ?></button>
											<?php submit_button( __( 'Save', 'medsol-appointments' ), 'primary', 'submit', false ); ?>
										</div>
									</footer>
								</section>
							<?php endforeach; ?>
						</main>
					</section>
				<?php endforeach; ?>
			</div>

			<div id="channel-sms" class="medsol-channel-panel" style="display:none">
				<p class="description"><?php esc_html_e( 'SMS UI coming later.', 'medsol-appointments' ); ?></p>
			</div>

			<div id="channel-whatsapp" class="medsol-channel-panel" style="display:none">
				<p class="description"><?php esc_html_e( 'WhatsApp UI coming later.', 'medsol-appointments' ); ?></p>
			</div>

			<input type="hidden" id="medsol-notifications-nonce" value="<?php echo esc_attr( $nonce ); ?>">
		</form>

		<!-- Right Drawer: Placeholders -->
		<aside id="medsol-drawer" class="medsol-drawer" aria-hidden="true" tabindex="-1">
			<div class="medsol-drawer-header">
				<h3><?php esc_html_e( 'Email Placeholders', 'medsol-appointments' ); ?></h3>
				<button type="button" class="button-link medsol-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'medsol-appointments' ); ?>">&times;</button>
			</div>
			<div class="medsol-drawer-body">
				<input type="search" class="medsol-search" placeholder="<?php esc_attr_e( 'Search placeholders…', 'medsol-appointments' ); ?>" aria-label="<?php esc_attr_e( 'Search placeholders', 'medsol-appointments' ); ?>">

				<?php foreach ( $placeholds as $group => $items ) : ?>
					<div class="medsol-ph-group" data-group="<?php echo esc_attr( $group ); ?>">
						<h4><?php echo esc_html( $group ); ?></h4>
						<ul>
							<?php foreach ( $items as $it ) : ?>
								<li class="medsol-ph-item" data-code="<?php echo esc_attr( $it['code'] ); ?>">
									<div class="medsol-ph-main">
										<span class="medsol-ph-label"><?php echo esc_html( $it['label'] ); ?></span>
										<code class="medsol-ph-code"><?php echo esc_html( $it['code'] ); ?></code>
									</div>
									<div class="medsol-ph-actions">
										<button type="button" class="button button-small medsol-insert" data-code="<?php echo esc_attr( $it['code'] ); ?>"><?php esc_html_e( 'Insert', 'medsol-appointments' ); ?></button>
										<button type="button" class="button button-small medsol-copy" data-code="<?php echo esc_attr( $it['code'] ); ?>"><?php esc_html_e( 'Copy', 'medsol-appointments' ); ?></button>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</aside>

		<!-- Modal: Test Email -->
		<div id="medsol-test-modal" class="medsol-modal" aria-hidden="true" tabindex="-1">
			<div class="medsol-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="medsol-test-title">
				<header class="medsol-modal__header">
					<h3 id="medsol-test-title"><?php esc_html_e( 'Send Test Email', 'medsol-appointments' ); ?></h3>
					<button type="button" class="button-link medsol-modal-close" aria-label="<?php esc_attr_e( 'Close', 'medsol-appointments' ); ?>">&times;</button>
				</header>
				<div class="medsol-modal__body">
					<label>
						<strong><?php esc_html_e( 'Send to email', 'medsol-appointments' ); ?></strong>
						<input type="email" id="medsol-test-to" placeholder="you@example.com">
					</label>
					<label>
						<strong><?php esc_html_e( 'Template to test', 'medsol-appointments' ); ?></strong>
						<select id="medsol-test-template">
							<?php foreach ( $recipients as $rkey => $rlabel ) : ?>
								<optgroup label="<?php echo esc_attr( $rlabel ); ?>">
									<?php foreach ( $templates as $tkey => $tlabel ) : ?>
										<option value="<?php echo esc_attr( $rkey . '|' . $tkey ); ?>"><?php echo esc_html( $tlabel ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</label>
					<p class="description"><?php esc_html_e( 'We’ll send a preview email with sample data replacing placeholders.', 'medsol-appointments' ); ?></p>
					<div id="medsol-test-feedback" class="notice" style="display:none"></div>
				</div>
				<footer class="medsol-modal__footer">
					<button type="button" class="button button-primary" id="medsol-test-send"><?php esc_html_e( 'Send', 'medsol-appointments' ); ?></button>
					<button type="button" class="button medsol-modal-close"><?php esc_html_e( 'Cancel', 'medsol-appointments' ); ?></button>
				</footer>
			</div>
		</div>
	</div>

	<!-- Data for admin.js (no inline JS) -->
	<textarea id="medsol-notif-placeholders" hidden><?php echo wp_json_encode( $placeholds ); ?></textarea>
	<textarea id="medsol-notif-templates" hidden><?php echo wp_json_encode( $templates ); ?></textarea>
	<input type="hidden" id="medsol-notif-l10n-unsaved" value="<?php echo esc_attr__( 'Unsaved changes', 'medsol-appointments' ); ?>">
	<input type="hidden" id="medsol-notif-l10n-leave" value="<?php echo esc_attr__( 'You have unsaved changes. Leave without saving?', 'medsol-appointments' ); ?>">
	<input type="hidden" id="medsol-notif-l10n-test-sent" value="<?php echo esc_attr__( 'Test email sent.', 'medsol-appointments' ); ?>">
	<input type="hidden" id="medsol-notif-l10n-test-failed" value="<?php echo esc_attr__( 'Failed to send test email. Check mail configuration.', 'medsol-appointments' ); ?>">
	<input type="hidden" id="medsol-notif-l10n-unexpected" value="<?php echo esc_attr__( 'Unexpected error.', 'medsol-appointments' ); ?>">
	<?php
}

<?php
/**
 * Notifications service.
 *
 * Coordinates who gets emailed and with which template when appointments are
 * created or change status. Uses wp_mail() synchronously (or Medsol_Mailer if present).
 *
 * @package Medsol_Appointments
 */

defined('ABSPATH') || exit;

if ( ! class_exists( 'Medsol_Notifications' ) ) :

class Medsol_Notifications {

	/**
	 * Wire up hooks. Call this once at plugin load.
	 */
	public static function boot() {
		$self = new self();

		// New appointment created (from Medsol_Appointment::create()).
		add_action( 'medsol_appointment_created', [ $self, 'on_appointment_created' ], 10, 2 );

		// Status changed (you should fire this from Medsol_Appointment::update()).
		add_action( 'medsol_appointment_status_changed', [ $self, 'on_status_changed' ], 10, 3 );
	}

	/**
	 * Called after a booking is created.
	 *
	 * @param int   $appointment_id Newly created appointment ID.
	 * @param array $context        Sanitized data passed from create().
	 */
	public function on_appointment_created( $appointment_id, $context = array() ) {
		$payload = $this->build_payload( $appointment_id );
		if ( ! $payload ) {
			return;
		}

		// Pick template key by current status (pending/approved/declined/canceled).
		$template_key = ! empty( $payload['appointment']['status'] ) ? $payload['appointment']['status'] : 'pending';

		$this->send_for_template( $template_key, $payload );
	}

	/**
	 * Called when appointment status changes.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $old_status     Old status.
	 * @param string $new_status     New status.
	 */
	public function on_status_changed( $appointment_id, $old_status, $new_status ) {
		$payload = $this->build_payload( $appointment_id );
		if ( ! $payload ) {
			return;
		}
		$this->send_for_template( (string) $new_status, $payload );
	}

	/* ---------------------------------------------------------------------
	 * Core send logic
	 * -------------------------------------------------------------------*/

	/**
	 * Send notifications for a given template key (pending/approved/declined/canceled/...).
	 *
	 * @param string $template_key Template key.
	 * @param array  $payload      Data bundle from build_payload().
	 */
	protected function send_for_template( $template_key, array $payload ) {
		$options = $this->get_options();

		// Global "Enable Notifications" toggle.
		if ( empty( $options['enable'] ) ) {
			return;
		}

		// Build tag map for replacements.
		$tags = $this->build_merge_tags( $payload );

		// Recipients we support.
		$recipients = array( 'customer', 'employee', 'admin' );

		foreach ( $recipients as $recipient_key ) {
			$template = $this->get_template_config( $options, $recipient_key, $template_key );

			// Respect per-template enable if present (default to enabled when absent).
			if ( isset( $template['enabled'] ) && ! $template['enabled'] ) {
				continue;
			}

			$subject_tpl = (string) ( $template['subject'] ?? '' );
			$body_tpl    = (string) ( $template['body'] ?? '' );

			$subject = $this->render( $subject_tpl, $tags );
			$body    = $this->render( $body_tpl, $tags );

			// Skip if both subject and body render to empty/whitespace.
			if ( '' === trim( wp_strip_all_tags( $subject ) ) && '' === trim( wp_strip_all_tags( $body ) ) ) {
				continue;
			}

			$to_list = $this->resolve_recipients( $recipient_key, $payload, $options );
			$to_list = apply_filters( 'medsol_notifications_recipients', $to_list, $recipient_key, $template_key, $payload );

			if ( empty( $to_list ) ) {
				continue;
			}

			// Allow devs to cancel this specific send.
			if ( false === apply_filters( 'medsol_notifications_should_send', true, $recipient_key, $template_key, $payload ) ) {
				continue;
			}

			foreach ( $to_list as $to ) {
				$this->deliver( $to, $subject, $body );
			}
		}
	}

	/**
	 * Actually deliver an email (HTML).
	 * Falls back to wp_mail if Medsol_Mailer is not present.
	 *
	 * @param string $to      Recipient email.
	 * @param string $subject Subject.
	 * @param string $html    HTML body.
	 */
	protected function deliver( $to, $subject, $html ) {
		if ( class_exists( 'Medsol_Mailer' ) && is_callable( [ 'Medsol_Mailer', 'send' ] ) ) {
			Medsol_Mailer::send( $to, $subject, $html );
			return;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $to, $subject, $html, $headers );
	}

	/* ---------------------------------------------------------------------
	 * Payload & merge-tags
	 * -------------------------------------------------------------------*/

	/**
	 * Build the data bundle we need to render templates & recipients.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return array|null
	 */
	protected function build_payload( $appointment_id ) {
		$appointment = null;
		if ( class_exists( 'Medsol_Appointment' ) && method_exists( 'Medsol_Appointment', 'get' ) ) {
			$appointment = Medsol_Appointment::get( $appointment_id );
		}
		if ( ! $appointment ) {
			return null;
		}

		// Normalize appointment to array.
		$appt = is_object( $appointment ) ? (array) $appointment : (array) $appointment;

		$service  = class_exists( 'Medsol_Service' )  ? Medsol_Service::get( (int) ( $appt['service_id'] ?? 0 ) )   : null;
		$employee = class_exists( 'Medsol_Employee' ) ? Medsol_Employee::get( (int) ( $appt['employee_id'] ?? 0 ) ) : null;
		$location = class_exists( 'Medsol_Location' ) ? Medsol_Location::get( (int) ( $appt['location_id'] ?? 0 ) ) : null;

		// Normalize related objects to arrays.
		$service  = is_object( $service )  ? (array) $service  : (array) $service;
		$employee = is_object( $employee ) ? (array) $employee : (array) $employee;
		$location = is_object( $location ) ? (array) $location : (array) $location;

		return array(
			'appointment' => $appt,
			'service'     => $service,
			'employee'    => $employee,
			'location'    => $location,
		);
	}

	/**
	 * Merge-tags available in templates.
	 *
	 * @param array $payload Bundle from build_payload().
	 * @return array tag => value
	 */
	protected function build_merge_tags( array $payload ) {
		$a = $payload['appointment'];
		$s = $payload['service'];
		$e = $payload['employee'];
		$l = $payload['location'];

		$employee_name = trim( sprintf(
			'%s %s',
			(string) ( $e['first_name'] ?? '' ),
			(string) ( $e['last_name'] ?? '' )
		) );

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$datetime = '';
		if ( ! empty( $a['date'] ) && ! empty( $a['time'] ) ) {
			$datetime = trim( $a['date'] . ' ' . $a['time'] );
		}

		// Get WordPress timezone & time format
		$tz          = wp_timezone();
		$time_format = get_option( 'time_format', 'H:i' ); // fallback to 24h

		$duration_min = (int) ( $a['duration'] ?? ( $s['duration'] ?? 0 ) );
		$start_ts     = ( ! empty( $a['date'] ) && ! empty( $a['time'] ) )
			? strtotime( $a['date'] . ' ' . $a['time'] )
			: 0;

		$appointment_start_time = $start_ts ? wp_date( $time_format, $start_ts, $tz ) : '';
		$appointment_end_time   = ( $start_ts && $duration_min > 0 )
			? wp_date( $time_format, $start_ts + ( $duration_min * 60 ), $tz )
			: '';


		return array(
			'{appointment_id}'         => (string) ( $a['id'] ?? '' ),
			'{appointment_status}'     => (string) ( $a['status'] ?? '' ),
			'{appointment_date}'       => (string) ( $a['date'] ?? '' ),
			'{appointment_time}'       => (string) ( $a['time'] ?? '' ),
			'{appointment_datetime}'   => $datetime,
			'{appointment_duration}'   => (string) ( $a['duration'] ?? '' ),

			// NEW: Appointment start/end time shortcodes
			'{appointment_start_time}' => $appointment_start_time, // e.g. 09:00
			'{appointment_end_time}'   => $appointment_end_time,   // e.g. 09:30

			'{customer_name}'          => (string) ( $a['customer_name'] ?? '' ),
			'{customer_email}'         => (string) ( $a['customer_email'] ?? '' ),
			'{customer_phone}'         => (string) ( $a['customer_phone'] ?? '' ),
			'{customer_note}'          => (string) ( $a['note'] ?? '' ),

			'{service_name}'           => (string) ( $s['name'] ?? '' ),
			'{service_duration}'       => (string) ( $s['duration'] ?? '' ),

			'{employee_name}'          => $employee_name,
			'{employee_email}'         => (string) ( $e['email'] ?? '' ),
			'{employee_phone}'         => (string) ( $e['phone'] ?? '' ),

			'{location_name}'          => (string) ( $l['name'] ?? '' ),
			'{location_address}'       => (string) ( $l['address'] ?? '' ),
			'{location_phone}'         => (string) ( $l['phone'] ?? '' ),

			'{site_name}'              => (string) $site_name,
			'{site_url}'               => (string) $site_url,
		);
	}

	/**
	 * Simple tag replacement.
	 *
	 * @param string $template Subject or body.
	 * @param array  $tags     Map of {tag} => value.
	 * @return string
	 */
	protected function render( $template, array $tags ) {
		if ( '' === $template ) {
			return '';
		}
		return strtr( (string) $template, $tags );
	}

	/* ---------------------------------------------------------------------
	 * Settings helpers
	 * -------------------------------------------------------------------*/

	/**
	 * Get notifications options with safe defaults.
	 *
	 * Structure expected:
	 * - enable (0/1)
	 * - admin_recipients (comma-separated string or array)
	 * - email[customer|employee|admin][pending|approved|declined|canceled][subject|body(,enabled)]
	 *
	 * @return array
	 */
	protected function get_options() {
		$opts = get_option( 'medsol_appointments_notifications', array() );

		// Normalize shape; be generous with missing keys.
		$defaults = array(
			'enable'           => 0,
			'admin_recipients' => '', // comma-separated or array
			'email'            => array(
				'customer' => array(),
				'employee' => array(),
				'admin'    => array(),
			),
		);

		$opts = wp_parse_args( $opts, $defaults );
		if ( ! is_array( $opts['email'] ) ) {
			$opts['email'] = $defaults['email'];
		}

		return $opts;
	}

	/**
	 * Fetch template config (subject/body[/enabled]) for recipient/template.
	 *
	 * @param array  $options       All options.
	 * @param string $recipient_key customer|employee|admin
	 * @param string $template_key  pending|approved|declined|canceled|...
	 * @return array
	 */
	protected function get_template_config( array $options, $recipient_key, $template_key ) {
		$email = $options['email'] ?? array();

		// Structure: email[recipient][template][subject|body|enabled]
		$out = array(
			'subject' => '',
			'body'    => '',
			// 'enabled' => true, // if your settings later add it
		);

		if ( isset( $email[ $recipient_key ][ $template_key ] ) && is_array( $email[ $recipient_key ][ $template_key ] ) ) {
			$out = wp_parse_args( $email[ $recipient_key ][ $template_key ], $out );
		}

		return $out;
	}

	/**
	 * Resolve recipient emails for a given recipient bucket.
	 *
	 * @param string $recipient_key customer|employee|admin
	 * @param array  $payload       Data bundle.
	 * @param array  $options       All options (for admin list).
	 * @return string[] Array of sanitized unique emails.
	 */
	protected function resolve_recipients( $recipient_key, array $payload, array $options ) {
		$emails = array();

		switch ( $recipient_key ) {
			case 'customer':
				if ( ! empty( $payload['appointment']['customer_email'] ) ) {
					$emails[] = $payload['appointment']['customer_email'];
				}
				break;

			case 'employee':
				if ( ! empty( $payload['employee']['email'] ) ) {
					$emails[] = $payload['employee']['email'];
				}
				break;

			case 'admin':
				$list = $options['admin_recipients'] ?? '';
				if ( is_string( $list ) ) {
					$list = array_filter( array_map( 'trim', preg_split( '/[,\n]+/', $list ) ) );
				}
				if ( ! is_array( $list ) ) {
					$list = array();
				}
				// Always fallback to site admin email if list is empty.
				if ( empty( $list ) ) {
					$list[] = get_option( 'admin_email' );
				}
				$emails = array_merge( $emails, $list );
				break;
		}

		// Sanitize, validate & unique.
		$emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ) ) ) );
		$emails = array_values( array_filter( $emails, 'is_email' ) );

		return $emails;
	}
}

endif;

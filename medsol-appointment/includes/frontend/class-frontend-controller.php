<?php
/**
 * Frontend controller.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Medsol_Frontend_Controller
 */
class Medsol_Frontend_Controller {

	public function __construct() {
		add_action( 'wp_ajax_nopriv_medsol_get_available_dates', array( $this, 'get_available_dates' ) );
		add_action( 'wp_ajax_medsol_get_available_dates', array( $this, 'get_available_dates' ) );

		add_action( 'wp_ajax_nopriv_medsol_get_time_slots', array( $this, 'get_time_slots' ) );
		add_action( 'wp_ajax_medsol_get_time_slots', array( $this, 'get_time_slots' ) );

		add_action( 'wp_ajax_nopriv_medsol_submit_booking', array( $this, 'submit_booking' ) );
		add_action( 'wp_ajax_medsol_submit_booking', array( $this, 'submit_booking' ) );
	}

	/**
	 * Normalize boolean-ish POST flag.
	 */
	private function bool_flag( $value ): bool {
		if ( is_bool( $value ) ) return $value;
		$str = strtolower( trim( (string) $value ) );
		return in_array( $str, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Return sanitized, capability-enforced flags.
	 * Only allow flags when user can manage bookings (admin / manager).
	 */
	private function read_availability_flags_from_post(): array {
		$post = wp_unslash( $_POST );

		$requested_off  = isset( $post['ignore_off_days'] ) ? $this->bool_flag( $post['ignore_off_days'] ) : false;
		$requested_avl  = isset( $post['ignore_availability'] ) ? $this->bool_flag( $post['ignore_availability'] ) : false;

		$authorized = ( current_user_can( 'manage_bookings' ) || current_user_can( 'manage_options' ) );

		return array(
			'ignore_off_days'     => $authorized ? $requested_off : false,
			'ignore_availability' => $authorized ? $requested_avl : false,
		);
	}

	/**
	 * Safely call Medsol_Location::get_available_dates with optional $args if supported.
	 */
	private function call_get_available_dates( int $location_id, int $max_days, array $args ) {
		if ( ! method_exists( 'Medsol_Location', 'get_available_dates' ) ) {
			return array();
		}
		try {
			$rm = new ReflectionMethod( 'Medsol_Location', 'get_available_dates' );
			// If model method supports a 3rd parameter, pass flags through.
			if ( $rm->getNumberOfParameters() >= 3 ) {
				return Medsol_Location::get_available_dates( $location_id, $max_days, $args );
			}
		} catch ( \Throwable $e ) {
			// fall through to legacy signature
		}
		return Medsol_Location::get_available_dates( $location_id, $max_days );
	}

	/**
	 * Safely call Medsol_Location::get_time_slots_for_date with optional $args if supported.
	 */
	private function call_get_time_slots_for_date( int $location_id, string $date, int $service_id, int $employee_id, array $args ) {
		if ( ! method_exists( 'Medsol_Location', 'get_time_slots_for_date' ) ) {
			return array();
		}
		try {
			$rm = new ReflectionMethod( 'Medsol_Location', 'get_time_slots_for_date' );
			// If model method supports a 5th parameter, pass flags through.
			if ( $rm->getNumberOfParameters() >= 5 ) {
				return Medsol_Location::get_time_slots_for_date( $location_id, $date, $service_id, $employee_id, $args );
			}
		} catch ( \Throwable $e ) {
			// fall through to legacy signature
		}
		return Medsol_Location::get_time_slots_for_date( $location_id, $date, $service_id, $employee_id );
	}

	public function get_available_dates() {
		check_ajax_referer( 'medsol_booking_nonce', 'nonce' );

		$location_id = absint( $_POST['location_id'] ?? 0 );
		if ( ! $location_id ) {
			wp_send_json_error( 'Invalid location ID.' );
		}

		// Read flags (only honored for authorized users)
		$flags = $this->read_availability_flags_from_post();

		$max_days = (int) get_option( 'medsol_max_booking_days', 90 );
		$dates    = $this->call_get_available_dates( $location_id, $max_days, $flags );

		/**
		 * Filter: allow other plugins to modify available dates post-hoc.
		 * Params: $dates, $location_id, $flags
		 */
		$dates = apply_filters( 'medsol_filter_available_dates', $dates, $location_id, $flags );

		wp_send_json_success( $dates );
	}

	public function get_time_slots() {
		check_ajax_referer( 'medsol_booking_nonce', 'nonce' );

		$location_id = absint( $_POST['location_id'] ?? 0 );
		$date        = sanitize_text_field( $_POST['date'] ?? '' );
		$service_id  = absint( $_POST['service_id'] ?? 0 );
		$employee_id = absint( $_POST['employee_id'] ?? 0 );

		if ( ! $location_id || ! $date || ! $service_id || ! $employee_id ) {
			wp_send_json_error( 'Missing required parameters.' );
		}

		// Read flags (only honored for authorized users)
		$flags = $this->read_availability_flags_from_post();

		$slots = $this->call_get_time_slots_for_date( $location_id, $date, $service_id, $employee_id, $flags );

		/**
		 * Filter: allow other plugins to modify slots post-hoc.
		 * Params: $slots, $location_id, $date, $service_id, $employee_id, $flags
		 */
		$slots = apply_filters( 'medsol_filter_time_slots', $slots, $location_id, $date, $service_id, $employee_id, $flags );

		wp_send_json_success( $slots );
	}

	public function submit_booking() {
		check_ajax_referer( 'medsol_booking_nonce', 'nonce' );

		// --- Rate limiting (per IP): 5 attempts / 5 minutes ---
		$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		$key  = 'medsol_rl_' . md5( $ip );
		$max  = (int) apply_filters( 'medsol_rate_limit_max', 5 );
		$ttl  = (int) apply_filters( 'medsol_rate_limit_ttl', 5 * MINUTE_IN_SECONDS );
		$cnt  = (int) get_transient( $key );
		if ( $cnt >= $max ) {
			wp_send_json_error( 'Too many attempts. Please try again later.' );
		}
		set_transient( $key, $cnt + 1, $ttl );

		// --- Normalize request ---
		$post = wp_unslash( $_POST );

		// --- Settings / default status ---
		$settings       = get_option( 'medsol_appointments_settings' );
		$default_status = ( is_array( $settings ) && ! empty( $settings['default_status'] ) ) ? $settings['default_status'] : 'pending';

		// --- Collect & sanitize (do NOT include duration yet) ---
		$data = array(
			'customer_name'  => sanitize_text_field( $post['customer_name'] ?? '' ),
			'customer_email' => sanitize_email( $post['customer_email'] ?? '' ),
			'customer_phone' => sanitize_text_field( $post['customer_phone'] ?? '' ),
			'note'           => sanitize_textarea_field( $post['note'] ?? '' ),
			'employee_id'    => absint( $post['employee_id'] ?? 0 ),
			'service_id'     => absint( $post['service_id'] ?? 0 ),
			'location_id'    => absint( $post['location_id'] ?? 0 ),
			'date'           => sanitize_text_field( $post['date'] ?? '' ), // Y-m-d
			'time'           => sanitize_text_field( $post['time'] ?? '' ), // H:i
			'status'         => $default_status,
		);

		// --- Required fields (excluding duration) ---
		$missing = [];
		foreach ( array( 'customer_name','customer_email','employee_id','service_id','location_id','date','time' ) as $field ) {
			if ( empty( $data[ $field ] ) ) { $missing[] = $field; }
		}
		if ( $missing ) {
			error_log( 'Missing fields in booking submission: ' . implode( ', ', $missing ) );
			wp_send_json_error( 'Missing required fields: ' . implode( ', ', $missing ) );
		}

		// --- Format validation ---
		if ( ! preg_match( '/^[\p{L}\s\'-]+$/u', $data['customer_name'] ) ) {
			wp_send_json_error( 'Invalid name.' );
		}
		if ( ! is_email( $data['customer_email'] ) ) {
			wp_send_json_error( 'Invalid email address.' );
		}
		if ( ! preg_match( '/^[0-9+\-\s().]{6,30}$/', $data['customer_phone'] ) ) {
			wp_send_json_error( 'Invalid phone number.' );
		}

		// --- Date/time sanity & prevent booking in the past ---
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
		$dt = DateTime::createFromFormat( 'Y-m-d H:i', $data['date'] . ' ' . $data['time'], $tz );
		$dt_valid = $dt && $dt->format('Y-m-d') === $data['date'] && $dt->format('H:i') === $data['time'];
		if ( ! $dt_valid ) {
			wp_send_json_error( 'Invalid date/time format.' );
		}
		$now_ts = function_exists( 'current_time' ) ? current_time( 'timestamp' ) : time();
		if ( $dt->getTimestamp() < $now_ts ) {
			wp_send_json_error( 'Date/time is in the past.' );
		}

		// --- Validate service existence explicitly (before duration) ---
		$svc = null;
		if ( class_exists( 'Medsol_Service' ) && method_exists( 'Medsol_Service', 'get' ) ) {
			$svc = Medsol_Service::get( $data['service_id'] );
			if ( empty( $svc ) ) {
				wp_send_json_error( 'Invalid service.' );
			}
		}

		// --- NEW: Validate employee exists ---
		if ( class_exists( 'Medsol_Employee' ) && method_exists( 'Medsol_Employee', 'get' ) ) {
			$emp = Medsol_Employee::get( $data['employee_id'] );
			if ( empty( $emp ) ) {
				wp_send_json_error( 'Invalid employee.' );
			}
		}

		// --- NEW: Validate location exists ---
		if ( class_exists( 'Medsol_Location' ) && method_exists( 'Medsol_Location', 'get' ) ) {
			$loc = Medsol_Location::get( $data['location_id'] );
			if ( empty( $loc ) ) {
				wp_send_json_error( 'Invalid location.' );
			}
		}

		// --- Derive duration (server authoritative) ---
		$server_duration = 0;
		if ( $svc ) {
			if ( is_object( $svc ) && isset( $svc->duration ) ) {
				$server_duration = (int) $svc->duration;
			} elseif ( is_array( $svc ) && ! empty( $svc['duration'] ) ) {
				$server_duration = (int) $svc['duration'];
			}
		} elseif ( class_exists( 'Medsol_Service' ) && method_exists( 'Medsol_Service', 'get_duration' ) ) {
			$server_duration = (int) Medsol_Service::get_duration( $data['service_id'] );
		}

		$client_duration = isset( $post['duration'] ) ? absint( $post['duration'] ) : 0;
		$duration        = $server_duration ?: $client_duration;

		if ( ! $duration ) {
			error_log( 'Medsol: Missing/invalid duration for service_id ' . $data['service_id'] );
			wp_send_json_error( 'Missing or invalid duration for selected service.' );
		}

		if ( $server_duration && $client_duration && $server_duration !== $client_duration ) {
			error_log( sprintf(
				'Medsol: Client duration (%d) overridden by server duration (%d) for service_id %d',
				$client_duration, $server_duration, $data['service_id']
			) );
		}

		$data['duration'] = $duration;

		// --- Server-side slot integrity check (prevents forged times) ---
		if ( method_exists( 'Medsol_Location', 'get_time_slots_for_date' ) ) {
			$slots = Medsol_Location::get_time_slots_for_date(
				$data['location_id'],
				$data['date'],
				$data['service_id'],
				$data['employee_id']
			);
			$valid = false;
			if ( is_array( $slots ) ) {
				foreach ( $slots as $slot ) {
					if ( isset( $slot['start'] ) && $slot['start'] === $data['time'] && (int) ( $slot['capacity'] ?? 1 ) >= 0 ) {
						$valid = true;
						break;
					}
				}
			}
			if ( ! $valid ) {
				wp_send_json_error( 'Selected time slot is no longer available.' );
			}
		}

		// --- Create appointment ---
		$appointment_id = Medsol_Appointment::create( $data );
		if ( $appointment_id && ! is_wp_error( $appointment_id ) ) {
			wp_send_json_success( 'Appointment booked successfully.' );
		} else {
			$msg = is_wp_error( $appointment_id ) ? $appointment_id->get_error_message() : 'Failed to book appointment.';
			wp_send_json_error( $msg );
		}
	}
}
<?php
/**
 * Core plugin class.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Medsol_Appointments class.
 */
class Medsol_Appointments {

	protected static $instance = null;

	protected function __construct() {
		$this->includes();

		if ( class_exists( 'Medsol_Notifications' ) && is_callable( array( 'Medsol_Notifications', 'boot' ) ) ) {
			Medsol_Notifications::boot();
		}

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

		new Medsol_Appointment_Controller();
		new Medsol_Employee_Controller();
		new Medsol_Service_Controller();
		new Medsol_Location_Controller();
		new Medsol_Frontend_Controller();

		add_shortcode( 'medsol_appointment_booking', array( $this, 'render_booking_form' ) );
	}

	public function register_admin_menu() {
		new Medsol_Admin_Menu();
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function includes() {
		// Models.
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/models/class-appointment.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/models/class-employee.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/models/class-service.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/models/class-location.php';

		// Notifications.
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/notifications/class-notifications.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/notifications/class-mailer.php';

		if ( is_admin() ) {
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/class-admin-menu.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/class-appointment-list-table.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/class-employee-list-table.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/class-service-list-table.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/class-location-list-table.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/pages/page-appointments.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/pages/page-employees.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/pages/page-services.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/pages/page-locations.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/pages/page-notifications.php';
			require_once MEDSOL_APPOINTMENTS_PATH . 'includes/admin/pages/page-settings.php';
		}

		// Controllers.
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/controllers/class-appointment-controller.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/controllers/class-employee-controller.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/controllers/class-service-controller.php';
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/controllers/class-location-controller.php';

		// Frontend.
		require_once MEDSOL_APPOINTMENTS_PATH . 'includes/frontend/class-frontend-controller.php';
	}

	public function init() {
		// No textdomain as per user.
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'medsol-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'medsol-appointments-admin', MEDSOL_APPOINTMENTS_URL . 'assets/css/admin.css', array(), MEDSOL_APPOINTMENTS_VERSION );
		wp_enqueue_script( 'medsol-appointments-admin', MEDSOL_APPOINTMENTS_URL . 'assets/js/admin.js', array( 'jquery' ), MEDSOL_APPOINTMENTS_VERSION, true );

		wp_localize_script( 'medsol-appointments-admin', 'medsolAppointments', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'medsol_appointments_nonce' ),
		) );
	}

	public function enqueue_frontend_assets() {
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'medsol_appointment_booking' ) ) {
			wp_enqueue_style( 'medsol-appointments-frontend', MEDSOL_APPOINTMENTS_URL . 'assets/css/frontend.css', array(), MEDSOL_APPOINTMENTS_VERSION );
			wp_enqueue_style( 'flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css' );
			wp_enqueue_script( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '4.6.13', true );
			wp_enqueue_script( 'medsol-appointments-frontend', MEDSOL_APPOINTMENTS_URL . 'assets/js/frontend.js', array( 'jquery' ), MEDSOL_APPOINTMENTS_VERSION, true );

			// Security: Add nonce expiry (optional, 1-hour default)
			$nonce_lifetime = defined( 'NONCE_LIFETIME' ) ? NONCE_LIFETIME : HOUR_IN_SECONDS;
			wp_localize_script( 'medsol-appointments-frontend', 'medsolFrontend', array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'medsol_booking_nonce' ),
				'nonce_lifetime'  => $nonce_lifetime,
			) );
		}
	}

	public function render_booking_form( $atts, $content = '' ) {
		// Parse tokens + flags from shortcode.
		$config = $this->parse_booking_tokens( $atts );

		// If the shortcode requested privileged behavior, restrict who can view the form.
		$requires_caps = ! empty( $config['_flags']['ignore_off_days'] ) || ! empty( $config['_flags']['ignore_availability'] );
		if ( $requires_caps && ! ( current_user_can( 'manage_bookings' ) || current_user_can( 'manage_options' ) ) ) {
			// Hide the form from unauthorized visitors.
			return '<div class="medsol-booking-restricted">You do not have permission to access this booking form.</div>';
		}

		// Ensure required base fields exist (service needed for duration-capacity logic).
		if ( ! isset( $config['service'] ) ) {
			return 'This booking form is not fully configured.';
		}

		// Force calendar & customer fields visible (frontend UX)
		$config['date']                        = array( 'visible' => true, 'id' => 0 );
		$config['customer_name']               = array( 'visible' => true, 'id' => 0 );
		$config['customer_email']              = array( 'visible' => true, 'id' => 0 );
		$config['customer_email_confirmation'] = array( 'visible' => true, 'id' => 0 );
		$config['customer_phone']              = array( 'visible' => true, 'id' => 0 );
		$config['note']                        = array( 'visible' => true, 'id' => 0 );

		// Ensure 'time' node exists & visible so JS can inject slots.
		if ( empty( $config['time'] ) || ! is_array( $config['time'] ) ) {
			$config['time'] = array();
		}
		$config['time']['visible'] = true;

		// Many templates in this codebase expect $fields; mirror $config for compatibility.
		$fields = $config;

		ob_start();
		include MEDSOL_APPOINTMENTS_PATH . 'includes/frontend/template-booking-form.php';
		return ob_get_clean();
	}

	/**
	 * Parse both:
	 * - Normal shortcode attributes (e.g. ignore_off_days="true")
	 * - %form_*% tokens (e.g. %form_service:4:hidden%)
	 */
	private function parse_booking_tokens( $atts ) {
		$config = array();

		// Initialize flags bucket.
		$config['_flags'] = array(
			'ignore_off_days'     => false,
			'ignore_availability' => false,
		);

		// 1) Handle normal shortcode attributes first (ignore_off_days / ignore_availability)
		// Security: Validate as boolean to prevent injection.
		if ( isset( $atts['ignore_off_days'] ) ) {
			$config['_flags']['ignore_off_days'] = filter_var( $atts['ignore_off_days'], FILTER_VALIDATE_BOOLEAN );
			unset( $atts['ignore_off_days'] );
		}
		if ( isset( $atts['ignore_availability'] ) ) {
			$config['_flags']['ignore_availability'] = filter_var( $atts['ignore_availability'], FILTER_VALIDATE_BOOLEAN );
			unset( $atts['ignore_availability'] );
		}

		// 2) If no tokens at all, render full form by default.
		if ( empty( $atts ) ) {
			$defaults = array( 'location', 'service', 'employee', 'date', 'time', 'customer_name', 'customer_email', 'customer_email_confirmation', 'customer_phone', 'note' );
			foreach ( $defaults as $default ) {
				$config[ $default ] = array( 'visible' => true, 'id' => 0 );
			}
			return $config;
		}

		// 3) Parse %form_*% tokens. They might appear as values OR keys depending on how the shortcode is written.
		$maybe_parse_token = function( $token ) use ( &$config ) {
			if ( ! is_string( $token ) ) {
				return;
			}
			if ( strpos( $token, '%form_' ) !== 0 ) {
				return;
			}
			$token = trim( $token, '%' );
			$parts = explode( ':', str_replace( 'form_', '', $token ) );

			$field  = $parts[0];
			$id     = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
			$hidden = isset( $parts[2] ) && $parts[2] === 'hidden';

			$config[ $field ] = array(
				'visible' => ! $hidden,
				'id'      => $id,
			);
		};

		foreach ( $atts as $key => $value ) {
			// Token as value: [shortcode "%form_service:4:hidden%" ...]
			$maybe_parse_token( $value );

			// Token as key (defensive; some builders pass tokens as keys)
			if ( is_string( $key ) ) {
				$maybe_parse_token( $key );
			}
		}

		// 4) Default any unspecified core fields to not visible with id=0
		$defaults = array( 'location', 'service', 'employee', 'date', 'time', 'customer_name', 'customer_email', 'customer_email_confirmation', 'customer_phone', 'note' );
		foreach ( $defaults as $default ) {
			if ( ! isset( $config[ $default ] ) ) {
				$config[ $default ] = array( 'visible' => false, 'id' => 0 );
			}
		}

		return $config;
	}

	public function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Appointments table.
		$sql = "CREATE TABLE {$wpdb->prefix}medsol_appointments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_name VARCHAR(255) NOT NULL,
			customer_email VARCHAR(255) NOT NULL,
			customer_phone VARCHAR(50) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			employee_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			location_id BIGINT UNSIGNED NOT NULL,
			date DATE NOT NULL,
			time TIME NOT NULL,
			duration INT NOT NULL,
			status ENUM('pending', 'approved', 'declined', 'canceled') DEFAULT 'pending',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY employee_id (employee_id),
			KEY service_id (service_id),
			KEY location_id (location_id),
			KEY date (date)
		) $charset_collate;";
		dbDelta( $sql );

		// Employees table.
		$sql = "CREATE TABLE {$wpdb->prefix}medsol_employees (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(255) NOT NULL,
			last_name VARCHAR(255) NOT NULL,
			email VARCHAR(255) NOT NULL,
			phone VARCHAR(50) DEFAULT NULL,
			role VARCHAR(100) DEFAULT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta( $sql );

		// Employee days off table.
		$sql = "CREATE TABLE {$wpdb->prefix}medsol_employee_days_off (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_id BIGINT UNSIGNED NOT NULL,
			reason VARCHAR(255) DEFAULT NULL,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			PRIMARY KEY (id),
			KEY employee_id (employee_id)
		) $charset_collate;";
		dbDelta( $sql );

		// Services table.
		$sql = "CREATE TABLE {$wpdb->prefix}medsol_services (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			duration INT NOT NULL,
			slot_capacity INT DEFAULT 0,
			min_booking_time INT DEFAULT 0,
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta( $sql );

		// Locations table.
		$sql = "CREATE TABLE {$wpdb->prefix}medsol_locations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			address TEXT DEFAULT NULL,
			phone VARCHAR(50) DEFAULT NULL,
			min_booking_time INT DEFAULT 0,
			weekly_availability JSON DEFAULT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta( $sql );

		// Location days off table.
		$sql = "CREATE TABLE {$wpdb->prefix}medsol_location_days_off (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			location_id BIGINT UNSIGNED NOT NULL,
			reason VARCHAR(255) DEFAULT NULL,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			PRIMARY KEY (id),
			KEY location_id (location_id)
		) $charset_collate;";
		dbDelta( $sql );

		update_option( 'medsol_appointments_version', MEDSOL_APPOINTMENTS_VERSION );

		// Add custom role on activation.
		add_role( 'manage_bookings', 'Manage Bookings', array( 'read' => true, 'manage_bookings' => true ) );
	}

	public function deactivate() {
		// Placeholder.
	}
}

/**
 * singleton helper for notifications class.
 * Keeps wiring terse and avoids multiple instantiation.
 */
if ( ! function_exists( 'medsol_notifications' ) ) {
	function medsol_notifications() {
		static $instance = null;
		if ( ! $instance ) {
			$instance = new Medsol_Notifications();
		}
		return $instance;
	}
}
<?php
/**
 * Appointment model.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Medsol_Appointment
 */
class Medsol_Appointment {

	private static $table = 'medsol_appointments';

	/**
	 * Create an appointment.
	 *
	 * @param array $data
	 * @return int|WP_Error Appointment ID on success, WP_Error on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$data = apply_filters( 'medsol_appointment_before_create', $data );

		$required = array( 'customer_name', 'customer_email', 'employee_id', 'service_id', 'location_id', 'date', 'time', 'duration', 'status' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error( 'missing_field', 'Missing required field: ' . $field );
			}
		}

		// Sanitize.
		$data['customer_name']  = sanitize_text_field( $data['customer_name'] );
		$data['customer_email'] = sanitize_email( $data['customer_email'] );
		$data['customer_phone'] = sanitize_text_field( $data['customer_phone'] ?? '' );
		$data['note']           = sanitize_textarea_field( $data['note'] ?? '' );
		$data['employee_id']    = absint( $data['employee_id'] );
		$data['service_id']     = absint( $data['service_id'] );
		$data['location_id']    = absint( $data['location_id'] );
		$data['date']           = sanitize_text_field( $data['date'] ); // Expect 'Y-m-d'
		$data['time']           = sanitize_text_field( $data['time'] ); // Expect 'H:i' or 'H:i:s'
		$data['duration']       = absint( $data['duration'] );
		$data['status']         = in_array( $data['status'], array( 'pending', 'approved', 'declined', 'canceled' ), true ) ? $data['status'] : 'pending';

		// Validate date/time formats (model-level guard).
		$dt_date = DateTime::createFromFormat( 'Y-m-d', $data['date'] );
		if ( ! $dt_date || $dt_date->format( 'Y-m-d' ) !== $data['date'] ) {
			return new WP_Error( 'invalid_date', 'Invalid date format.' );
		}

		$dt_time = DateTime::createFromFormat( 'H:i:s', $data['time'] );
		if ( ! $dt_time ) {
			$dt_time = DateTime::createFromFormat( 'H:i', $data['time'] );
			if ( $dt_time ) {
				// Normalize to H:i:s for consistency with SQL TIME comparisons.
				$data['time'] = $dt_time->format( 'H:i:s' );
			}
		}
		if ( ! $dt_time ) {
			return new WP_Error( 'invalid_time', 'Invalid time format.' );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::$table,
			array(
				'customer_name'  => $data['customer_name'],
				'customer_email' => $data['customer_email'],
				'customer_phone' => $data['customer_phone'],
				'note'           => $data['note'],
				'employee_id'    => $data['employee_id'],
				'service_id'     => $data['service_id'],
				'location_id'    => $data['location_id'],
				'date'           => $data['date'],
				'time'           => $data['time'],
				'duration'       => $data['duration'],
				'status'         => $data['status'],
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( false !== $inserted ) {
			$appointment_id = (int) $wpdb->insert_id;

			/**
			 * Fires after an appointment is created.
			 *
			 * @param int   $appointment_id Newly created appointment ID.
			 * @param array $data           Sanitized data that was inserted.
			 */
			do_action( 'medsol_appointment_created', $appointment_id, $data );

			return $appointment_id;
		}

		// DB error path: log for diagnostics and return WP_Error.
		if ( ! empty( $wpdb->last_error ) ) {
			error_log( 'Medsol insert error: ' . $wpdb->last_error );
		}
		return new WP_Error( 'db_insert_failed', 'Could not create appointment.' );
	}

	/**
	 * Get one appointment by ID.
	 *
	 * @param int $id
	 * @return object|false
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$appointment = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::$table . " WHERE id = %d", $id )
		);

		return $appointment ?: false;
	}

	/**
	 * Query appointments (paginated).
	 *
	 * @param array $args
	 * @return array { appointments: array<object>, total: int }
	 */
	public static function get_all( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'        => '',
			'employee_id'   => 0,
			'customer_name' => '',
			'service_id'    => 0,
			'date_from'     => '',
			'date_to'       => '',
			'status'        => '',
			'paged'         => 1,
			'per_page'      => 20,
			'orderby'       => 'date',
			'order'         => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = 'WHERE 1=1';
		$where_params = array();

		if ( $args['search'] ) {
			$search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s)';
			$where_params[] = $search;
			$where_params[] = $search;
		}

		if ( $args['employee_id'] ) {
			$where .= ' AND employee_id = %d';
			$where_params[] = absint( $args['employee_id'] );
		}

		if ( $args['customer_name'] ) {
			$where .= ' AND customer_name LIKE %s';
			$where_params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['customer_name'] ) ) . '%';
		}

		if ( $args['service_id'] ) {
			$where .= ' AND service_id = %d';
			$where_params[] = absint( $args['service_id'] );
		}

		if ( $args['date_from'] ) {
			$where .= ' AND date >= %s';
			$where_params[] = sanitize_text_field( $args['date_from'] );
		}

		if ( $args['date_to'] ) {
			$where .= ' AND date <= %s';
			$where_params[] = sanitize_text_field( $args['date_to'] );
		}

		if ( $args['status'] ) {
			$where .= ' AND status = %s';
			$where_params[] = sanitize_text_field( $args['status'] );
		}

		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby = $orderby ? "ORDER BY $orderby" : '';

		$limit_str = '';
		$limit_params = array();
		if ( $args['per_page'] > 0 ) {
			$offset = max( 0, ( absint( $args['paged'] ) - 1 ) * absint( $args['per_page'] ) );
			$limit_str = 'LIMIT %d OFFSET %d';
			$limit_params = array( absint( $args['per_page'] ), $offset );
		}

		$params = array_merge( $where_params, $limit_params );

		$query = "SELECT * FROM {$wpdb->prefix}" . self::$table
		       . ( $where ? ' ' . $where : '' )
		       . ( $orderby ? ' ' . $orderby : '' )
		       . ( $limit_str ? ' ' . $limit_str : '' );

		if ( ! empty( $params ) ) {
			$appointments = $wpdb->get_results( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$appointments = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$total_query = "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::$table . ( $where ? ' ' . $where : '' );

		if ( ! empty( $where_params ) ) {
			$total = $wpdb->get_var( $wpdb->prepare( $total_query, $where_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$total = $wpdb->get_var( $total_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return array( 'appointments' => $appointments ?: array(), 'total' => (int) $total );
	}

	/**
	 * Update an appointment.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool True on success (including no-op), false on DB error.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id || ! self::get( $id ) ) {
			return false;
		}

		$data = apply_filters( 'medsol_appointment_before_update', $data, $id );

		// Sanitize + (if provided) validate formats.
		if ( isset( $data['customer_name'] ) )  $data['customer_name']  = sanitize_text_field( $data['customer_name'] );
		if ( isset( $data['customer_email'] ) ) $data['customer_email'] = sanitize_email( $data['customer_email'] );
		if ( isset( $data['customer_phone'] ) ) $data['customer_phone'] = sanitize_text_field( $data['customer_phone'] );
		if ( isset( $data['note'] ) )           $data['note']           = sanitize_textarea_field( $data['note'] );
		if ( isset( $data['employee_id'] ) )    $data['employee_id']    = absint( $data['employee_id'] );
		if ( isset( $data['service_id'] ) )     $data['service_id']     = absint( $data['service_id'] );
		if ( isset( $data['location_id'] ) )    $data['location_id']    = absint( $data['location_id'] );
		if ( isset( $data['duration'] ) )       $data['duration']       = absint( $data['duration'] );
		if ( isset( $data['status'] ) ) {
			$data['status'] = in_array( $data['status'], array( 'pending', 'approved', 'declined', 'canceled' ), true )
				? $data['status']
				: 'pending';
		}
		if ( isset( $data['date'] ) ) {
			$data['date'] = sanitize_text_field( $data['date'] );
			$dt_date = DateTime::createFromFormat( 'Y-m-d', $data['date'] );
			if ( ! $dt_date || $dt_date->format( 'Y-m-d' ) !== $data['date'] ) {
				return false;
			}
		}
		if ( isset( $data['time'] ) ) {
			$data['time'] = sanitize_text_field( $data['time'] );
			$dt_time = DateTime::createFromFormat( 'H:i:s', $data['time'] );
			if ( ! $dt_time ) {
				$dt_time = DateTime::createFromFormat( 'H:i', $data['time'] );
				if ( $dt_time ) {
					$data['time'] = $dt_time->format( 'H:i:s' );
				}
			}
			if ( ! $dt_time ) {
				return false;
			}
		}

		// Formats map.
		$formats = array();
		foreach ( $data as $key => $value ) {
			switch ( $key ) {
				case 'duration':
				case 'employee_id':
				case 'service_id':
				case 'location_id':
					$formats[ $key ] = '%d';
					break;
				default:
					$formats[ $key ] = '%s';
			}
		}

		// Capture old status (for change detection).
		$old = self::get( $id );
		$old_status = '';
		if ( $old ) {
			$old_arr    = is_object( $old ) ? (array) $old : (array) $old;
			$old_status = (string) ( $old_arr['status'] ?? '' );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . self::$table,
			$data,
			array( 'id' => $id ),
			array_values( $formats ),
			array( '%d' )
		);

		if ( false === $updated ) {
			if ( ! empty( $wpdb->last_error ) ) {
				error_log( 'Medsol update error: ' . $wpdb->last_error );
			}
			return false;
		}

		// Fire "updated" action for listeners.
		do_action( 'medsol_appointment_updated', $id, $data );

		// Fire status-changed action if applicable.
		if ( isset( $data['status'] ) ) {
			$new_status = (string) $data['status'];
			if ( $new_status !== $old_status ) {
				/**
				 * Fires when an appointment status changes.
				 *
				 * @param int    $id
				 * @param string $old_status
				 * @param string $new_status
				 */
				do_action( 'medsol_appointment_status_changed', $id, $old_status, $new_status );
			}
		}

		// Return true on success, including when no rows were changed (no-op).
		return $updated !== false;
	}

	/**
	 * Delete an appointment.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		do_action( 'medsol_appointment_before_delete', $id );

		return (bool) $wpdb->delete( $wpdb->prefix . self::$table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Get count of overlapping bookings for a slot.
	 *
	 * @param int    $location_id Location ID.
	 * @param string $date        Date (YYYY-MM-DD).
	 * @param string $start_time  Start time (HH:MM[:SS]).
	 * @param string $end_time    End time (HH:MM[:SS]).
	 * @param int    $service_id  Service ID (optional, if busy by service).
	 * @return int Booked count.
	 */
	public static function get_overlapping_bookings_count( $location_id, $date, $start_time, $end_time, $service_id = 0 ) {
		global $wpdb;

		$settings = get_option( 'medsol_appointments_settings', array() );
		$busy_by  = $settings['busy_slots'] ?? 'location';

		$query = "SELECT COUNT(*) FROM {$wpdb->prefix}medsol_appointments
			WHERE location_id = %d AND date = %s AND status IN ('pending', 'approved')
			AND (
				(time < %s AND ADDTIME(time, SEC_TO_TIME(duration * 60)) > %s) OR
				(time >= %s AND time < %s)
			)";
		$params = array( $location_id, $date, $end_time, $start_time, $start_time, $end_time );

		if ( $busy_by === 'service' && $service_id ) {
			$query   .= ' AND service_id = %d';
			$params[] = $service_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) );
	}
}

<?php
/**
 * Location model.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Medsol_Location
 */
class Medsol_Location {

	private static $table = 'medsol_locations';

	private static $days_off_table = 'medsol_location_days_off';

	public static function create( array $data ) {
		global $wpdb;

		$data = apply_filters( 'medsol_location_before_create', $data );

		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'missing_field', 'Missing required field: name' );
		}

		$data['name']                 = sanitize_text_field( $data['name'] );
		$data['address']              = sanitize_textarea_field( $data['address'] ?? '' );
		$data['phone']                = sanitize_text_field( $data['phone'] ?? '' );
		$data['min_booking_time']     = absint( $data['min_booking_time'] ?? 0 );
		$data['weekly_availability']  = wp_json_encode( $data['weekly_availability'] ?? array() );

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::$table,
			array(
				'name'                => $data['name'],
				'address'             => $data['address'],
				'phone'               => $data['phone'],
				'min_booking_time'    => $data['min_booking_time'],
				'weekly_availability' => $data['weekly_availability'],
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		if ( $inserted ) {
			delete_transient( 'medsol_locations_all' ); // Clear cache on create
			return $wpdb->insert_id;
		}
		return false;
	}

	public static function get( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$location = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::$table . " WHERE id = %d", $id )
		);

		if ( $location ) {
			$location->weekly_availability = json_decode( $location->weekly_availability, true );
			$location->days_off = self::get_days_off( $id );
		}

		return $location ?: false;
	}

	public static function get_all( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'paged'    => 1,
			'per_page' => 20,
			'orderby'  => 'name',
			'order'    => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );

		if ( $args['per_page'] <= 0 && empty( $args['search'] ) ) {
			$transient_key = 'medsol_locations_all';
			$cached = get_transient( $transient_key );
			if ( $cached ) {
				return $cached;
			}
		}

		$where = 'WHERE 1=1';
		$where_params = array();

		if ( $args['search'] ) {
			$search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where .= ' AND (name LIKE %s OR address LIKE %s)';
			$where_params[] = $search;
			$where_params[] = $search;
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

		$query = "SELECT * FROM {$wpdb->prefix}" . self::$table . ( $where ? ' ' . $where : '' ) . ( $orderby ? ' ' . $orderby : '' ) . ( $limit_str ? ' ' . $limit_str : '' );

		if ( ! empty( $params ) ) {
			$locations = $wpdb->get_results( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$locations = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( $locations ) {
			foreach ( $locations as $location ) {
				$location->weekly_availability = json_decode( $location->weekly_availability, true );
			}
		}

		$total_query = "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::$table . ( $where ? ' ' . $where : '' );

		if ( ! empty( $where_params ) ) {
			$total = $wpdb->get_var( $wpdb->prepare( $total_query, $where_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$total = $wpdb->get_var( $total_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$result = array( 'locations' => $locations ?: array(), 'total' => (int) $total );

		if ( $args['per_page'] <= 0 && empty( $args['search'] ) ) {
			set_transient( $transient_key, $result, HOUR_IN_SECONDS ); // Cache 1 hour.
		}

		return $result;
	}

	public static function update( $id, array $data ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id || ! self::get( $id ) ) {
			return false;
		}

		$data = apply_filters( 'medsol_location_before_update', $data, $id );

		if ( isset( $data['name'] ) ) $data['name'] = sanitize_text_field( $data['name'] );
		if ( isset( $data['address'] ) ) $data['address'] = sanitize_textarea_field( $data['address'] );
		if ( isset( $data['phone'] ) ) $data['phone'] = sanitize_text_field( $data['phone'] );
		if ( isset( $data['min_booking_time'] ) ) $data['min_booking_time'] = absint( $data['min_booking_time'] );
		if ( isset( $data['weekly_availability'] ) ) $data['weekly_availability'] = wp_json_encode( $data['weekly_availability'] );

		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[ $key ] = ( 'min_booking_time' === $key ) ? '%d' : '%s';
		}

		$updated = $wpdb->update(
			$wpdb->prefix . self::$table,
			$data,
			array( 'id' => $id ),
			array_values( $formats ),
			array( '%d' )
		);

		if ( $updated !== false ) {
			delete_transient( 'medsol_locations_all' ); // Clear cache on update
		}

		return (bool) $updated;
	}

	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		do_action( 'medsol_location_before_delete', $id );

		$wpdb->delete( $wpdb->prefix . self::$days_off_table, array( 'location_id' => $id ), array( '%d' ) );

		$deleted = (bool) $wpdb->delete( $wpdb->prefix . self::$table, array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			delete_transient( 'medsol_locations_all' ); // Clear cache on delete
		}

		return $deleted;
	}

	public static function add_day_off( $location_id, array $data ) {
		global $wpdb;

		$location_id = absint( $location_id );
		if ( ! $location_id ) {
			return false;
		}

		$data['reason']     = sanitize_text_field( $data['reason'] ?? '' );
		$data['start_date'] = sanitize_text_field( $data['start_date'] );
		$data['end_date']   = sanitize_text_field( $data['end_date'] );

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::$days_off_table,
			array(
				'location_id' => $location_id,
				'reason'      => $data['reason'],
				'start_date'  => $data['start_date'],
				'end_date'    => $data['end_date'],
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			delete_transient( 'medsol_locations_all' ); // Clear cache on day off add
		}

		return $inserted ? $wpdb->insert_id : false;
	}

	public static function get_days_off( $location_id ) {
		global $wpdb;

		$location_id = absint( $location_id );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::$days_off_table . " WHERE location_id = %d", $location_id )
		);
	}

	public static function delete_day_off( $day_off_id ) {
		global $wpdb;

		$day_off_id = absint( $day_off_id );

		$deleted = (bool) $wpdb->delete( $wpdb->prefix . self::$days_off_table, array( 'id' => $day_off_id ), array( '%d' ) );

		if ( $deleted ) {
			delete_transient( 'medsol_locations_all' ); // Clear cache on day off delete
		}

		return $deleted;
	}

	public static function get_available_dates( $location_id, $max_days = null, $args = array() ) {
		if ( $max_days === null ) {
			$max_days = get_option( 'medsol_max_booking_days', 90 );
		}

		// Flags (backwards compatible)
		$args = is_array( $args ) ? $args : array();
		$ignore_off_days     = ! empty( $args['ignore_off_days'] );
		$ignore_availability = ! empty( $args['ignore_availability'] );

		$location = self::get( $location_id );
		if ( ! $location ) {
			return array();
		}

		$available_dates = array();
		$today = new DateTime( 'now' );
		$end   = ( clone $today )->add( new DateInterval( "P{$max_days}D" ) );

		while ( $today <= $end ) {
			$day      = strtolower( $today->format( 'D' ) ); // mon,tue,...
			$date_str = $today->format( 'Y-m-d' );

			$weekly = $location->weekly_availability[ $day ] ?? array();
			$has_weekly_window = ! empty( $weekly['from'] ) && ! empty( $weekly['to'] );

			// If ignoring availability, we allow the date even without a weekly window.
			if ( $ignore_availability || $has_weekly_window ) {
				$is_off = false;

				// Only apply location days-off if NOT ignoring off days.
				if ( ! $ignore_off_days && ! empty( $location->days_off ) ) {
					foreach ( $location->days_off as $off ) {
						if ( $date_str >= $off->start_date && $date_str <= $off->end_date ) {
							$is_off = true;
							break;
						}
					}
				}

				if ( ! $is_off ) {
					$available_dates[] = $date_str;
				}
			}

			$today->add( new DateInterval( 'P1D' ) );
		}

		return $available_dates;
	}

	public static function get_time_slots_for_date( $location_id, $date, $service_id, $employee_id, $args = array() ) {
		// Flags (backwards compatible)
		$args = is_array( $args ) ? $args : array();
		$ignore_off_days     = ! empty( $args['ignore_off_days'] );
		$ignore_availability = ! empty( $args['ignore_availability'] );

		$location = self::get( $location_id );
		$service  = Medsol_Service::get( $service_id );

		// Basic existence checks
		if ( ! $location || ! $service ) {
			return array();
		}

		// Employee off-days: only enforce if NOT ignoring off days
		if ( ! $ignore_off_days && ! Medsol_Employee::is_available_on_date( $employee_id, $date ) ) {
			return array();
		}

		$day    = strtolower( ( new DateTime( $date ) )->format( 'D' ) );
		$weekly = $location->weekly_availability[ $day ] ?? array();

		// If not ignoring availability, require a weekly window.
		if ( ! $ignore_availability && ( empty( $weekly['from'] ) || empty( $weekly['to'] ) ) ) {
			return array();
		}

		// When ignoring availability, use a permissive full-day window.
		$from = $ignore_availability ? '00:00' : $weekly['from'];
		$to   = $ignore_availability ? '23:59' : $weekly['to'];

		$start_time   = new DateTime( $date . ' ' . $from );
		$end_time     = new DateTime( $date . ' ' . $to );
		$duration     = new DateInterval( "PT{$service->duration}M" );
		$min_booking  = max( (int) $location->min_booking_time, (int) $service->min_booking_time ) * 3600; // hours -> seconds
		$now          = time() + $min_booking;

		$slots = array();

		while ( $start_time < $end_time ) {
			$slot_end = ( clone $start_time )->add( $duration );
			if ( $slot_end > $end_time ) {
				break;
			}

			// Respect min booking lead time
			if ( $start_time->getTimestamp() > $now ) {
				$booked_count = Medsol_Appointment::get_overlapping_bookings_count(
					$location_id,
					$date,
					$start_time->format( 'H:i:s' ),
					$slot_end->format( 'H:i:s' ),
					$service_id
				);

				$remaining = (int) $service->slot_capacity - (int) $booked_count;

				// slot_capacity === 0 means unlimited (keep 0 in payload for UI consistency)
				if ( $remaining > 0 || (int) $service->slot_capacity === 0 ) {
					$slots[] = array(
						'start'    => $start_time->format( 'H:i' ),
						'end'      => $slot_end->format( 'H:i' ),
						'capacity' => (int) $service->slot_capacity === 0 ? 0 : $remaining,
					);
				}
			}

			$start_time = $slot_end;
		}

		return $slots;
	}

}
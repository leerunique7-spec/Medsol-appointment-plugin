<?php
/**
 * Service model.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Medsol_Service
 */
class Medsol_Service {

	private static $table = 'medsol_services';

	public static function create( array $data ) {
		global $wpdb;

		$data = apply_filters( 'medsol_service_before_create', $data );

		$required = array( 'name', 'duration' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error( 'missing_field', 'Missing required field: ' . $field );
			}
		}

		$data['name']             = sanitize_text_field( $data['name'] );
		$data['duration']         = absint( $data['duration'] );
		$data['slot_capacity']    = absint( $data['slot_capacity'] ?? 0 );
		$data['min_booking_time'] = absint( $data['min_booking_time'] ?? 0 );

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::$table,
			array(
				'name'             => $data['name'],
				'duration'         => $data['duration'],
				'slot_capacity'    => $data['slot_capacity'],
				'min_booking_time' => $data['min_booking_time'],
			),
			array( '%s', '%d', '%d', '%d' )
		);

		if ( $inserted ) {
			delete_transient( 'medsol_services_all' ); // Clear cache on create
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

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::$table . " WHERE id = %d", $id )
		) ?: false;
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
			$transient_key = 'medsol_services_all';
			$cached = get_transient( $transient_key );
			if ( $cached ) {
				return $cached;
			}
		}

		$where = 'WHERE 1=1';
		$where_params = array();

		if ( $args['search'] ) {
			$search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where .= ' AND name LIKE %s';
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
			$services = $wpdb->get_results( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$services = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$total_query = "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::$table . ( $where ? ' ' . $where : '' );

		if ( ! empty( $where_params ) ) {
			$total = $wpdb->get_var( $wpdb->prepare( $total_query, $where_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$total = $wpdb->get_var( $total_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$result = array( 'services' => $services ?: array(), 'total' => (int) $total );

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

		$data = apply_filters( 'medsol_service_before_update', $data, $id );

		if ( isset( $data['name'] ) ) $data['name'] = sanitize_text_field( $data['name'] );
		if ( isset( $data['duration'] ) ) $data['duration'] = absint( $data['duration'] );
		if ( isset( $data['slot_capacity'] ) ) $data['slot_capacity'] = absint( $data['slot_capacity'] );
		if ( isset( $data['min_booking_time'] ) ) $data['min_booking_time'] = absint( $data['min_booking_time'] );

		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[ $key ] = ( 'name' === $key ) ? '%s' : '%d';
		}

		$updated = $wpdb->update(
			$wpdb->prefix . self::$table,
			$data,
			array( 'id' => $id ),
			array_values( $formats ),
			array( '%d' )
		);

		if ( $updated !== false ) {
			delete_transient( 'medsol_services_all' ); // Clear cache on update
		}

		return (bool) $updated;
	}

	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		do_action( 'medsol_service_before_delete', $id );

		$deleted = (bool) $wpdb->delete( $wpdb->prefix . self::$table, array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			delete_transient( 'medsol_services_all' ); // Clear cache on delete
		}

		return $deleted;
	}
}
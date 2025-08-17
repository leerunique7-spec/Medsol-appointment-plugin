<?php
/**
 * Booking form template.
 *
 * @package Medsol_Appointments
 */

defined( 'ABSPATH' ) || exit;

// Fetch entities (defensive)
$loc_all  = Medsol_Location::get_all( array( 'per_page' => -1 ) );
$svc_all  = Medsol_Service::get_all( array( 'per_page' => -1 ) );
$emp_all  = Medsol_Employee::get_all( array( 'per_page' => -1 ) );

$locations = ( is_array( $loc_all ) && ! empty( $loc_all['locations'] ) && is_array( $loc_all['locations'] ) ) ? $loc_all['locations'] : array();
$services  = ( is_array( $svc_all ) && ! empty( $svc_all['services'] )  && is_array( $svc_all['services'] ) )  ? $svc_all['services']  : array();
$employees = ( is_array( $emp_all ) && ! empty( $emp_all['employees'] ) && is_array( $emp_all['employees'] ) ) ? $emp_all['employees'] : array();

$fields = $config; // From shortcode parser

// Render select field (guard re-declare)
if ( ! function_exists( 'render_select' ) ) {
	function render_select( $name, $options, $label, $config ) {
		if ( empty( $config['visible'] ) ) {
			// If hidden but preselected, send value as hidden input
			if ( ! empty( $config['id'] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $config['id'] ) . '">';
			}
			return;
		}

		echo '<label>' . esc_html( $label ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '-select">';
		echo '<option value="">' . esc_html( 'Select ' . $label ) . '</option>';

		foreach ( $options as $opt ) {
			// If a specific ID is enforced, only show that one
			if ( ! empty( $config['id'] ) && (string) $opt->id !== (string) $config['id'] ) {
				continue;
			}
			$selected = ( ! empty( $config['id'] ) && (string) $config['id'] === (string) $opt->id ) ? ' selected' : '';

			// Only services carry duration; guard existence
			$data_duration = ( $name === 'service_id' && isset( $opt->duration ) )
				? ' data-duration="' . esc_attr( $opt->duration ) . '"'
				: '';

			// Build a safe option label (service name OR employee full name)
			if ( isset( $opt->name ) && $opt->name !== '' ) {
				$option_label = $opt->name;
			} else {
				$fn = isset( $opt->first_name ) ? $opt->first_name : '';
				$ln = isset( $opt->last_name )  ? $opt->last_name  : '';
				$option_label = trim( $fn . ' ' . $ln );
			}

			echo '<option value="' . esc_attr( $opt->id ) . '"' . $selected . $data_duration . '>' . esc_html( $option_label ) . '</option>';
		}

		echo '</select>';
	}
}

// Render input field (guard re-declare)
if ( ! function_exists( 'render_input' ) ) {
	function render_input( $name, $label, $type, $config ) {
		if ( empty( $config['visible'] ) ) {
			return;
		}
		echo '<label>' . esc_html( $label ) . '</label>';
		echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<span class="error" id="' . esc_attr( $name ) . '-error"></span>';
	}
}
?>

<div class="medsol-booking-form">
	<form id="medsol-booking-form">
		<?php
		// Flags from parser (used by JS & submit controller)
		$flags = isset( $fields['_flags'] ) ? $fields['_flags'] : ( $config['_flags'] ?? array() );
		?>
		<?php if ( ! empty( $flags['ignore_off_days'] ) ) : ?>
			<input type="hidden" name="ignore_off_days" value="1">
		<?php endif; ?>
		<?php if ( ! empty( $flags['ignore_availability'] ) ) : ?>
			<input type="hidden" name="ignore_availability" value="1">
		<?php endif; ?>

		<?php render_select( 'location_id', $locations, 'Location', $fields['location'] ); ?>
		<?php render_select( 'service_id',  $services,  'Service',  $fields['service'] ); ?>
		<?php render_select( 'employee_id', $employees, 'Employee', $fields['employee'] ); ?>

		<?php if ( ! empty( $fields['date']['visible'] ) ) : ?>
			<label>Date</label>
			<div id="booking-calendar-container"></div> <!-- Inline calendar -->
			<input type="hidden" name="date" id="selected-date">
			<span class="error" id="date-error"></span>
		<?php endif; ?>

		<?php if ( ! empty( $fields['time']['visible'] ) ) : ?>
			<label>Time Slot</label>
			<div id="time-slots"></div>
			<span class="error" id="time-error"></span>
		<?php endif; ?>

		<?php render_input( 'customer_name',  'Customer Name',  'text',  $fields['customer_name'] ); ?>
		<?php render_input( 'customer_email', 'Customer Email', 'email', $fields['customer_email'] ); ?>
		<?php render_input( 'customer_email_confirmation', 'Confirm Email', 'email', $fields['customer_email_confirmation'] ); ?>
		<?php render_input( 'customer_phone', 'Customer Phone', 'text',  $fields['customer_phone'] ); ?>

		<?php if ( ! empty( $fields['note']['visible'] ) ) : ?>
			<label>Note</label>
			<textarea name="note" id="note"></textarea>
		<?php endif; ?>

		<button type="button" id="submit-booking">Book Appointment</button>
	</form>
	<div id="booking-message"></div>
</div>

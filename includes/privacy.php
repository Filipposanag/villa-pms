<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Privacy / GDPR: ανωνυμοποίηση + WordPress personal data
 * exporter & eraser hooks (με βάση το email του guest).
 * Σημείωση: τεχνικοί μηχανισμοί — όχι νομική πιστοποίηση.
 * --------------------------------------------------------------- */

/** Ανωνυμοποίηση προσωπικών στοιχείων μιας κράτησης (κρατά ημερομηνίες/ποσά για στατιστικά). */
function vpms_anonymize_booking( $id ) {
	return vpms_update_booking( (int) $id, array(
		'guest_name'  => 'Anonymized',
		'guest_email' => '',
		'guest_phone' => '',
		'note'        => '',
	) );
}

/* ---------- Personal data EXPORTER ---------- */
add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
	$exporters['villa-pms'] = array(
		'exporter_friendly_name' => 'Villa PMS Bookings',
		'callback'               => 'vpms_privacy_exporter',
	);
	return $exporters;
} );

function vpms_privacy_exporter( $email, $page = 1 ) {
	global $wpdb;
	$email = sanitize_email( $email );
	$items = array();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}vpms_bookings WHERE guest_email = %s LIMIT 100",
		$email
	) );

	foreach ( $rows as $b ) {
		$items[] = array(
			'group_id'    => 'vpms_bookings',
			'group_label' => 'Villa bookings',
			'item_id'     => 'vpms-booking-' . $b->id,
			'data'        => array(
				array( 'name' => 'Name', 'value' => $b->guest_name ),
				array( 'name' => 'Email', 'value' => $b->guest_email ),
				array( 'name' => 'Phone', 'value' => $b->guest_phone ),
				array( 'name' => 'Check-in', 'value' => $b->checkin ),
				array( 'name' => 'Check-out', 'value' => $b->checkout ),
				array( 'name' => 'Guests', 'value' => $b->guests ),
				array( 'name' => 'Amount', 'value' => vpms_format_money( $b->amount, $b->currency ) ),
				array( 'name' => 'Status', 'value' => $b->status ),
				array( 'name' => 'Privacy consent date', 'value' => (string) $b->privacy_consent_at ),
			),
		);
	}

	return array( 'data' => $items, 'done' => true );
}

/* ---------- Personal data ERASER ---------- */
add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
	$erasers['villa-pms'] = array(
		'eraser_friendly_name' => 'Villa PMS Bookings',
		'callback'             => 'vpms_privacy_eraser',
	);
	return $erasers;
} );

function vpms_privacy_eraser( $email, $page = 1 ) {
	global $wpdb;
	$email = sanitize_email( $email );

	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}vpms_bookings WHERE guest_email = %s LIMIT 100",
		$email
	) );

	$removed = false;
	foreach ( $ids as $id ) {
		vpms_anonymize_booking( $id );
		$removed = true;
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => $removed
			? array( 'Booking personal data was anonymized. Dates and amounts were retained for accounting/statistics.' )
			: array(),
		'done'           => true,
	);
}

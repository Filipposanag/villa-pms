<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Αυτόματα emails στους guests
 *  - Pre-arrival: Χ μέρες πριν το check-in (οδηγίες, WiFi, ώρες)
 *  - Post-departure: την επομένη του check-out (ευχαριστώ + review)
 * Τρέχει καθημερινά μέσω cron. Πιάνει και μέρες που τυχόν χάθηκαν.
 * --------------------------------------------------------------- */

add_action( 'vpms_daily_emails', 'vpms_run_daily_emails' );

function vpms_run_daily_emails() {
	global $wpdb;
	$s     = vpms_get_settings();
	$table = $wpdb->prefix . 'vpms_bookings';
	$today = current_time( 'Y-m-d' );

	// --- Pre-arrival: check-in από σήμερα έως σήμερα+Χ μέρες, δεν έχει σταλεί ---
	$days   = max( 0, (int) $s['prearrival_days'] );
	$target = date( 'Y-m-d', strtotime( "+$days days", current_time( 'timestamp' ) ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table
		 WHERE status = 'paid' AND guest_email != '' AND prearrival_sent = 0
		   AND checkin >= %s AND checkin <= %s", $today, $target
	) );
	foreach ( $rows as $b ) {
		if ( vpms_send_prearrival_email( $b ) ) {
			$wpdb->update( $table, array( 'prearrival_sent' => 1 ), array( 'id' => $b->id ) );
		}
	}

	// --- Thank you: ΜΟΝΟ μετά την ολοκλήρωση της ημέρας checkout ---
	// (checkout < σήμερα, δηλαδή από την επόμενη μέρα και μετά· lookback 3 μέρες)
	$since = date( 'Y-m-d', strtotime( '-3 days', current_time( 'timestamp' ) ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table
		 WHERE status = 'paid' AND guest_email != '' AND thankyou_sent = 0
		   AND checkout < %s AND checkout >= %s", $today, $since
	) );
	foreach ( $rows as $b ) {
		if ( vpms_send_thankyou_email( $b ) ) {
			$wpdb->update( $table, array( 'thankyou_sent' => 1 ), array( 'id' => $b->id ) );
		}
	}
}

function vpms_send_prearrival_email( $b ) {
	$s     = vpms_get_settings();
	$site  = vpms_property_name();
	$date  = date_i18n( 'l j F Y', strtotime( $b->checkin ) );
	$first = trim( strtok( $b->guest_name, ' ' ) );

	$body  = "Dear " . ( $first ?: 'guest' ) . ",\n\n";
	$body .= "Your stay at $site is almost here — we can't wait to welcome you!\n\n";
	$body .= "ARRIVAL\n";
	$body .= "Check-in: from {$s['checkin_time']} on $date\n";
	$body .= "Check-out: by {$s['checkout_time']}\n\n";
	if ( trim( $s['arrival_info'] ) !== '' ) {
		$body .= "HOW TO FIND US\n" . trim( $s['arrival_info'] ) . "\n\n";
	}
	if ( $s['wifi_ssid'] || $s['wifi_pass'] ) {
		$body .= "WIFI\n";
		if ( $s['wifi_ssid'] ) $body .= "Network: {$s['wifi_ssid']}\n";
		if ( $s['wifi_pass'] ) $body .= "Password: {$s['wifi_pass']}\n";
		$body .= "\n";
	}
	if ( $s['host_phone'] ) {
		$body .= "Need anything before or during your stay? Call or message us anytime: {$s['host_phone']}\n\n";
	}
	$body .= "Safe travels — see you soon!\n$site";

	return vpms_mail( $b->guest_email, "Your stay at $site — arrival details", $body );
}

function vpms_send_thankyou_email( $b ) {
	$s     = vpms_get_settings();
	$site  = vpms_property_name();
	$first = trim( strtok( $b->guest_name, ' ' ) );

	$body  = "Dear " . ( $first ?: 'guest' ) . ",\n\n";
	$body .= "Thank you for staying with us at $site — we hope the Ionian treated you well and that you left with memories to keep.\n\n";
	if ( trim( $s['review_link'] ) !== '' ) {
		$body .= "If you enjoyed your stay, it would mean the world to us if you shared a few words:\n";
		$body .= trim( $s['review_link'] ) . "\n\n";
	}
	$body .= "You are always welcome back — returning guests receive our best rate when booking directly.\n\n";
	$body .= "Warm regards,\n$site";

	return vpms_mail( $b->guest_email, "Thank you for staying at $site", $body );
}

/** Δοκιμαστική αποστολή και των δύο email στον ιδιοκτήτη. */
function vpms_send_test_emails() {
	$s = vpms_get_settings();
	$fake = (object) array(
		'guest_name'  => 'Maria Rossi',
		'guest_email' => $s['notify_email'],
		'checkin'     => date( 'Y-m-d', strtotime( '+3 days' ) ),
		'checkout'    => date( 'Y-m-d', strtotime( '+8 days' ) ),
	);
	vpms_send_prearrival_email( $fake );
	vpms_send_thankyou_email( $fake );
}

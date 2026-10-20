<?php
/**
 * Plugin Name: Villa PMS
 * Description: Villa booking system with daily pricing, Stripe payments and iCal sync with Booking.com & Airbnb.
 * Version: 1.2.4
 * Author: Filippos Anagnostopoulos
 * Author URI: https://github.com/Filipposanag
 * Text Domain: villa-pms
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'VPMS_VERSION', '1.2.4' );
define( 'VPMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'VPMS_URL', plugin_dir_url( __FILE__ ) );

require_once VPMS_PATH . 'includes/db.php';
require_once VPMS_PATH . 'includes/ical.php';
require_once VPMS_PATH . 'includes/stripe.php';
require_once VPMS_PATH . 'includes/rest.php';
require_once VPMS_PATH . 'includes/emails.php';
require_once VPMS_PATH . 'includes/stats.php';
require_once VPMS_PATH . 'includes/privacy.php';
require_once VPMS_PATH . 'includes/admin.php';
require_once VPMS_PATH . 'includes/frontend.php';

/* ---------------------------------------------------------------
 * Ενεργοποίηση / αναβάθμιση: πίνακες + προεπιλογές + tokens + cron
 * Ασφαλές να τρέξει πολλές φορές (idempotent), δεν χάνει δεδομένα.
 * --------------------------------------------------------------- */
register_activation_hook( __FILE__, 'vpms_activate' );
function vpms_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	// Κρατήσεις (direct, booking.com, airbnb, χειροκίνητα μπλοκ)
	// dbDelta προσθέτει νέα columns/indexes χωρίς να πειράξει υπάρχοντα δεδομένα.
	dbDelta( "CREATE TABLE {$wpdb->prefix}vpms_bookings (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		checkin DATE NOT NULL,
		checkout DATE NOT NULL,
		guest_name VARCHAR(190) DEFAULT '',
		guest_email VARCHAR(190) DEFAULT '',
		guest_phone VARCHAR(60) DEFAULT '',
		guests SMALLINT UNSIGNED DEFAULT 2,
		amount DECIMAL(10,2) DEFAULT 0,
		currency VARCHAR(8) DEFAULT 'EUR',
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		source VARCHAR(20) NOT NULL DEFAULT 'direct',
		external_uid VARCHAR(190) DEFAULT '',
		stripe_session VARCHAR(190) DEFAULT '',
		stripe_payment_intent VARCHAR(190) DEFAULT '',
		stripe_expires_at DATETIME DEFAULT NULL,
		paid_at DATETIME DEFAULT NULL,
		refund_id VARCHAR(190) DEFAULT '',
		refunded_at DATETIME DEFAULT NULL,
		refund_status VARCHAR(30) DEFAULT '',
		refund_requested_at DATETIME DEFAULT NULL,
		refund_last_error VARCHAR(190) DEFAULT '',
		refund_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		privacy_consent_at DATETIME DEFAULT NULL,
		confirmation_guest_sent TINYINT(1) NOT NULL DEFAULT 0,
		confirmation_owner_sent TINYINT(1) NOT NULL DEFAULT 0,
		confirmation_last_error VARCHAR(190) DEFAULT '',
		prearrival_sent TINYINT(1) NOT NULL DEFAULT 0,
		thankyou_sent TINYINT(1) NOT NULL DEFAULT 0,
		note TEXT,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY dates (checkin, checkout),
		KEY status (status),
		KEY refund_status (refund_status),
		KEY source (source),
		KEY external_uid (external_uid),
		KEY stripe_session (stripe_session)
	) $charset;" );

	// Ημερήσιες τιμές (μόνο οι μέρες που διαφέρουν από την προεπιλογή)
	dbDelta( "CREATE TABLE {$wpdb->prefix}vpms_rates (
		rate_date DATE NOT NULL,
		price DECIMAL(10,2) NOT NULL,
		PRIMARY KEY  (rate_date)
	) $charset;" );

	// Προεπιλεγμένες ρυθμίσεις: συγχώνευση νέων κλειδιών ΧΩΡΙΣ να
	// χαθούν οι υπάρχουσες τιμές του χρήστη.
	$existing = get_option( 'vpms_settings', array() );
	update_option( 'vpms_settings', wp_parse_args( $existing, vpms_default_settings() ) );

	// Κρυπτογραφικά tokens για τα iCal export feeds (ένα ανά κανάλι).
	// Δημιουργούνται μόνο αν λείπουν — δεν αλλάζουν σε κάθε request.
	foreach ( array( 'airbnb', 'booking' ) as $channel ) {
		if ( ! get_option( 'vpms_ical_token_' . $channel ) ) {
			update_option( 'vpms_ical_token_' . $channel, vpms_generate_token(), false );
		}
	}

	if ( ! wp_next_scheduled( 'vpms_ical_sync' ) ) {
		wp_schedule_event( time() + 60, 'vpms_15min', 'vpms_ical_sync' );
	}
	if ( ! wp_next_scheduled( 'vpms_cleanup' ) ) {
		wp_schedule_event( time() + 120, 'hourly', 'vpms_cleanup' );
	}
	if ( ! wp_next_scheduled( 'vpms_daily_emails' ) ) {
		wp_schedule_event( time() + 300, 'twicedaily', 'vpms_daily_emails' );
	}

	// Migration 1.2.2 → 1.2.3: το refund δεν είναι πλέον booking status.
	// Παλιές εγγραφές με status='refund_pending' προέκυπταν από refund σε
	// εξέλιξη· τις μεταφέρουμε ΑΣΦΑΛΩΣ σε status='paid' + refund_status='pending'
	// ώστε να ΜΗΝ ελευθερωθούν κατά λάθος οι ημερομηνίες, και ειδοποιούμε τον
	// διαχειριστή να τις ελέγξει. Ιδιοδύναμο (idempotent): αφού τρέξει, δεν
	// υπάρχουν πια εγγραφές 'refund_pending', οπότε δεν ξανααγγίζει τίποτα.
	$rp_ids = $wpdb->get_col(
		"SELECT id FROM {$wpdb->prefix}vpms_bookings WHERE status = 'refund_pending'"
	);
	if ( $rp_ids ) {
		$wpdb->query(
			"UPDATE {$wpdb->prefix}vpms_bookings
			 SET status = 'paid', refund_status = 'pending'
			 WHERE status = 'refund_pending' AND ( refund_status = '' OR refund_status IS NULL )"
		);
		$already = get_option( 'vpms_refund_migrated', array() );
		if ( ! is_array( $already ) ) $already = array();
		$merged = array_values( array_unique( array_merge(
			array_map( 'intval', $already ),
			array_map( 'intval', $rp_ids )
		) ) );
		if ( count( $merged ) > 50 ) $merged = array_slice( $merged, -50 );
		update_option( 'vpms_refund_migrated', $merged, false );
	}

	update_option( 'vpms_db_version', VPMS_VERSION );
}

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'vpms_ical_sync' );
	wp_clear_scheduled_hook( 'vpms_cleanup' );
	wp_clear_scheduled_hook( 'vpms_daily_emails' );
} );

/** Κρυπτογραφικά ασφαλές, μη προβλέψιμο token. */
function vpms_generate_token() {
	try {
		return bin2hex( random_bytes( 20 ) ); // 40 hex chars
	} catch ( Exception $e ) {
		return wp_generate_password( 40, false, false );
	}
}

/** Προεπιλεγμένες ρυθμίσεις (ενιαία πηγή αλήθειας). */
function vpms_default_settings() {
	return array(
		'property_name'   => get_bloginfo( 'name' ),
		'default_price'   => 150,
		'min_nights'      => 2,
		'max_guests'      => 8,
		'max_nights'      => 60,
		'advance_months'  => 24,
		'cleaning_fee'    => 0,
		'currency'        => 'EUR',
		'stripe_pk'       => '',
		'stripe_sk'       => '',
		'stripe_whsec'    => '',
		'ical_booking'    => '',
		'ical_airbnb'     => '',
		'notify_email'    => get_option( 'admin_email' ),
		'from_email'      => '',
		'checkin_time'    => '15:00',
		'checkout_time'   => '10:00',
		'wifi_ssid'       => '',
		'wifi_pass'       => '',
		'arrival_info'    => '',
		'review_link'     => '',
		'host_phone'      => '',
		'prearrival_days' => 3,
	);
}

/* Cron κάθε 15 λεπτά */
add_filter( 'cron_schedules', function ( $s ) {
	$s['vpms_15min'] = array( 'interval' => 900, 'display' => 'Every 15 minutes (Villa PMS)' );
	return $s;
} );

add_action( 'vpms_ical_sync', 'vpms_run_ical_import' );

/* Lifecycle ληγμένων pending κρατήσεων (ενιαίο με το Stripe expiration):
 * 1) pending → 'expired' ΜΟΝΟ αφού περάσει το Stripe session expiration
 *    (stripe_expires_at, ~31') + 10 λεπτά grace period για καθυστερημένα
 *    webhooks. Fallback χωρίς αποθηκευμένο expiration: created_at + 41'.
 * 2) expired > 7 ημέρες → οριστική διαγραφή (χρόνος για Stripe webhook
 *    retries και diagnostics). paid / cancelled / payment_conflict /
 *    reconciliation records ΔΕΝ διαγράφονται ποτέ αυτόματα (ακόμη και με
 *    refund_status σε εξέλιξη — το refund είναι ξεχωριστό από το status).
 * 3) Επαν-αποστολή confirmation emails που απέτυχαν + refund reconciliation. */
add_action( 'vpms_cleanup', function () {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}vpms_bookings SET status = 'expired'
		 WHERE status = 'pending'
		   AND COALESCE(stripe_expires_at, created_at + INTERVAL 31 MINUTE) + INTERVAL 10 MINUTE < %s",
		$now
	) );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}vpms_bookings
		 WHERE status = 'expired' AND created_at < %s",
		gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
	) );
	if ( function_exists( 'vpms_retry_failed_confirmations' ) ) vpms_retry_failed_confirmations();
	if ( function_exists( 'vpms_reconcile_pending_refunds' ) ) vpms_reconcile_pending_refunds();
} );

/* Βοηθητικό: ρυθμίσεις */
function vpms_get_settings() {
	return wp_parse_args( get_option( 'vpms_settings', array() ), vpms_default_settings() );
}

/** Όνομα καταλύματος (setting με fallback στο όνομα του site). */
function vpms_property_name() {
	$s = vpms_get_settings();
	return trim( (string) $s['property_name'] ) !== '' ? $s['property_name'] : get_bloginfo( 'name' );
}

/* Αναβάθμιση σχήματος βάσης όταν ενημερώνεται το plugin */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'vpms_db_version' ) !== VPMS_VERSION ) {
		vpms_activate();
	}
} );

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * REST API για το frontend ημερολόγιο & τη φόρμα κράτησης
 * --------------------------------------------------------------- */
add_action( 'rest_api_init', function () {

	// GET /wp-json/vpms/v1/availability?from=2026-07-01&to=2026-09-01
	register_rest_route( 'vpms/v1', '/availability', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$from = sanitize_text_field( $req->get_param( 'from' ) );
			$to   = sanitize_text_field( $req->get_param( 'to' ) );
			if ( ! vpms_valid_date( $from ) || ! vpms_valid_date( $to ) || $to <= $from ) {
				return new WP_REST_Response( array( 'error' => 'bad dates' ), 400 );
			}
			// Όριο 6 μηνών ανά αίτημα
			if ( ( new DateTime( $from ) )->diff( new DateTime( $to ) )->days > 190 ) {
				return new WP_REST_Response( array( 'error' => 'range too large' ), 400 );
			}

			$s        = vpms_get_settings();
			$occupied = vpms_get_occupied_nights( $from, $to );
			$prices   = vpms_get_prices( $from, $to );
			$today    = current_time( 'Y-m-d' );
			// Τέλος επιτρεπόμενου booking window — μέρες πέρα από αυτό
			// εμφανίζονται μη διαθέσιμες ήδη από το ημερολόγιο.
			$window_end = ( new DateTime( $today ) )
				->modify( '+' . max( 1, (int) $s['advance_months'] ) . ' months' )
				->format( 'Y-m-d' );

			$days = array();
			foreach ( $prices as $date => $price ) {
				$days[ $date ] = array(
					'price'     => $price,
					'available' => ! isset( $occupied[ $date ] ) && $date >= $today && $date < $window_end,
				);
			}
			return array(
				'days'               => $days,
				'min_nights'         => (int) $s['min_nights'],
				'max_nights'         => (int) $s['max_nights'],
				'max_guests'         => (int) $s['max_guests'],
				'cleaning_fee'       => (float) $s['cleaning_fee'],
				'currency'           => strtoupper( $s['currency'] ),
				'booking_window_end' => $window_end,
			);
		},
	) );

	// POST /wp-json/vpms/v1/book  → δημιουργεί pending κράτηση + Stripe URL
	register_rest_route( 'vpms/v1', '/book', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'vpms_rest_book',
	) );
} );

/* ---------------------------------------------------------------
 * Rate limiting στο δημόσιο booking endpoint.
 * Δεν αποθηκεύεται IP σε αναγνώσιμη μορφή — μόνο keyed hash (HMAC με
 * το salt του site), σε transient που λήγει μόνο του. Όριο αρκετά
 * ψηλό ώστε να μην ενοχλεί φυσιολογικούς επισκέπτες.
 * --------------------------------------------------------------- */
function vpms_rate_limit_exceeded() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	if ( $ip === '' ) return false;

	$key   = 'vpms_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 24 );
	$count = (int) get_transient( $key );
	if ( $count >= 10 ) return true; // max 10 προσπάθειες / 15 λεπτά

	set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
	return false;
}

function vpms_rest_book( WP_REST_Request $req ) {
	// Nonce για βασική προστασία CSRF
	$nonce = $req->get_header( 'x-wp-nonce' );
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_REST_Response( array( 'error' => 'Your session expired. Please refresh the page.' ), 403 );
	}

	if ( vpms_rate_limit_exceeded() ) {
		return new WP_REST_Response( array( 'error' => 'Too many booking attempts. Please wait a few minutes and try again.' ), 429 );
	}

	$s        = vpms_get_settings();
	$checkin  = sanitize_text_field( $req->get_param( 'checkin' ) );
	$checkout = sanitize_text_field( $req->get_param( 'checkout' ) );
	$name     = sanitize_text_field( $req->get_param( 'name' ) );
	$email    = sanitize_email( $req->get_param( 'email' ) );
	$phone    = sanitize_text_field( $req->get_param( 'phone' ) );
	$guests   = (int) $req->get_param( 'guests' );
	$privacy  = (bool) $req->get_param( 'privacy_accepted' );

	// --- Αυστηρό validation ---
	if ( ! vpms_valid_date( $checkin ) || ! vpms_valid_date( $checkout ) || $checkout <= $checkin ) {
		return new WP_REST_Response( array( 'error' => 'Invalid dates.' ), 400 );
	}
	$today = current_time( 'Y-m-d' );
	if ( $checkin < $today ) {
		return new WP_REST_Response( array( 'error' => 'The selected dates are in the past.' ), 400 );
	}

	// Επιτρεπτό διάστημα: έως Χ μήνες μπροστά (default 24)
	$horizon = ( new DateTime( $today ) )->modify( '+' . max( 1, (int) $s['advance_months'] ) . ' months' )->format( 'Y-m-d' );
	if ( $checkout > $horizon ) {
		return new WP_REST_Response( array( 'error' => 'Bookings can be made up to ' . (int) $s['advance_months'] . ' months in advance.' ), 400 );
	}

	$nights = ( new DateTime( $checkin ) )->diff( new DateTime( $checkout ) )->days;
	if ( $nights < (int) $s['min_nights'] ) {
		return new WP_REST_Response( array( 'error' => 'Minimum stay: ' . (int) $s['min_nights'] . ' nights.' ), 400 );
	}
	if ( $nights > max( 1, (int) $s['max_nights'] ) ) {
		return new WP_REST_Response( array( 'error' => 'Maximum stay: ' . (int) $s['max_nights'] . ' nights.' ), 400 );
	}

	if ( $guests < 1 || $guests > (int) $s['max_guests'] ) {
		return new WP_REST_Response( array( 'error' => 'Guests must be between 1 and ' . (int) $s['max_guests'] . '.' ), 400 );
	}
	if ( ! $name || mb_strlen( $name ) > 190 || ! is_email( $email ) ) {
		return new WP_REST_Response( array( 'error' => 'Please enter your name and a valid email.' ), 400 );
	}
	if ( mb_strlen( $phone ) > 60 ) {
		return new WP_REST_Response( array( 'error' => 'Phone number is too long.' ), 400 );
	}
	if ( ! $privacy ) {
		return new WP_REST_Response( array( 'error' => 'Please accept the privacy policy to continue.' ), 400 );
	}

	// --- Ατομική δημιουργία: database lock → re-check → server-side ποσό → insert ---
	// Δύο ταυτόχρονα requests για τις ίδιες μέρες: μόνο το ένα περνά, το άλλο παίρνει 409.
	$booking_id = vpms_create_booking_atomic( array(
		'checkin' => $checkin, 'checkout' => $checkout,
		'guest_name' => $name, 'guest_email' => $email, 'guest_phone' => $phone,
		'guests' => $guests, 'currency' => strtoupper( $s['currency'] ),
		'status' => 'pending', 'source' => 'direct',
		'privacy_consent_at' => gmdate( 'Y-m-d H:i:s' ),
	) );

	if ( is_wp_error( $booking_id ) ) {
		$code = $booking_id->get_error_code() === 'conflict' ? 409 : 500;
		return new WP_REST_Response( array( 'error' => $booking_id->get_error_message() ), $code );
	}

	$url = vpms_stripe_create_session( $booking_id );
	if ( is_wp_error( $url ) ) {
		// Αποδέσμευση: δεν κρατάμε τις μέρες 30 λεπτά χωρίς checkout URL.
		vpms_delete_booking( $booking_id );
		return new WP_REST_Response(
			array( 'error' => 'We could not start the payment process. Your dates have not been reserved — please try again.' ),
			502
		);
	}

	return array( 'checkout_url' => $url );
}

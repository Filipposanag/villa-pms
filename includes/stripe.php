<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Stripe (χωρίς SDK — απευθείας HTTP κλήσεις)
 * --------------------------------------------------------------- */

/**
 * Δημιουργία Stripe Checkout Session. Επιστρέφει URL ή WP_Error.
 * Σε αποτυχία, ο caller ΠΡΕΠΕΙ να διαγράψει το pending booking
 * (γίνεται στο rest.php) ώστε να μην μένουν οι μέρες δεσμευμένες.
 */
function vpms_stripe_create_session( $booking_id ) {
	$s = vpms_get_settings();
	$b = vpms_get_booking( $booking_id );
	if ( ! $b ) return new WP_Error( 'not_found', 'Booking not found.' );
	if ( empty( $s['stripe_sk'] ) ) return new WP_Error( 'no_key', 'Online payment is not configured yet (missing Stripe Secret Key in settings).' );

	$return_page = wp_get_referer() ? wp_get_referer() : home_url();
	$return_page = remove_query_arg( array( 'vpms_success', 'vpms_cancel', 'session_id' ), $return_page );

	$nights = ( new DateTime( $b->checkin ) )->diff( new DateTime( $b->checkout ) )->days;
	$desc   = sprintf( '%d nights, %s to %s',
		$nights,
		date_i18n( 'd/m/Y', strtotime( $b->checkin ) ),
		date_i18n( 'd/m/Y', strtotime( $b->checkout ) )
	);

	$params = array(
		'mode'                        => 'payment',
		// Μόνο άμεσες card-based πληρωμές (incl. Apple/Google Pay στα card
		// rails) — όχι delayed bank/debit/voucher methods σε αυτή την έκδοση.
		'payment_method_types[0]'     => 'card',
		// Session λήγει στα ~31' (Stripe minimum 30' + clock-skew margin).
		// Το τοπικό hold κρατάει έως ΤΟ ΑΠΟΘΗΚΕΥΜΕΝΟ expiration + 10' grace,
		// άρα δεν υπάρχει παράθυρο όπου το session πληρώνεται με τις μέρες
		// ήδη ελεύθερες.
		'expires_at'                  => time() + 31 * MINUTE_IN_SECONDS,
		'success_url'                 => add_query_arg( array( 'vpms_success' => 1, 'session_id' => '{CHECKOUT_SESSION_ID}' ), $return_page ),
		'cancel_url'                  => add_query_arg( 'vpms_cancel', 1, $return_page ),
		'customer_email'              => $b->guest_email,
		'client_reference_id'         => $b->id,
		'metadata[booking_id]'        => $b->id,
		'line_items[0][quantity]'     => 1,
		'line_items[0][price_data][currency]'              => strtolower( $b->currency ),
		'line_items[0][price_data][unit_amount]'           => (int) round( $b->amount * 100 ),
		'line_items[0][price_data][product_data][name]'    => vpms_property_name() . ' — Villa Booking',
		'line_items[0][price_data][product_data][description]' => $desc,
	);

	$resp = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $s['stripe_sk'],
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
		'body' => $params,
	) );

	if ( is_wp_error( $resp ) ) return $resp;
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( empty( $data['url'] ) || empty( $data['id'] ) ) {
		$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown Stripe error.';
		return new WP_Error( 'stripe', $msg );
	}

	// Αποθήκευση session ID + ΠΡΑΓΜΑΤΙΚΟΥ expires_at από το Stripe response,
	// με έλεγχο επιτυχίας. Αν αποτύχει, δεν επιτρέπεται να μείνει ενεργό
	// «ορφανό» Stripe Session: το λήγουμε server-side και γυρνάμε WP_Error
	// (το REST endpoint θα διαγράψει με ασφάλεια το pending booking).
	$expires_at = ! empty( $data['expires_at'] ) && is_numeric( $data['expires_at'] )
		? gmdate( 'Y-m-d H:i:s', (int) $data['expires_at'] )
		: gmdate( 'Y-m-d H:i:s', time() + 31 * MINUTE_IN_SECONDS );

	$saved = vpms_update_booking( $booking_id, array(
		'stripe_session'    => $data['id'],
		'stripe_expires_at' => $expires_at,
	) );
	if ( false === $saved ) {
		wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $data['id'] ) . '/expire',
			array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $s['stripe_sk'] ) )
		);
		return new WP_Error( 'db', 'Could not link the payment session to the booking. The session was cancelled — please try again.' );
	}
	return $data['url'];
}

/** Ανάκτηση session από Stripe (fallback επιβεβαίωσης χωρίς webhook). */
function vpms_stripe_get_session( $session_id ) {
	$s = vpms_get_settings();
	if ( empty( $s['stripe_sk'] ) ) return null;
	$resp = wp_remote_get(
		'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $session_id ),
		array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $s['stripe_sk'] ) )
	);
	if ( is_wp_error( $resp ) ) return null;
	return json_decode( wp_remote_retrieve_body( $resp ), true );
}

/**
 * ΚΟΙΝΗ function πλήρους server-side επαλήθευσης πληρωμής.
 * Χρησιμοποιείται ΚΑΙ από το webhook ΚΑΙ από το success-page fallback,
 * ώστε καμία διαδρομή να μην είναι πιο "χαλαρή" από την άλλη.
 * Επιστρέφει true μόνο αν ΟΛΑ ταιριάζουν.
 */
function vpms_verify_session_pays_booking( $booking, $session ) {
	return vpms_session_matches_booking( $booking, $session )
		&& ( $session['payment_status'] ?? '' ) === 'paid';
}

/** Έλεγχος ταυτότητας session↔κράτησης + ποσού + νομίσματος (χωρίς payment_status). */
function vpms_session_matches_booking( $booking, $session ) {
	if ( ! $booking || ! is_array( $session ) ) return false;

	// 1. Το session ID ταιριάζει με το αποθηκευμένο στην κράτηση
	//    (άρα ένα session δεν μπορεί να "πληρώσει" διαφορετική κράτηση).
	if ( empty( $session['id'] ) || empty( $booking->stripe_session )
		|| ! hash_equals( (string) $booking->stripe_session, (string) $session['id'] ) ) {
		return false;
	}

	// 2. Το booking ID στα metadata / client_reference_id δείχνει στην ίδια κράτηση.
	$meta_bid = isset( $session['metadata']['booking_id'] ) ? (int) $session['metadata']['booking_id'] : 0;
	$ref_bid  = isset( $session['client_reference_id'] ) ? (int) $session['client_reference_id'] : 0;
	if ( $meta_bid !== (int) $booking->id && $ref_bid !== (int) $booking->id ) {
		return false;
	}

	// 3. Ποσό ακριβώς ίσο με την κράτηση, σε cents.
	$expected_cents = (int) round( ( (float) $booking->amount ) * 100 );
	if ( ! isset( $session['amount_total'] ) || (int) $session['amount_total'] !== $expected_cents ) {
		return false;
	}

	// 4. Νόμισμα ίδιο με της κράτησης.
	if ( strtolower( $session['currency'] ?? '' ) !== strtolower( $booking->currency ) ) {
		return false;
	}

	return true;
}

/**
 * Επισήμανση κράτησης ως πληρωμένης ΜΕΤΑ από πλήρη επαλήθευση.
 * Επιστρέφει διακριτό κωδικό αποτελέσματος ώστε το webhook να μπορεί
 * να απαντήσει με σωστό HTTP status (όχι τυφλό 200):
 *   'paid'           → η μετάβαση έγινε τώρα (emails στάλθηκαν μία φορά)
 *   'already'        → ήδη πληρωμένη (idempotent, OK)
 *   'awaiting_async' → session/κράτηση ταιριάζουν αλλά payment_status
 *                      όχι ακόμη 'paid' (asynchronous μέθοδος πληρωμής)
 *   'not_found'      → δεν υπάρχει τέτοια κράτηση
 *   'verify_failed'  → session/ποσό/νόμισμα δεν ταιριάζουν
 *   'state_conflict' → η μετάβαση απέτυχε (π.χ. cancelled/refunded)
 */
function vpms_mark_paid_verified( $booking_id, $session ) {
	$b = vpms_get_booking( $booking_id );
	if ( ! $b ) return 'not_found';

	// Η επαλήθευση session↔κράτησης γίνεται ΠΡΙΝ από οποιαδήποτε
	// idempotent απάντηση: ένα άσχετο Stripe Session δεν παίρνει ποτέ
	// 'already' απλώς επειδή η κράτηση είναι ήδη πληρωμένη.
	if ( ! vpms_session_matches_booking( $b, $session ) ) return 'verify_failed';

	if ( $b->status === 'paid' ) return 'already'; // idempotent — ΜΕΤΑ το verification

	// Ήδη καταχωρημένο ως payment_conflict: επαληθευμένη πληρωμή που ξαναφτάνει
	// (Stripe retry) ΔΕΝ πρέπει να δημιουργεί δεύτερο conflict log ή να αλλάζει
	// ξανά την κράτηση — idempotent αποτέλεσμα (το webhook απαντά 200).
	if ( $b->status === 'payment_conflict' ) return 'payment_conflict';

	// Asynchronous payment methods: ΔΕΝ επιβεβαιώνουμε πριν γίνει 'paid'.
	if ( ( $session['payment_status'] ?? '' ) !== 'paid' ) return 'awaiting_async';

	$intent = ( ! empty( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) )
		? $session['payment_intent'] : '';

	// Expired κράτηση: η πληρωμή είναι πραγματική, αλλά οι μέρες μπορεί να
	// έχουν ξαναδοθεί — availability reconciliation κάτω από το global lock.
	if ( $b->status === 'expired' ) {
		return vpms_recover_expired_payment( $b, $intent );
	}

	// Κανονική ατομική μετάβαση: ΜΟΝΟ από pending.
	if ( ! vpms_transition_to_paid( $booking_id, $intent ) ) {
		$b = vpms_get_booking( $booking_id );
		return ( $b && $b->status === 'paid' ) ? 'already' : 'state_conflict';
	}

	$b = vpms_get_booking( $booking_id );
	vpms_send_confirmation_emails( $b );
	return 'paid';
}

/**
 * Πληρωμή που έφτασε σε expired κράτηση (καθυστερημένο webhook):
 * κάτω από το global booking lock ελέγχεται αν οι μέρες είναι ακόμη
 * ελεύθερες. Αν ναι → ασφαλής μετάβαση σε paid. Αν όχι → η πληρωμή ΔΕΝ
 * αγνοείται: αποθηκεύεται ως 'payment_conflict' (με intent/session),
 * ΧΩΡΙΣ confirmation email, με σοβαρό admin notice — απαιτείται
 * refund/reconciliation από τον διαχειριστή.
 */
function vpms_recover_expired_payment( $b, $intent ) {
	global $wpdb;
	if ( ! vpms_acquire_booking_lock( 10 ) ) return 'state_conflict';
	try {
		$overlaps = vpms_overlapping_active_ids( $b->checkin, $b->checkout, $b->id );

		if ( empty( $overlaps ) ) {
			$affected = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}vpms_bookings
				 SET status = 'paid', paid_at = %s, stripe_payment_intent = %s
				 WHERE id = %d AND status = 'expired'",
				gmdate( 'Y-m-d H:i:s' ), sanitize_text_field( (string) $intent ), (int) $b->id
			) );
			if ( (int) $affected !== 1 ) {
				$b2 = vpms_get_booking( $b->id );
				return ( $b2 && $b2->status === 'paid' ) ? 'already' : 'state_conflict';
			}
			$b2 = vpms_get_booking( $b->id );
			vpms_send_confirmation_emails( $b2 );
			return 'paid';
		}

		// Σύγκρουση: οι μέρες έχουν ξαναδοθεί. ΟΧΙ paid, ΟΧΙ email.
		// Το UPDATE ελέγχεται: αν αποτύχει στη βάση (false) → 'state_conflict'
		// (non-2xx) ώστε το Stripe να ξαναστείλει το webhook. Conflict log
		// γράφεται ΜΟΝΟ όταν όντως έγινε η μετάβαση (affected === 1).
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}vpms_bookings
			 SET status = 'payment_conflict', stripe_payment_intent = %s
			 WHERE id = %d AND status = 'expired'",
			sanitize_text_field( (string) $intent ), (int) $b->id
		) );

		if ( false === $affected ) {
			// Αποτυχία βάσης — μην «καταπιείς» την πληρωμή· ζήτα retry.
			return 'state_conflict';
		}
		if ( (int) $affected === 1 ) {
			$log   = get_option( 'vpms_payment_conflicts', array() );
			if ( ! is_array( $log ) ) $log = array();
			$log[] = array(
				'time'        => current_time( 'mysql' ),
				'booking'     => (int) $b->id,
				'dates'       => $b->checkin . ' → ' . $b->checkout,
				'overlapping' => array_slice( $overlaps, 0, 5 ),
			);
			if ( count( $log ) > 50 ) $log = array_slice( $log, -50 );
			update_option( 'vpms_payment_conflicts', $log, false );
			return 'payment_conflict';
		}

		// affected === 0: κάποιος άλλος το μετέβαλε ενδιάμεσα. Idempotent:
		// αν είναι ήδη payment_conflict → ίδιο αποτέλεσμα, ΧΩΡΙΣ δεύτερο log.
		$b2 = vpms_get_booking( $b->id );
		if ( $b2 && $b2->status === 'payment_conflict' ) return 'payment_conflict';
		if ( $b2 && $b2->status === 'paid' ) return 'already';
		return 'state_conflict';
	} finally {
		vpms_release_booking_lock();
	}
}

/* ---------------------------------------------------------------
 * Webhook: https://yoursite.com/wp-json/vpms/v1/stripe-webhook
 * Stripe Dashboard > Developers > Webhooks, event: checkout.session.completed
 * --------------------------------------------------------------- */
add_action( 'rest_api_init', function () {
	register_rest_route( 'vpms/v1', '/stripe-webhook', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // η επαλήθευση γίνεται με υπογραφή
		'callback'            => 'vpms_stripe_webhook',
	) );
} );

function vpms_stripe_webhook( WP_REST_Request $req ) {
	$s       = vpms_get_settings();
	$payload = $req->get_body();
	$sig     = $req->get_header( 'stripe-signature' );

	// Χωρίς ρυθμισμένο signing secret ΔΕΝ επεξεργαζόμαστε κανένα event.
	if ( empty( $s['stripe_whsec'] ) ) {
		update_option( 'vpms_webhook_unverified_hit', current_time( 'mysql' ), false );
		return new WP_REST_Response(
			array( 'error' => 'Webhook signing secret is not configured. Event rejected.' ),
			503
		);
	}

	// Η επαλήθευση υπογραφής γίνεται ΠΡΙΝ από οποιαδήποτε επεξεργασία του payload.
	if ( ! vpms_stripe_verify_signature( $payload, $sig, $s['stripe_whsec'] ) ) {
		return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
	}

	$event = json_decode( $payload, true );
	$type  = isset( $event['type'] ) ? (string) $event['type'] : '';

	// Άσχετα event types: 200 χωρίς επεξεργασία.
	$payment_events = array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' );
	if ( $type !== 'checkout.session.expired' && ! in_array( $type, $payment_events, true ) ) {
		return new WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
	}

	$session = isset( $event['data']['object'] ) && is_array( $event['data']['object'] )
		? $event['data']['object'] : array();
	$bid = isset( $session['metadata']['booking_id'] ) ? (int) $session['metadata']['booking_id'] : 0;
	if ( ! $bid && isset( $session['client_reference_id'] ) ) {
		$bid = (int) $session['client_reference_id'];
	}

	// Session έληξε στο Stripe. ΔΕΝ ελευθερώνουμε ΑΜΕΣΩΣ τις μέρες: το τοπικό
	// hold (stripe_expires_at + 10' grace) πρέπει να τηρηθεί, ώστε ένα
	// καθυστερημένο checkout.session.completed να μπορεί ακόμη να κάνει την
	// κράτηση paid χωρίς να έχουν ελευθερωθεί οι ημερομηνίες. Το hourly
	// cleanup κάνει pending → expired μετά το expiration + grace. Idempotent 200.
	if ( $type === 'checkout.session.expired' ) {
		if ( ! $bid ) {
			vpms_webhook_log( $type, 0, 'missing_booking_reference' );
			return new WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
		}
		$b = vpms_get_booking( $bid );
		if ( ! $b ) {
			vpms_webhook_log( $type, $bid, 'not_found' );
			return new WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
		}
		// Το session του event πρέπει να ταιριάζει με το αποθηκευμένο — άσχετο
		// ή παλιό Stripe Session ΔΕΝ αγγίζει την κράτηση.
		$evt_sid = isset( $session['id'] ) ? (string) $session['id'] : '';
		if ( $evt_sid === '' || empty( $b->stripe_session )
			|| ! hash_equals( (string) $b->stripe_session, $evt_sid ) ) {
			vpms_webhook_log( $type, $bid, 'session_mismatch' );
			return new WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
		}
		// Ήδη paid/refunded/cancelled/payment_conflict → idempotent, καμία αλλαγή.
		if ( $b->status !== 'pending' ) {
			vpms_webhook_log( $type, $bid, 'noop_' . sanitize_key( (string) $b->status ) );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		// pending: ΔΕΝ αλλάζουμε status εδώ — παραμένει pending και μπλοκάρει
		// μέχρι stripe_expires_at + 10'. Μόνο diagnostic.
		vpms_webhook_log( $type, $bid, 'expired_noted_pending' );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	// Payment events: HTTP 200 ΜΟΝΟ αν το event καταχωρήθηκε ή ήταν idempotent.
	if ( ! $bid ) {
		vpms_webhook_log( $type, 0, 'missing_booking_reference' );
		return new WP_REST_Response( array( 'error' => 'Missing booking reference in event.' ), 400 );
	}

	$result = vpms_mark_paid_verified( $bid, $session );
	vpms_webhook_log( $type, $bid, $result );

	switch ( $result ) {
		case 'paid':
		case 'already':
		case 'payment_conflict': // καταχωρήθηκε ως conflict — απαιτεί admin ενέργεια, όχι retry
			return new WP_REST_Response( array( 'received' => true, 'result' => $result ), 200 );
		case 'awaiting_async':
			// Αναμένεται async_payment_succeeded αργότερα — αποδεκτό.
			return new WP_REST_Response( array( 'received' => true, 'result' => $result ), 200 );
		case 'not_found':
			return new WP_REST_Response( array( 'error' => 'Booking not found.' ), 404 );
		case 'verify_failed':
			return new WP_REST_Response( array( 'error' => 'Session verification failed.' ), 400 );
		default: // state_conflict ή αποτυχία βάσης
			return new WP_REST_Response( array( 'error' => 'Booking could not be updated.' ), 500 );
	}
}

/** Ασφαλές webhook log: μόνο event type, booking ID, αποτέλεσμα — ποτέ payload/PII/secrets. */
function vpms_webhook_log( $type, $booking_id, $result ) {
	$log   = get_option( 'vpms_webhook_log', array() );
	$log[] = array(
		'time'    => current_time( 'mysql' ),
		'type'    => sanitize_text_field( $type ),
		'booking' => (int) $booking_id,
		'result'  => sanitize_text_field( (string) $result ),
	);
	if ( count( $log ) > 20 ) $log = array_slice( $log, -20 );
	update_option( 'vpms_webhook_log', $log, false );
}

function vpms_stripe_verify_signature( $payload, $sig_header, $secret ) {
	if ( ! $sig_header ) return false;
	$parts = array();
	foreach ( explode( ',', $sig_header ) as $pair ) {
		$kv = explode( '=', $pair, 2 );
		if ( count( $kv ) === 2 ) $parts[ trim( $kv[0] ) ][] = trim( $kv[1] );
	}
	if ( empty( $parts['t'][0] ) || empty( $parts['v1'] ) ) return false;

	$timestamp = $parts['t'][0];
	if ( abs( time() - (int) $timestamp ) > 300 ) return false; // 5 λεπτά ανοχή

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	foreach ( $parts['v1'] as $v1 ) {
		if ( hash_equals( $expected, $v1 ) ) return true;
	}
	return false;
}

/* ---------------------------------------------------------------
 * Refund μέσω Stripe API (μόνο με ρητή ενέργεια διαχειριστή).
 *
 * ΑΡΧΗ: το refund είναι ΞΕΧΩΡΙΣΤΟ από το booking status. Το booking
 * status (paid/cancelled/payment_conflict) καθορίζει τη διαθεσιμότητα των
 * ημερομηνιών· το refund_status (''/pending/requires_action/succeeded/
 * failed/canceled) καθορίζει την πορεία της επιστροφής χρημάτων. ΜΟΝΟ ένα
 * refund που το Stripe αναφέρει ρητά ως 'succeeded' θεωρείται ολοκληρωμένο
 * και ΜΟΝΟ τότε το booking γίνεται 'refunded' (και ελευθερώνονται οι μέρες).
 * --------------------------------------------------------------- */

/** Booking statuses που μπορούν να επιστραφούν (με έγκυρο payment intent). */
function vpms_refundable_booking_statuses() {
	return array( 'paid', 'cancelled', 'payment_conflict' );
}

/** refund_status τιμές που ΔΕΝ επιτρέπουν νέα refund ενέργεια. */
function vpms_refund_status_blocks_new() {
	// 'processing' = εσωτερικό μεταβατικό «claim» ενώ τρέχει η κλήση στο Stripe
	// ή ενώ εκκρεμεί reconciliation μετά από αποτυχία τοπικού update.
	return array( 'pending', 'requires_action', 'succeeded', 'processing' );
}

/** Καταγραφή reconciliation entry — capped στα 50 τελευταία, χωρίς PII. */
function vpms_refund_recon_log( $entry ) {
	$log = get_option( 'vpms_refund_reconciliation', array() );
	if ( ! is_array( $log ) ) $log = array();
	$log[] = array_merge( array( 'time' => current_time( 'mysql' ) ), $entry );
	if ( count( $log ) > 50 ) $log = array_slice( $log, -50 );
	update_option( 'vpms_refund_reconciliation', $log, false );
}

/**
 * Full refund για κράτηση με payment intent. Επιστρέφει true / 'pending' /
 * WP_Error.
 *
 * Ασφάλεια:
 *  - «Claim» με atomic conditional UPDATE (refund_status -> 'processing')
 *    ώστε δύο ταυτόχρονα κλικ να ΜΗΝ ξεκινήσουν δύο refunds.
 *  - Idempotency key ανά προσπάθεια: vpms-refund-{id}-{attempt_number}.
 *    Νέα ρητή προσπάθεια μετά από failed/canceled -> νέο attempt number
 *    (η αύξηση γίνεται atomic στη ΒΑΣΗ). Επανάληψη μετά από σφάλμα δικτύου
 *    -> ΙΔΙΟ key (το Stripe το κάνει dedupe — κανένα διπλό refund).
 */
function vpms_stripe_refund_booking( $booking_id ) {
	global $wpdb;
	$s = vpms_get_settings();
	$b = vpms_get_booking( $booking_id );

	if ( ! $b ) return new WP_Error( 'not_found', 'Booking not found.' );
	if ( empty( $s['stripe_sk'] ) ) {
		return new WP_Error( 'no_key', 'Stripe Secret Key is not configured.' );
	}
	if ( $b->status === 'refunded' || $b->refund_status === 'succeeded' ) {
		return new WP_Error( 'already', 'This booking has already been fully refunded.' );
	}
	if ( in_array( $b->refund_status, array( 'pending', 'requires_action', 'processing' ), true ) ) {
		return new WP_Error( 'in_progress', 'A refund for this booking is already in progress. Please wait — the final status is checked automatically every hour.' );
	}
	if ( ! in_array( $b->status, vpms_refundable_booking_statuses(), true ) ) {
		return new WP_Error( 'not_paid', 'Only paid, cancelled or payment-conflict bookings can be refunded.' );
	}
	if ( empty( $b->stripe_payment_intent ) ) {
		return new WP_Error( 'no_intent', 'No Stripe payment is linked to this booking, so it cannot be refunded automatically. Use the Stripe Dashboard instead.' );
	}

	// --- Atomic «claim»: επιτρέπεται ΜΟΝΟ αν δεν έχει ξεκινήσει/αποτύχει
	//     ήδη ένα refund. Ο conditional WHERE εξασφαλίζει έναν μόνο νικητή
	//     σε ταυτόχρονα requests (το δεύτερο παίρνει affected=0). ---
	$table   = $wpdb->prefix . 'vpms_bookings';
	$now     = gmdate( 'Y-m-d H:i:s' );
	$claimed = $wpdb->query( $wpdb->prepare(
		"UPDATE $table
		 SET refund_status = 'processing', refund_requested_at = %s, refund_last_error = ''
		 WHERE id = %d
		   AND status IN ('paid','cancelled','payment_conflict')
		   AND stripe_payment_intent != ''
		   AND ( refund_status = '' OR refund_status IN ('failed','canceled') )",
		$now, (int) $b->id
	) );
	if ( false === $claimed ) {
		return new WP_Error( 'db', 'Could not start the refund (database error). No refund was requested. Please try again.' );
	}
	if ( (int) $claimed !== 1 ) {
		// Κάποιος άλλος το «κλείδωσε» ενδιάμεσα ή δεν είναι πλέον επιλέξιμο.
		$b = vpms_get_booking( $booking_id );
		if ( $b && ( $b->status === 'refunded' || $b->refund_status === 'succeeded' ) ) {
			return new WP_Error( 'already', 'This booking has already been fully refunded.' );
		}
		return new WP_Error( 'in_progress', 'A refund for this booking is already in progress. Please wait and check again.' );
	}

	// attempt number = πλήθος «καμένων» keys + 1. Δεν αυξάνεται στο claim·
	// αυξάνεται μόνο όταν ένα attempt λάβει ΟΡΙΣΤΙΚΟ Stripe failed/canceled,
	// ώστε η επόμενη ρητή προσπάθεια να πάρει νέο key. Σε σφάλμα δικτύου
	// (χωρίς οριστική απάντηση) το ίδιο key επαναχρησιμοποιείται με ασφάλεια.
	$attempts = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT refund_attempts FROM $table WHERE id = %d", (int) $b->id
	) );
	$attempt_number = $attempts + 1;
	$idem_key = 'vpms-refund-' . (int) $b->id . '-' . $attempt_number;

	$resp = wp_remote_post( 'https://api.stripe.com/v1/refunds', array(
		'timeout' => 30,
		'headers' => array(
			'Authorization'   => 'Bearer ' . $s['stripe_sk'],
			'Content-Type'    => 'application/x-www-form-urlencoded',
			'Idempotency-Key' => $idem_key,
		),
		'body' => array( 'payment_intent' => $b->stripe_payment_intent ),
	) );

	// --- Σφάλμα δικτύου/μεταφοράς: το refund ΜΠΟΡΕΙ και να μη δημιουργήθηκε.
	//     Δεν «καίμε» attempt number -> η επανάληψη χρησιμοποιεί το ΙΔΙΟ
	//     idempotency key και το Stripe κάνει dedupe. refund_status='failed'
	//     ώστε να επιτρέπεται retry. ---
	if ( is_wp_error( $resp ) ) {
		$rev = vpms_update_booking( $b->id, array(
			'refund_status'     => 'failed',
			'refund_last_error' => substr( 'Network error contacting Stripe: ' . $resp->get_error_message(), 0, 190 ),
		) );
		if ( false === $rev ) {
			// ΣΟΒΑΡΟ: μετά από network error, η αλλαγή processing → failed
			// απέτυχε — η κράτηση μπορεί να μείνει «κλειδωμένη» σε processing.
			vpms_refund_recon_log( array(
				'booking'   => (int) $b->id,
				'refund_id' => '',
				'status'    => 'stuck_processing_after_network_error',
			) );
			return new WP_Error( 'reconciliation',
				'IMPORTANT: Could not reach Stripe AND the booking could not be moved out of the "processing" refund state, '
				. 'so it may stay locked in processing. Check booking #' . (int) $b->id . ' and the Stripe Dashboard before retrying. This incident was logged.'
			);
		}
		return new WP_Error( 'stripe', 'Could not reach Stripe to submit the refund. No change was made. You can retry — it will not create a double refund.' );
	}

	$data = json_decode( wp_remote_retrieve_body( $resp ), true );

	// --- Το Stripe απάντησε με σφάλμα ή χωρίς refund object: δεν δημιουργήθηκε
	//     refund. «Καίμε» το attempt number (νέο key στην επόμενη προσπάθεια),
	//     refund_status='failed' -> επιτρέπεται retry. Καμία αλλαγή σε booking
	//     status/ημερομηνίες. ---
	if ( empty( $data['id'] ) || ! empty( $data['error'] ) ) {
		$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Stripe refund was not created.';
		$upd = $wpdb->query( $wpdb->prepare(
			"UPDATE $table SET refund_attempts = refund_attempts + 1,
			 refund_status = 'failed', refund_last_error = %s WHERE id = %d",
			substr( $msg, 0, 190 ), (int) $b->id
		) );
		if ( false === $upd ) {
			// Το update απέτυχε — η κράτηση μπορεί να μείνει σε processing.
			vpms_refund_recon_log( array(
				'booking'   => (int) $b->id,
				'refund_id' => '',
				'status'    => 'stuck_processing_after_api_error',
			) );
			return new WP_Error( 'reconciliation',
				'IMPORTANT: Stripe returned an error AND the booking could not be moved out of the "processing" refund state '
				. '(it may stay locked). Stripe said: ' . substr( $msg, 0, 140 ) . '. Check booking #' . (int) $b->id . '. This incident was logged.'
			);
		}
		return new WP_Error( 'stripe', $msg );
	}

	$refund_id     = sanitize_text_field( (string) $data['id'] );
	$refund_status = isset( $data['status'] ) ? (string) $data['status'] : '';

	// --- ΟΡΙΣΤΙΚΗ αποτυχία στο Stripe (failed/canceled): τα χρήματα ΔΕΝ
	//     κινήθηκαν. «Καίμε» attempt (retry με νέο key), αποθηκεύουμε refund
	//     id + error, ΚΑΜΙΑ αλλαγή σε booking status/διαθεσιμότητα. ---
	if ( in_array( $refund_status, array( 'failed', 'canceled' ), true ) ) {
		$upd = $wpdb->query( $wpdb->prepare(
			"UPDATE $table SET refund_attempts = refund_attempts + 1,
			 refund_status = %s, refund_id = %s,
			 refund_last_error = %s WHERE id = %d",
			$refund_status, $refund_id,
			substr( 'Stripe reported the refund as "' . $refund_status . '".', 0, 190 ),
			(int) $b->id
		) );
		vpms_refund_recon_log( array(
			'booking'   => (int) $b->id,
			'refund_id' => $refund_id,
			'status'    => ( false === $upd ) ? $refund_status . '_update_failed' : $refund_status,
		) );
		if ( false === $upd ) {
			// Το Stripe απέτυχε ΚΑΙ το τοπικό update απέτυχε: η κράτηση μπορεί
			// να μείνει σε processing — δεν επιτρέπουμε ανεξέλεγκτο νέο refund.
			return new WP_Error( 'reconciliation',
				'IMPORTANT: Stripe reported the refund as "' . $refund_status . '" but the booking could not be updated '
				. '(it may stay locked in processing). Refund ID: ' . $refund_id . '. Check the Stripe Dashboard. This incident was logged.'
			);
		}
		return new WP_Error( 'stripe', 'Stripe reported the refund as "' . $refund_status . '". The booking was NOT changed and the dates were NOT released. You can retry the refund.' );
	}

	// --- Σε εξέλιξη (pending/requires_action): αποθηκεύουμε refund id +
	//     refund_status, ΔΕΝ αλλάζουμε booking status, ΔΕΝ ελευθερώνουμε
	//     μέρες, ΔΕΝ δείχνουμε «completed». Το ωριαίο reconciliation κλείνει
	//     την τελική κατάσταση μέσω του Stripe API. ---
	if ( in_array( $refund_status, array( 'pending', 'requires_action' ), true ) ) {
		$upd = vpms_update_booking( $b->id, array(
			'refund_status'       => $refund_status,
			'refund_id'           => $refund_id,
			'refund_requested_at' => $now,
			'refund_last_error'   => '',
		) );
		if ( false === $upd ) {
			vpms_refund_recon_log( array( 'booking' => (int) $b->id, 'refund_id' => $refund_id, 'status' => 'pending_save_failed' ) );
			return new WP_Error( 'reconciliation',
				'IMPORTANT: A refund was submitted to Stripe (refund ID: ' . $refund_id . ') but saving it locally FAILED. '
				. 'Do NOT start a new refund. Verify in the Stripe Dashboard. This incident was logged.'
			);
		}
		return 'pending';
	}

	// --- ΟΛΟΚΛΗΡΩΜΕΝΟ refund: ΜΟΝΟ όταν $refund_status === 'succeeded'. ---
	if ( $refund_status === 'succeeded' ) {
		$updated = vpms_update_booking( $b->id, array(
			'status'            => 'refunded',
			'refund_status'     => 'succeeded',
			'refund_id'         => $refund_id,
			'refunded_at'       => $now,
			'refund_last_error' => '',
		) );
		if ( false === $updated ) {
			// Τα χρήματα ΕΧΟΥΝ επιστραφεί, αλλά το τοπικό update απέτυχε.
			// ΟΧΙ «refund failed». Το refund_status μένει 'processing' ->
			// δεν επιτρέπεται νέα refund ενέργεια μέχρι να ελεγχθεί. Σώζουμε
			// το refund id ώστε να ΜΗΝ χαθεί.
			vpms_refund_recon_log( array( 'booking' => (int) $b->id, 'refund_id' => $refund_id, 'status' => 'succeeded_save_failed' ) );
			return new WP_Error( 'reconciliation',
				'IMPORTANT: The Stripe refund SUCCEEDED (refund ID: ' . $refund_id . '), '
				. 'but updating the booking in the database FAILED. The guest HAS been refunded. '
				. 'Please mark the booking manually and verify in the Stripe Dashboard. This incident was logged. '
				. 'No new refund action is allowed for this booking until it is reviewed.'
			);
		}
		return true;
	}

	// --- ΑΓΝΩΣΤΟ / κενό status: ΔΕΝ το θεωρούμε ποτέ succeeded. Κρατάμε το
	//     refund id, το σημειώνουμε ως 'pending' ώστε το reconciliation να
	//     το ελέγξει ξανά, ΔΕΝ αλλάζουμε booking status, καταγράφουμε
	//     reconciliation error και ζητάμε έλεγχο στο Stripe Dashboard. ---
	$upd = vpms_update_booking( $b->id, array(
		'refund_status'       => 'pending',
		'refund_id'           => $refund_id,
		'refund_requested_at' => $now,
		'refund_last_error'   => substr( 'Unknown Stripe refund status: "' . $refund_status . '" — under reconciliation.', 0, 190 ),
	) );
	vpms_refund_recon_log( array(
		'booking'   => (int) $b->id,
		'refund_id' => $refund_id,
		'status'    => ( false === $upd ) ? 'unknown_update_failed:' . substr( $refund_status, 0, 12 ) : 'unknown:' . substr( $refund_status, 0, 20 ),
	) );
	return new WP_Error( 'reconciliation',
		'Stripe returned an unexpected refund status for booking #' . (int) $b->id . '. '
		. 'The booking was NOT marked as refunded. Please check the refund (ID: ' . $refund_id . ') in the Stripe Dashboard. This incident was logged.'
	);
}

/**
 * Περιοδικό reconciliation για refunds σε εξέλιξη (refund_status
 * pending/requires_action). Ρωτάει το Stripe Refund API για την τελική
 * κατάσταση — ΑΝΕΞΑΡΤΗΤΑ από το booking status:
 *   succeeded       -> booking 'refunded' + refund_status 'succeeded' + refunded_at
 *   failed/canceled -> refund_status = failed/canceled, «καίγεται» attempt
 *                      (retry με νέο key), booking status ΑΜΕΤΑΒΛΗΤΟ + diagnostic
 * Τρέχει από το ωριαίο vpms_cleanup cron.
 */
function vpms_reconcile_pending_refunds() {
	global $wpdb;
	$s = vpms_get_settings();
	if ( empty( $s['stripe_sk'] ) ) return;

	$table = $wpdb->prefix . 'vpms_bookings';
	$rows  = $wpdb->get_results(
		"SELECT id, refund_id FROM $table
		 WHERE refund_status IN ('pending','requires_action') AND refund_id != '' LIMIT 10"
	);
	foreach ( $rows as $r ) {
		$resp = wp_remote_get(
			'https://api.stripe.com/v1/refunds/' . rawurlencode( $r->refund_id ),
			array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $s['stripe_sk'] ) )
		);
		if ( is_wp_error( $resp ) ) continue;
		$data   = json_decode( wp_remote_retrieve_body( $resp ), true );
		$status = isset( $data['status'] ) ? (string) $data['status'] : '';

		if ( $status === 'succeeded' ) {
			$upd = vpms_update_booking( $r->id, array(
				'status'            => 'refunded',
				'refund_status'     => 'succeeded',
				'refunded_at'       => gmdate( 'Y-m-d H:i:s' ),
				'refund_last_error' => '',
			) );
			if ( false === $upd ) {
				vpms_refund_recon_log( array( 'booking' => (int) $r->id, 'refund_id' => sanitize_text_field( $r->refund_id ), 'status' => 'succeeded_save_failed' ) );
			}
		} elseif ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
			// booking status ΑΜΕΤΑΒΛΗΤΟ (η διαθεσιμότητα δεν αλλάζει). «Καίμε»
			// attempt ώστε τυχόν χειροκίνητο retry να πάρει νέο idempotency key.
			$upd = $wpdb->query( $wpdb->prepare(
				"UPDATE $table SET refund_attempts = refund_attempts + 1,
				 refund_status = %s,
				 refund_last_error = %s WHERE id = %d",
				$status,
				substr( 'Stripe reported the refund as "' . $status . '" during reconciliation.', 0, 190 ),
				(int) $r->id
			) );
			vpms_refund_recon_log( array(
				'booking'   => (int) $r->id,
				'refund_id' => sanitize_text_field( $r->refund_id ),
				'status'    => ( false === $upd ) ? $status . '_reconcile_update_failed' : $status,
			) );
		}
		// pending/requires_action/άγνωστο: θα ελεγχθεί ξανά στο επόμενο cron.
	}
}

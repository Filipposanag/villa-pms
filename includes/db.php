<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Επίπεδο δεδομένων: διαθεσιμότητα, τιμές, κρατήσεις, locking
 * --------------------------------------------------------------- */

/**
 * Επιστρέφει τις "πιασμένες" νύχτες (Y-m-d => true) σε ένα διάστημα.
 * Πιασμένη νύχτα = paid / external / blocked / πρόσφατο pending.
 */
function vpms_get_occupied_nights( $from, $to ) {
	global $wpdb;
	// Pending hold: ενιαίο με το Stripe expiration — μπλοκάρει μέχρι
	// stripe_expires_at (~31') + 10' grace (fallback: created_at + 41').
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT checkin, checkout FROM {$wpdb->prefix}vpms_bookings
		 WHERE checkin < %s AND checkout > %s
		   AND ( status IN ('paid','external','blocked')
		         OR ( status = 'pending'
		              AND COALESCE(stripe_expires_at, created_at + INTERVAL 31 MINUTE) + INTERVAL 10 MINUTE > %s ) )",
		$to, $from, gmdate( 'Y-m-d H:i:s' )
	) );
	$occupied = array();
	foreach ( $rows as $r ) {
		$d   = new DateTime( $r->checkin );
		$end = new DateTime( $r->checkout ); // η μέρα checkout είναι ελεύθερη
		while ( $d < $end ) {
			$occupied[ $d->format( 'Y-m-d' ) ] = true;
			$d->modify( '+1 day' );
		}
	}
	return $occupied;
}

/** Τιμές ανά μέρα σε διάστημα (custom ή προεπιλογή). */
function vpms_get_prices( $from, $to ) {
	global $wpdb;
	$s = vpms_get_settings();
	$custom = $wpdb->get_results( $wpdb->prepare(
		"SELECT rate_date, price FROM {$wpdb->prefix}vpms_rates
		 WHERE rate_date >= %s AND rate_date < %s", $from, $to
	), OBJECT_K );

	$prices = array();
	$d   = new DateTime( $from );
	$end = new DateTime( $to );
	while ( $d < $end ) {
		$key = $d->format( 'Y-m-d' );
		$prices[ $key ] = isset( $custom[ $key ] )
			? (float) $custom[ $key ]->price
			: (float) $s['default_price'];
		$d->modify( '+1 day' );
	}
	return $prices;
}

/** Έλεγχος ότι όλες οι νύχτες checkin..checkout-1 είναι ελεύθερες. */
function vpms_range_available( $checkin, $checkout ) {
	$occupied = vpms_get_occupied_nights( $checkin, $checkout );
	$d   = new DateTime( $checkin );
	$end = new DateTime( $checkout );
	while ( $d < $end ) {
		if ( isset( $occupied[ $d->format( 'Y-m-d' ) ] ) ) return false;
		$d->modify( '+1 day' );
	}
	return true;
}

/**
 * IDs ενεργών κρατήσεων που επικαλύπτονται με το διάστημα, εξαιρώντας
 * ένα booking. Χρησιμοποιείται στο expired→paid reconciliation και στον
 * εντοπισμό iCal conflicts. Κενός πίνακας = οι μέρες είναι ελεύθερες.
 */
function vpms_overlapping_active_ids( $checkin, $checkout, $exclude_id = 0 ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}vpms_bookings
		 WHERE id != %d AND checkin < %s AND checkout > %s
		   AND ( status IN ('paid','external','blocked')
		         OR ( status = 'pending'
		              AND COALESCE(stripe_expires_at, created_at + INTERVAL 31 MINUTE) + INTERVAL 10 MINUTE > %s ) )",
		(int) $exclude_id, $checkout, $checkin, gmdate( 'Y-m-d H:i:s' )
	) ) );
}

/** Συνολικό κόστος διαμονής (νύχτες + έξοδα καθαρισμού), υπολογισμένο server-side. */
function vpms_calculate_total( $checkin, $checkout ) {
	$s      = vpms_get_settings();
	$prices = vpms_get_prices( $checkin, $checkout );
	return round( array_sum( $prices ) + (float) $s['cleaning_fee'], 2 );
}

/* ---------------------------------------------------------------
 * Database-level lock (MySQL GET_LOCK) — μία βίλα, ένα lock.
 * Αποτρέπει race condition σε ταυτόχρονα booking requests.
 * --------------------------------------------------------------- */

function vpms_lock_name() {
	global $wpdb;
	// Μοναδικό ανά site ώστε να μη συγκρούεται σε shared MySQL server.
	return 'vpms_booking_' . md5( $wpdb->prefix . (string) get_current_blog_id() );
}

/** Παίρνει database-level lock (true/false). Timeout σε δευτερόλεπτα. */
function vpms_acquire_booking_lock( $timeout = 10 ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare(
		'SELECT GET_LOCK(%s, %d)', vpms_lock_name(), $timeout
	) ) === '1';
}

function vpms_release_booking_lock() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', vpms_lock_name() ) );
}

/**
 * Ατομική δημιουργία κράτησης: lock → re-check διαθεσιμότητας →
 * server-side υπολογισμός ποσού (για direct) → insert → release.
 * Το lock ελευθερώνεται ΠΑΝΤΑ, ακόμη και σε exception.
 * Επιστρέφει booking ID ή WP_Error ('conflict' αν οι μέρες πιάστηκαν).
 */
function vpms_create_booking_atomic( $args ) {
	if ( ! vpms_acquire_booking_lock( 10 ) ) {
		return new WP_Error( 'lock', 'The system is busy right now. Please try again in a moment.' );
	}
	try {
		if ( ! vpms_range_available( $args['checkin'], $args['checkout'] ) ) {
			return new WP_Error( 'conflict', 'These dates were just booked by someone else. Please choose different dates.' );
		}
		if ( ( $args['source'] ?? '' ) === 'direct' ) {
			// Το ποσό υπολογίζεται ΠΑΝΤΑ server-side μέσα στο lock.
			$args['amount'] = vpms_calculate_total( $args['checkin'], $args['checkout'] );
		}
		return vpms_create_booking( $args );
	} finally {
		vpms_release_booking_lock();
	}
}

/** Δημιουργία κράτησης (για public flows χρησιμοποίησε την atomic εκδοχή). */
function vpms_create_booking( $args ) {
	global $wpdb;
	$defaults = array(
		'checkin' => '', 'checkout' => '', 'guest_name' => '', 'guest_email' => '',
		'guest_phone' => '', 'guests' => 2, 'amount' => 0, 'currency' => 'EUR',
		'status' => 'pending', 'source' => 'direct', 'external_uid' => '',
		'stripe_session' => '', 'note' => '',
	);
	$a = wp_parse_args( $args, $defaults );

	if ( ! vpms_valid_date( $a['checkin'] ) || ! vpms_valid_date( $a['checkout'] )
		|| $a['checkout'] <= $a['checkin'] ) {
		return new WP_Error( 'bad_dates', 'Invalid dates.' );
	}

	$a['created_at'] = gmdate( 'Y-m-d H:i:s' );

	$ok = $wpdb->insert( $wpdb->prefix . 'vpms_bookings', $a );
	return $ok ? (int) $wpdb->insert_id : new WP_Error( 'db', 'Database error.' );
}

function vpms_get_booking( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}vpms_bookings WHERE id = %d", $id
	) );
}

function vpms_update_booking( $id, $fields ) {
	global $wpdb;
	return $wpdb->update( $wpdb->prefix . 'vpms_bookings', $fields, array( 'id' => (int) $id ) );
}

/** Οριστική διαγραφή κράτησης (π.χ. pending που απέτυχε το Stripe session). */
function vpms_delete_booking( $id ) {
	global $wpdb;
	return $wpdb->delete( $wpdb->prefix . 'vpms_bookings', array( 'id' => (int) $id ) );
}

/**
 * Ατομική μετάβαση σε paid. Status, paid_at ΚΑΙ payment intent
 * αποθηκεύονται στο ΙΔΙΟ atomic UPDATE — δεν υπάρχει κατάσταση όπου η
 * κράτηση είναι paid χωρίς αποθηκευμένο intent. Επιστρέφει true ΜΟΝΟ
 * αν αυτό το request έκανε την αλλαγή (ένα μόνο confirmation email,
 * ακόμη και με διπλά webhooks ή ταυτόχρονο success redirect).
 * ΜΟΝΟ από status 'pending' — για expired κρατήσεις η μετάβαση γίνεται
 * αποκλειστικά μέσω vpms_recover_expired_payment() με availability
 * reconciliation κάτω από το global booking lock (όχι τυφλά).
 */
function vpms_transition_to_paid( $booking_id, $payment_intent = '' ) {
	global $wpdb;
	$affected = $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}vpms_bookings
		 SET status = 'paid', paid_at = %s, stripe_payment_intent = %s
		 WHERE id = %d AND status = 'pending'",
		gmdate( 'Y-m-d H:i:s' ),
		sanitize_text_field( (string) $payment_intent ),
		(int) $booking_id
	) );
	return (int) $affected === 1;
}

/**
 * Αυστηρό validation ημερομηνίας: μορφή Y-m-d ΚΑΙ πραγματικά έγκυρη
 * ημερολογιακή μέρα (απορρίπτει π.χ. 2026-02-31, 2026-13-01).
 */
function vpms_valid_date( $d ) {
	if ( ! is_string( $d ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/** Ορισμός τιμής για εύρος ημερομηνιών (inclusive). */
function vpms_set_rate_range( $from, $to, $price ) {
	global $wpdb;
	$d   = new DateTime( $from );
	$end = new DateTime( $to );
	while ( $d <= $end ) {
		$wpdb->replace( $wpdb->prefix . 'vpms_rates', array(
			'rate_date' => $d->format( 'Y-m-d' ),
			'price'     => (float) $price,
		) );
		$d->modify( '+1 day' );
	}
}

/**
 * Αποστολή email με προσαρμοσμένο αποστολέα (Settings → Email sender).
 * Αν οριστεί έγκυρο from_email (π.χ. booking@isleofolive.gr), όλα τα
 * emails του plugin φεύγουν από αυτό, με όνομα το Property name —
 * χωρίς να επηρεάζονται τα υπόλοιπα emails του WordPress.
 */
function vpms_mail( $to, $subject, $body ) {
	$s       = vpms_get_settings();
	$headers = array();
	$from    = sanitize_email( $s['from_email'] );
	if ( $from && is_email( $from ) ) {
		$name      = str_replace( array( "\r", "\n", '"' ), '', vpms_property_name() );
		$headers[] = 'From: ' . $name . ' <' . $from . '>';
		$headers[] = 'Reply-To: ' . $name . ' <' . $from . '>';
	}
	return wp_mail( $to, $subject, $body, $headers );
}

/** Μορφοποίηση ποσού με ISO currency code (emails/admin — χωρίς hardcoded €). */
function vpms_format_money( $amount, $currency ) {
	return number_format( (float) $amount, 2 ) . ' ' . strtoupper( (string) $currency );
}

/**
 * Ειδοποίηση ιδιοκτήτη + επιβεβαίωση επισκέπτη μετά από πληρωμή, με
 * αποθηκευμένο delivery state ανά παραλήπτη: αν το wp_mail αποτύχει,
 * η κράτηση μένει paid, το σφάλμα καταγράφεται και η αποστολή μπορεί να
 * επαναληφθεί (cron ή admin "Resend") — ποτέ όμως δεύτερη φορά όταν
 * έχει ήδη σταλεί επιτυχώς.
 */
function vpms_send_confirmation_emails( $booking ) {
	$s     = vpms_get_settings();
	$name  = vpms_property_name();
	$dates = date_i18n( 'd/m/Y', strtotime( $booking->checkin ) ) . ' – ' . date_i18n( 'd/m/Y', strtotime( $booking->checkout ) );
	$money = vpms_format_money( $booking->amount, $booking->currency );
	$error = '';

	// Στον ιδιοκτήτη — μόνο αν δεν έχει ήδη σταλεί επιτυχώς
	if ( empty( $booking->confirmation_owner_sent ) ) {
		$ok = vpms_mail(
			$s['notify_email'],
			'New booking: ' . $dates,
			"New paid booking from the website.\n\n"
			. "Name: {$booking->guest_name}\nEmail: {$booking->guest_email}\n"
			. "Phone: {$booking->guest_phone}\nGuests: {$booking->guests}\n"
			. "Dates: $dates\nAmount: $money"
		);
		if ( $ok ) {
			vpms_update_booking( $booking->id, array( 'confirmation_owner_sent' => 1 ) );
		} else {
			$error = 'Owner notification failed';
		}
	}

	// Στον επισκέπτη (Αγγλικά — διεθνές κοινό) — μόνο αν δεν έχει ήδη σταλεί
	if ( $booking->guest_email && empty( $booking->confirmation_guest_sent ) ) {
		$ok = vpms_mail(
			$booking->guest_email,
			'Booking confirmation — ' . $name,
			"Dear {$booking->guest_name},\n\n"
			. "Your booking is confirmed!\n\n"
			. "Dates: $dates\nGuests: {$booking->guests}\n"
			. "Total: $money\n\n"
			. "Check-in from {$s['checkin_time']}, check-out by {$s['checkout_time']}.\n"
			. "We look forward to welcoming you!\n\n" . $name
		);
		if ( $ok ) {
			vpms_update_booking( $booking->id, array( 'confirmation_guest_sent' => 1 ) );
		} else {
			$error = trim( $error . ' / Guest confirmation failed', ' /' );
		}
	}

	vpms_update_booking( $booking->id, array(
		'confirmation_last_error' => substr( $error, 0, 190 ),
	) );
}

/**
 * Cron retry: ξαναστέλνει ΜΟΝΟ τα confirmation emails που απέτυχαν,
 * για direct πληρωμένες κρατήσεις των τελευταίων 7 ημερών.
 */
function vpms_retry_failed_confirmations() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}vpms_bookings
		 WHERE status = 'paid' AND source = 'direct' AND paid_at IS NOT NULL AND paid_at >= %s
		   AND ( confirmation_owner_sent = 0
		         OR ( guest_email != '' AND confirmation_guest_sent = 0 ) )
		 LIMIT 20",
		gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
	) );
	foreach ( $rows as $b ) {
		vpms_send_confirmation_emails( $b );
	}
}

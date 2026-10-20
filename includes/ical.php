<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * iCal: δύο ξεχωριστά export feeds (Airbnb / Booking.com) με
 * ασφαλή tokens + ασφαλές import με sync logging.
 *
 * Export URLs (εμφανίζονται στις Ρυθμίσεις):
 *   /?vpms_ical=export&channel=airbnb&token=...   → δώσε στο Airbnb
 *   /?vpms_ical=export&channel=booking&token=...  → δώσε στο Booking.com
 * --------------------------------------------------------------- */

/** URL του export feed για κανάλι. */
function vpms_ical_export_url( $channel ) {
	return add_query_arg( array(
		'vpms_ical' => 'export',
		'channel'   => $channel,
		'token'     => get_option( 'vpms_ical_token_' . $channel, '' ),
	), home_url( '/' ) );
}

/* ---------------- Export ---------------- */
add_action( 'init', function () {
	if ( ! isset( $_GET['vpms_ical'] ) || $_GET['vpms_ical'] !== 'export' ) return;

	$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
	$token   = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';

	if ( ! in_array( $channel, array( 'airbnb', 'booking' ), true ) ) {
		status_header( 403 );
		exit;
	}
	$stored = (string) get_option( 'vpms_ical_token_' . $channel, '' );
	if ( $stored === '' || $token === '' || ! hash_equals( $stored, $token ) ) {
		status_header( 403 );
		exit;
	}

	echo vpms_build_ical_export( $channel ); // phpcs:ignore -- iCal output, όχι HTML
	exit;
} );

/**
 * Χτίζει το .ics για ένα κανάλι.
 * Περιλαμβάνει: paid direct + manual κρατήσεις, manual blocks,
 * και ενεργές external κρατήσεις από το ΑΛΛΟ κανάλι.
 * Εξαιρεί: events του ίδιου καναλιού (αποφυγή loop), pending,
 * cancelled, refunded. Κανένα προσωπικό δεδομένο στο feed.
 */
function vpms_build_ical_export( $channel ) {
	global $wpdb;

	// external μόνο από το άλλο κανάλι
	$other = $channel === 'airbnb' ? 'booking' : 'airbnb';

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, checkin, checkout, source, status FROM {$wpdb->prefix}vpms_bookings
		 WHERE checkout >= CURDATE()
		   AND (
		         ( status IN ('paid','blocked') AND source IN ('direct','manual') )
		      OR ( status = 'external' AND source = %s )
		   )",
		$other
	) );

	$host  = wp_parse_url( home_url(), PHP_URL_HOST );
	$now   = gmdate( 'Ymd\THis\Z' );
	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Villa PMS//Villa PMS ' . VPMS_VERSION . '//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
	);
	foreach ( $rows as $r ) {
		$summary = ( $r->status === 'blocked' ) ? 'Blocked' : 'Reserved';
		// Σταθερό, μοναδικό, αναγνωρίσιμο UID (prefix vpms- για loop detection)
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = vpms_ical_fold( 'UID:vpms-' . $r->id . '@' . $host );
		$lines[] = 'DTSTAMP:' . $now;
		$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $r->checkin );
		// DTEND = μέρα checkout (exclusive) — δεν μπλοκάρεται ως διανυκτέρευση
		$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', $r->checkout );
		$lines[] = vpms_ical_fold( 'SUMMARY:' . vpms_ical_escape( $summary ) );
		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'END:VEVENT';
	}
	$lines[] = 'END:VCALENDAR';

	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: inline; filename=villa-' . $channel . '.ics' );
	return implode( "\r\n", $lines ) . "\r\n";
}

/** Escaping κειμένου κατά RFC 5545 (backslash, semicolon, comma, newline). */
function vpms_ical_escape( $text ) {
	$text = str_replace( '\\', '\\\\', (string) $text );
	$text = str_replace( array( ';', ',' ), array( '\;', '\,' ), $text );
	$text = str_replace( array( "\r\n", "\r", "\n" ), '\n', $text );
	return $text;
}

/** Line folding κατά RFC 5545 (γραμμές max 75 octets, συνέχεια με κενό). */
function vpms_ical_fold( $line ) {
	if ( strlen( $line ) <= 75 ) return $line;
	$out   = array();
	$first = true;
	while ( strlen( $line ) > 0 ) {
		$take  = $first ? 75 : 74;
		$out[] = ( $first ? '' : ' ' ) . substr( $line, 0, $take );
		$line  = substr( $line, $take );
		$first = false;
	}
	return implode( "\r\n", $out );
}

/* ---------------- Sync status / logging ---------------- */

/** Ενημέρωση κατάστασης sync για ένα source (χωρίς secrets/προσωπικά δεδομένα). */
function vpms_sync_log( $source, $data ) {
	$all = get_option( 'vpms_sync_status', array() );
	$cur = isset( $all[ $source ] ) ? $all[ $source ] : array();
	$all[ $source ] = array_merge( $cur, $data, array( 'last_attempt' => current_time( 'mysql' ) ) );
	update_option( 'vpms_sync_status', $all, false );

	// Σύντομο ιστορικό — περιορισμένο στα 20 τελευταία (δεν μεγαλώνει η βάση)
	$log   = get_option( 'vpms_sync_history', array() );
	$log[] = array_merge( array( 'source' => $source, 'time' => current_time( 'mysql' ) ), $data );
	if ( count( $log ) > 20 ) $log = array_slice( $log, -20 );
	update_option( 'vpms_sync_history', $log, false );
}

function vpms_get_sync_status( $source = null ) {
	$all = get_option( 'vpms_sync_status', array() );
	if ( $source === null ) return $all;
	return isset( $all[ $source ] ) ? $all[ $source ] : array();
}

/* ---------------- Import ---------------- */

/**
 * Import: κατεβάζει τα iCal των Booking/Airbnb και μπλοκάρει τις
 * αντίστοιχες ημερομηνίες. Τρέχει από cron κάθε 15 λεπτά.
 * Blank/malformed/HTTP-error response ΔΕΝ προκαλεί ποτέ διαγραφή.
 */
function vpms_run_ical_import() {
	$s     = vpms_get_settings();
	$feeds = array( 'booking' => $s['ical_booking'], 'airbnb' => $s['ical_airbnb'] );

	foreach ( $feeds as $source => $url ) {
		if ( empty( $url ) ) continue;
		vpms_import_ical_source( $source, $url );
	}
	update_option( 'vpms_last_sync', current_time( 'mysql' ), false );
}

/** Database-level named lock ανά iCal source (όχι transient). */
function vpms_ical_lock_name( $source ) {
	global $wpdb;
	return 'vpms_ical_' . $source . '_' . md5( $wpdb->prefix . (string) get_current_blog_id() );
}

/**
 * Ασφαλές import ενός feed με πλήρες validation, logging και
 * database lock ανά source: αν τρέχει ήδη sync (π.χ. cron + "Sync now"
 * ταυτόχρονα), το δεύτερο τερματίζει με log και ΚΑΜΙΑ αλλαγή στη βάση.
 */
function vpms_import_ical_source( $source, $url ) {
	global $wpdb;
	$got = (string) $wpdb->get_var( $wpdb->prepare(
		'SELECT GET_LOCK(%s, 0)', vpms_ical_lock_name( $source )
	) ) === '1';
	if ( ! $got ) {
		vpms_sync_log( $source, array(
			'last_error_at' => current_time( 'mysql' ),
			'last_error'    => 'Sync already running — skipped (no changes made).',
			'http_status'   => 0,
		) );
		return false;
	}
	try {
		return vpms_import_ical_source_locked( $source, $url );
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', vpms_ical_lock_name( $source ) ) );
	}
}

function vpms_import_ical_source_locked( $source, $url ) {
	// Μόνο έγκυρα http(s) URLs — προτιμάται HTTPS.
	$valid  = wp_http_validate_url( $url );
	$scheme = $valid ? wp_parse_url( $valid, PHP_URL_SCHEME ) : '';
	if ( ! $valid || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		vpms_sync_log( $source, array(
			'last_error_at' => current_time( 'mysql' ),
			'last_error'    => 'Invalid feed URL (must be a valid HTTP/HTTPS address).',
			'http_status'   => 0,
		) );
		return false;
	}

	// wp_safe_remote_get: SSL verification ενεργό, προστασία από εσωτερικά IPs,
	// λογικό timeout και όριο μεγέθους απάντησης (2 MB).
	$resp = wp_safe_remote_get( $valid, array(
		'timeout'             => 20,
		'redirection'         => 3,
		'limit_response_size' => 2 * MB_IN_BYTES,
	) );

	if ( is_wp_error( $resp ) ) {
		vpms_sync_log( $source, array(
			'last_error_at' => current_time( 'mysql' ),
			'last_error'    => substr( $resp->get_error_message(), 0, 200 ),
			'http_status'   => 0,
		) );
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code( $resp );
	$body = (string) wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		vpms_sync_log( $source, array(
			'last_error_at' => current_time( 'mysql' ),
			'last_error'    => 'HTTP error from feed.',
			'http_status'   => $code,
		) );
		return false;
	}

	// Πρέπει να είναι πραγματικό, πλήρες, μη-truncated VCALENDAR —
	// αλλιώς καμία αλλαγή (και σίγουρα καμία διαγραφή) στη βάση.
	$vc_error = vpms_validate_vcalendar( $body );
	if ( $vc_error !== true ) {
		vpms_sync_log( $source, array(
			'last_error_at' => current_time( 'mysql' ),
			'last_error'    => $vc_error,
			'http_status'   => $code,
		) );
		return false;
	}

	$events = vpms_parse_ical( $body );

	// Οι αλλαγές στη βάση γίνονται κάτω από το ΙΔΙΟ global booking lock
	// που χρησιμοποιούν direct/manual bookings — όχι race μεταξύ iCal
	// import και ταυτόχρονης κράτησης. Release πάντα (finally).
	if ( ! vpms_acquire_booking_lock( 10 ) ) {
		vpms_sync_log( $source, array(
			'last_error_at' => current_time( 'mysql' ),
			'last_error'    => 'Could not acquire booking lock — sync skipped, no changes made.',
			'http_status'   => $code,
		) );
		return false;
	}
	try {
		$counts = vpms_apply_external_events( $source, $events );
	} finally {
		vpms_release_booking_lock();
	}

	$log_entry = array(
		'http_status'  => $code,
		'events_found' => count( $events ),
		'added'        => $counts['added'],
		'updated'      => $counts['updated'],
		'deleted'      => $counts['deleted'],
	);
	if ( ! empty( $counts['db_errors'] ) ) {
		// Υπήρξε database error → ΔΕΝ δηλώνουμε πλήρη επιτυχία (δεν ενημερώνεται
		// last_success). Καταγράφεται σαφές σφάλμα για admin review.
		$log_entry['last_error']    = 'Database error during sync (' . (int) $counts['db_errors'] . '): '
			. substr( implode( '; ', (array) $counts['db_error_notes'] ), 0, 180 );
		$log_entry['last_error_at'] = current_time( 'mysql' );
		vpms_sync_log( $source, $log_entry );
		return false;
	}
	$log_entry['last_success'] = current_time( 'mysql' );
	$log_entry['last_error']   = '';
	vpms_sync_log( $source, $log_entry );
	return true;
}

/**
 * Αυστηρό validation VCALENDAR πριν επιτραπεί οποιαδήποτε διαγραφή:
 * ακριβώς ένα BEGIN/END:VCALENDAR, VERSION, ισοσκελισμένα VEVENT blocks,
 * και όχι truncation (το END:VCALENDAR πρέπει να κλείνει το έγγραφο και
 * το μέγεθος να μην έχει «χτυπήσει» στο response-size όριο).
 * Επιστρέφει true ή string με το σφάλμα.
 */
function vpms_validate_vcalendar( $body ) {
	$trimmed = rtrim( $body );
	if ( substr_count( $body, 'BEGIN:VCALENDAR' ) !== 1 || substr_count( $body, 'END:VCALENDAR' ) !== 1 ) {
		return 'Response does not contain exactly one complete VCALENDAR block.';
	}
	if ( stripos( $body, 'VERSION:' ) === false ) {
		return 'VCALENDAR is missing the VERSION property.';
	}
	if ( substr( $trimmed, -strlen( 'END:VCALENDAR' ) ) !== 'END:VCALENDAR' ) {
		return 'VCALENDAR appears truncated (does not end with END:VCALENDAR).';
	}
	if ( substr_count( $body, 'BEGIN:VEVENT' ) !== substr_count( $body, 'END:VEVENT' ) ) {
		return 'VCALENDAR contains unbalanced VEVENT blocks (possible truncation).';
	}
	// Αν φτάσαμε στο όριο μεγέθους, το σώμα μπορεί να είναι κομμένο.
	if ( strlen( $body ) >= 2 * MB_IN_BYTES ) {
		return 'Response reached the size limit — possibly truncated. No changes applied.';
	}
	return true;
}

/**
 * iCal parser: υποστηρίζει folded lines, VALUE=DATE, DATE-TIME (τοπικό,
 * UTC ή με TZID — κρατάμε το ημερολογιακό μέρος), escaped summaries, UIDs.
 * Επιστρέφει [ [uid, start, end, summary], ... ]
 */
function vpms_parse_ical( $body ) {
	// Unfold: γραμμές που συνεχίζουν ξεκινούν με κενό ή tab
	$body  = preg_replace( "/(\r\n|\n|\r)[ \t]/", '', $body );
	$lines = preg_split( "/\r\n|\n|\r/", $body );

	$events = array();
	$cur    = null;
	$method_cancel = false;
	foreach ( $lines as $line ) {
		// METHOD:CANCEL σε επίπεδο ημερολογίου: όλα τα events είναι ακυρώσεις.
		if ( $cur === null && stripos( $line, 'METHOD:CANCEL' ) === 0 ) {
			$method_cancel = true;
			continue;
		}
		if ( strpos( $line, 'BEGIN:VEVENT' ) === 0 ) {
			$cur = array( 'uid' => '', 'start' => '', 'end' => '', 'summary' => '', 'cancelled' => $method_cancel );
			continue;
		}
		if ( strpos( $line, 'END:VEVENT' ) === 0 ) {
			if ( $cur && $cur['start'] ) {
				// DTEND προαιρετικό κατά RFC — για VALUE=DATE χωρίς DTEND, 1 μέρα
				if ( ! $cur['end'] ) {
					$cur['end'] = ( new DateTime( $cur['start'] ) )->modify( '+1 day' )->format( 'Y-m-d' );
				}
				if ( $cur['end'] > $cur['start'] ) $events[] = $cur;
			}
			$cur = null;
			continue;
		}
		if ( $cur === null ) continue;

		if ( preg_match( '/^UID[^:]*:(.+)$/', $line, $m ) ) {
			$cur['uid'] = trim( $m[1] );
		}
		if ( preg_match( '/^SUMMARY[^:]*:(.+)$/', $line, $m ) ) {
			$cur['summary'] = vpms_ical_unescape( trim( $m[1] ) );
		}
		if ( preg_match( '/^DTSTART[^:]*:(.+)$/', $line, $m ) ) {
			$cur['start'] = vpms_ical_parse_date( trim( $m[1] ) );
		}
		if ( preg_match( '/^DTEND[^:]*:(.+)$/', $line, $m ) ) {
			$cur['end'] = vpms_ical_parse_date( trim( $m[1] ) );
		}
		if ( preg_match( '/^STATUS[^:]*:\s*CANCELLED/i', $line ) ) {
			$cur['cancelled'] = true;
		}
	}
	return $events;
}

/** Μετατροπή iCal DATE ή DATE-TIME (20260713 / 20260713T140000Z) σε Y-m-d. */
function vpms_ical_parse_date( $value ) {
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T\d{6}Z?)?$/', $value, $m ) ) {
		if ( checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
	}
	return '';
}

/** Αντίστροφο του RFC 5545 escaping. */
function vpms_ical_unescape( $text ) {
	return str_replace(
		array( '\n', '\N', '\,', '\;', '\\\\' ),
		array( "\n", "\n", ',', ';', '\\' ),
		(string) $text
	);
}

/**
 * Συγχρονισμός external κρατήσεων: προσθήκη νέων, ενημέρωση,
 * αφαίρεση ακυρωμένων — ΜΟΝΟ μετά από επιτυχή, έγκυρο sync του source.
 * Τα δικά μας events (UID prefix vpms-) αγνοούνται → όχι loops/duplicates.
 */
function vpms_apply_external_events( $source, $events ) {
	global $wpdb;
	$table  = $wpdb->prefix . 'vpms_bookings';
	// db_errors: πλήθος αποτυχημένων insert/update/delete· db_error_notes:
	// σύντομες περιγραφές (χωρίς PII) ώστε το sync να ΜΗΝ δηλωθεί επιτυχές.
	$counts = array( 'added' => 0, 'updated' => 0, 'deleted' => 0, 'db_errors' => 0, 'db_error_notes' => array() );

	$note = function ( $text ) use ( &$counts ) {
		if ( count( $counts['db_error_notes'] ) < 5 ) {
			$counts['db_error_notes'][] = $text;
		}
	};

	$seen = array();
	foreach ( $events as $e ) {
		$uid = $e['uid'] !== '' ? $e['uid'] : md5( $source . $e['start'] . $e['end'] );
		// Αγνόησε events που είναι δικές μας κρατήσεις που γύρισαν πίσω (loop protection)
		if ( strpos( $uid, 'vpms-' ) === 0 ) continue;
		// Μόνο μελλοντικά/τρέχοντα events μας ενδιαφέρουν
		if ( $e['end'] < current_time( 'Y-m-d' ) ) continue;

		// STATUS:CANCELLED / METHOD:CANCEL → δεν δημιουργείται ενεργή κράτηση·
		// αν υπάρχει ήδη με το ίδιο UID, αφαιρείται (και δεν εξάγεται πουθενά).
		if ( ! empty( $e['cancelled'] ) ) {
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM $table WHERE external_uid = %s AND source = %s AND status = 'external'",
				$uid, $source
			) );
			if ( false === $deleted ) {
				$counts['db_errors']++;
				$note( 'cancel-delete failed ' . $e['start'] . '→' . $e['end'] );
			} elseif ( $deleted ) {
				$counts['deleted'] += (int) $deleted;
			}
			continue; // εκτός $seen — δεν «προστατεύεται» από το deletion pass
		}
		$seen[] = $uid;

		$exists = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, checkin, checkout FROM $table WHERE external_uid = %s AND source = %s",
			$uid, $source
		) );
		if ( $exists ) {
			if ( $exists->checkin !== $e['start'] || $exists->checkout !== $e['end'] ) {
				// Οι ημερομηνίες ενός υπάρχοντος external event άλλαξαν στην OTA.
				// Ελέγχουμε ξανά για overlap ΕΞΑΙΡΩΝΤΑΣ την ίδια external κράτηση,
				// και μετά ενημερώνουμε (η OTA κράτηση είναι πραγματική — δεν
				// αγνοείται). Αν προκύψει overlap, καταγράφεται conflict warning.
				// Επειδή αυτό τρέχει ΜΟΝΟ όταν άλλαξαν οι ημερομηνίες, το ίδιο
				// conflict ΔΕΝ ξανακαταγράφεται σε κάθε sync χωρίς αλλαγή.
				$overlaps = vpms_overlapping_active_ids( $e['start'], $e['end'], (int) $exists->id );

				$ok = $wpdb->update( $table,
					array( 'checkin' => $e['start'], 'checkout' => $e['end'] ),
					array( 'id' => $exists->id )
				);
				if ( false === $ok ) {
					// Το update απέτυχε: μην αυξήσεις το updated count· κατέγραψε
					// sync error. Η πραγματική OTA κράτηση παραμένει (παλιές
					// ημερομηνίες) και σημειώνεται για admin review.
					$counts['db_errors']++;
					$note( 'update failed booking #' . (int) $exists->id . ' ' . $e['start'] . '→' . $e['end'] );
				} else {
					$counts['updated']++;
					if ( $overlaps ) {
						$log = get_option( 'vpms_booking_conflicts', array() );
						if ( ! is_array( $log ) ) $log = array();
						$log[] = array(
							'time'        => current_time( 'mysql' ),
							'source'      => $source,
							'dates'       => $e['start'] . ' → ' . $e['end'],
							'new_booking' => (int) $exists->id,
							'overlapping' => array_slice( $overlaps, 0, 5 ),
						);
						if ( count( $log ) > 20 ) $log = array_slice( $log, -20 );
						update_option( 'vpms_booking_conflicts', $log, false );
					}
				}
			}
		} else {
			// Επικάλυψη με υπάρχουσα ενεργή κράτηση; Η OTA κράτηση είναι
			// πραγματική — ΔΕΝ αγνοείται: αποθηκεύεται ΚΑΙ καταγράφεται
			// conflict με σοβαρό admin notice (χωρίς προσωπικά δεδομένα).
			$overlaps = vpms_overlapping_active_ids( $e['start'], $e['end'], 0 );

			$id = vpms_create_booking( array(
				'checkin' => $e['start'], 'checkout' => $e['end'],
				'status' => 'external', 'source' => $source,
				'external_uid' => $uid,
				'note' => sanitize_text_field( substr( $e['summary'], 0, 190 ) ),
			) );
			if ( is_wp_error( $id ) ) {
				// Το insert της πραγματικής OTA κράτησης απέτυχε: sync error +
				// καταγραφή για admin review (η κράτηση υπάρχει στην πλατφόρμα).
				$counts['db_errors']++;
				$note( 'insert failed ' . $source . ' ' . $e['start'] . '→' . $e['end'] );
			} else {
				$counts['added']++;
				if ( $overlaps ) {
					$log   = get_option( 'vpms_booking_conflicts', array() );
					if ( ! is_array( $log ) ) $log = array();
					$log[] = array(
						'time'        => current_time( 'mysql' ),
						'source'      => $source,
						'dates'       => $e['start'] . ' → ' . $e['end'],
						'new_booking' => (int) $id,
						'overlapping' => array_slice( $overlaps, 0, 5 ),
					);
					if ( count( $log ) > 20 ) $log = array_slice( $log, -20 );
					update_option( 'vpms_booking_conflicts', $log, false );
				}
			}
		}
	}

	// Διαγραφή external κρατήσεων που δεν υπάρχουν πια στο feed
	// (ακυρώθηκαν στην πλατφόρμα) — μόνο μελλοντικές, μόνο του source,
	// και μόνο επειδή το feed ήταν έγκυρο (ο caller το έχει ελέγξει).
	$existing = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, external_uid FROM $table
		 WHERE source = %s AND status = 'external' AND checkout >= CURDATE()", $source
	) );
	foreach ( $existing as $row ) {
		if ( ! in_array( $row->external_uid, $seen, true ) ) {
			$d = $wpdb->delete( $table, array( 'id' => $row->id ) );
			if ( false === $d ) {
				$counts['db_errors']++;
				$note( 'delete failed booking #' . (int) $row->id );
			} else {
				$counts['deleted'] += (int) $d;
			}
		}
	}
	return $counts;
}

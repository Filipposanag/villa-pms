<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Διαχείριση (wp-admin)
 * --------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_menu_page( 'Villa PMS', 'Villa PMS', 'manage_options', 'vpms', 'vpms_admin_bookings', 'dashicons-palmtree', 26 );
	add_submenu_page( 'vpms', 'Bookings', 'Bookings', 'manage_options', 'vpms', 'vpms_admin_bookings' );
	add_submenu_page( 'vpms', 'Pricing', 'Pricing', 'manage_options', 'vpms-rates', 'vpms_admin_rates' );
	add_submenu_page( 'vpms', 'Statistics', 'Statistics', 'manage_options', 'vpms-stats', 'vpms_admin_stats' );
	add_submenu_page( 'vpms', 'Settings', 'Settings', 'manage_options', 'vpms-settings', 'vpms_admin_settings' );
} );

/* Σοβαρή προειδοποίηση αν λείπει το webhook signing secret ενώ το Stripe είναι ενεργό */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s = vpms_get_settings();
	if ( ! empty( $s['stripe_sk'] ) && empty( $s['stripe_whsec'] ) ) {
		echo '<div class="notice notice-error"><p><strong>Villa PMS:</strong> The Stripe <em>Webhook Signing Secret</em> is not configured. '
			. 'Incoming Stripe webhooks are <strong>rejected</strong> until you add it in '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=vpms-settings' ) ) . '">Villa PMS → Settings</a>. '
			. 'Payments are still confirmed via the (fully verified) success page, but the webhook is the reliable path.</p></div>';
	}
} );

/* Σοβαρά notices: payment conflicts (expired→paid με κατειλημμένες μέρες)
 * και booking conflicts (OTA event πάνω σε υπάρχουσα κράτηση). Χωρίς PII. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$pc = get_option( 'vpms_payment_conflicts', array() );
	if ( $pc ) {
		$last = end( $pc );
		echo '<div class="notice notice-error"><p><strong>Villa PMS — PAYMENT CONFLICT (' . count( $pc ) . '):</strong> '
			. 'A Stripe payment was received for booking #' . (int) $last['booking'] . ' (' . esc_html( $last['dates'] ) . ') '
			. 'but the dates were already taken by booking(s) #' . esc_html( implode( ', #', array_map( 'intval', (array) $last['overlapping'] ) ) ) . '. '
			. 'The guest HAS paid — a refund or manual arrangement is required. See bookings marked “Payment conflict” in '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=vpms' ) ) . '">Villa PMS → Bookings</a>.</p></div>';
	}

	$bc = get_option( 'vpms_booking_conflicts', array() );
	if ( $bc ) {
		$last = end( $bc );
		echo '<div class="notice notice-error"><p><strong>Villa PMS — CALENDAR CONFLICT (' . count( $bc ) . '):</strong> '
			. 'An imported ' . esc_html( ucfirst( $last['source'] ) ) . ' reservation (' . esc_html( $last['dates'] ) . ', booking #' . (int) $last['new_booking'] . ') '
			. 'overlaps existing booking(s) #' . esc_html( implode( ', #', array_map( 'intval', (array) $last['overlapping'] ) ) ) . '. '
			. 'Both are stored — please resolve the double booking manually and dismiss this in '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=vpms' ) ) . '">Villa PMS → Bookings</a>.</p></div>';
	}

	// Εγγραφές που μεταφέρθηκαν από το παλιό status 'refund_pending' (1.2.2 → 1.2.3):
	// κρατήθηκαν ως Paid + Refund status Pending ώστε να ΜΗΝ ελευθερωθούν οι μέρες.
	$rm = get_option( 'vpms_refund_migrated', array() );
	if ( is_array( $rm ) && $rm ) {
		echo '<div class="notice notice-warning"><p><strong>Villa PMS — REFUND MIGRATION (' . count( $rm ) . '):</strong> '
			. 'On upgrade to 1.2.3, booking(s) #' . esc_html( implode( ', #', array_map( 'intval', $rm ) ) ) . ' had a refund in progress. '
			. 'They were kept as <strong>Paid</strong> with <strong>Refund status: Pending</strong> so their dates stayed blocked. '
			. 'Please verify each refund in the Stripe Dashboard, then dismiss this in '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=vpms' ) ) . '">Villa PMS → Bookings</a>.</p></div>';
	}
} );

/* ---------- Κρατήσεις ---------- */
function vpms_admin_bookings() {
	global $wpdb;
	$table = $wpdb->prefix . 'vpms_bookings';
	$refund_confirm = 0;

	// Ενέργειες
	if ( isset( $_POST['vpms_action'] ) && check_admin_referer( 'vpms_admin' ) && current_user_can( 'manage_options' ) ) {
		$act = sanitize_text_field( wp_unslash( $_POST['vpms_action'] ) );

		if ( $act === 'cancel' && ! empty( $_POST['booking_id'] ) ) {
			// Απλή ακύρωση: ΔΕΝ κάνει refund — το refund είναι ξεχωριστή, ρητή ενέργεια.
			vpms_update_booking( (int) $_POST['booking_id'], array( 'status' => 'cancelled' ) );
			echo '<div class="notice notice-success"><p>Booking cancelled. No refund was issued — use the refund button if you also want to refund the payment.</p></div>';
		}

		if ( $act === 'refund_confirm' && ! empty( $_POST['booking_id'] ) ) {
			$refund_confirm = (int) $_POST['booking_id'];
		}

		if ( $act === 'refund' && ! empty( $_POST['booking_id'] ) ) {
			$res = vpms_stripe_refund_booking( (int) $_POST['booking_id'] );
			if ( is_wp_error( $res ) ) {
				$code = $res->get_error_code();
				if ( $code === 'reconciliation' ) {
					echo '<div class="notice notice-error"><p>' . esc_html( '⚠️ RECONCILIATION REQUIRED: ' . $res->get_error_message() ) . '</p></div>';
				} elseif ( in_array( $code, array( 'in_progress', 'already' ), true ) ) {
					echo '<div class="notice notice-info"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html( 'Refund failed: ' . $res->get_error_message() ) . '</p></div>';
				}
			} elseif ( $res === 'pending' ) {
				// Σε εξέλιξη — ΟΧΙ "completed": θα κλείσει μέσω reconciliation.
				echo '<div class="notice notice-warning"><p>The refund was submitted and is <strong>in progress</strong> at Stripe. The booking status is unchanged and the <strong>Refund</strong> column shows “Pending”; if it was a paid booking the dates stay blocked until the refund succeeds (checked automatically every hour).</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>Refund completed via Stripe. The booking is now marked as refunded and the dates are released.</p></div>';
			}
		}

		if ( $act === 'resend_confirmation' && ! empty( $_POST['booking_id'] ) ) {
			$rb = vpms_get_booking( (int) $_POST['booking_id'] );
			if ( $rb && $rb->status === 'paid' && $rb->source === 'direct' ) {
				vpms_send_confirmation_emails( $rb );
				$rb = vpms_get_booking( $rb->id );
				$done = ( $rb->confirmation_owner_sent && ( ! $rb->guest_email || $rb->confirmation_guest_sent ) );
				echo $done
					? '<div class="notice notice-success"><p>Confirmation emails sent.</p></div>'
					: '<div class="notice notice-error"><p>Sending failed again: ' . esc_html( $rb->confirmation_last_error ?: 'unknown mail error' ) . '. Check your email/SMTP configuration.</p></div>';
			}
		}

		if ( $act === 'clear_conflicts' ) {
			delete_option( 'vpms_payment_conflicts' );
			delete_option( 'vpms_booking_conflicts' );
			echo '<div class="notice notice-success"><p>Conflict notices dismissed.</p></div>';
		}

		if ( $act === 'clear_refund_migration' ) {
			delete_option( 'vpms_refund_migrated' );
			echo '<div class="notice notice-success"><p>Refund migration notice dismissed.</p></div>';
		}

		if ( $act === 'anonymize' && ! empty( $_POST['booking_id'] ) ) {
			vpms_anonymize_booking( (int) $_POST['booking_id'] );
			echo '<div class="notice notice-success"><p>Personal data removed from the booking (dates and amounts kept for statistics).</p></div>';
		}

		if ( $act === 'block' ) {
			$from = sanitize_text_field( wp_unslash( $_POST['block_from'] ?? '' ) );
			$to   = sanitize_text_field( wp_unslash( $_POST['block_to'] ?? '' ) );
			if ( vpms_valid_date( $from ) && vpms_valid_date( $to ) && $to > $from ) {
				$res = vpms_create_booking_atomic( array(
					'checkin' => $from, 'checkout' => $to,
					'status' => 'blocked', 'source' => 'manual',
					'note' => sanitize_text_field( wp_unslash( $_POST['block_note'] ?? '' ) ),
				) );
				if ( is_wp_error( $res ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success"><p>Dates blocked.</p></div>';
				}
			} else {
				echo '<div class="notice notice-error"><p>Invalid dates.</p></div>';
			}
		}

		if ( $act === 'manual_add' ) {
			$from = sanitize_text_field( wp_unslash( $_POST['m_from'] ?? '' ) );
			$to   = sanitize_text_field( wp_unslash( $_POST['m_to'] ?? '' ) );
			if ( ! vpms_valid_date( $from ) || ! vpms_valid_date( $to ) || $to <= $from ) {
				echo '<div class="notice notice-error"><p>Invalid dates.</p></div>';
			} else {
				$res = vpms_create_booking_atomic( array(
					'checkin' => $from, 'checkout' => $to,
					'guest_name'  => sanitize_text_field( wp_unslash( $_POST['m_name'] ?? '' ) ),
					'guest_email' => sanitize_email( wp_unslash( $_POST['m_email'] ?? '' ) ),
					'guest_phone' => sanitize_text_field( wp_unslash( $_POST['m_phone'] ?? '' ) ),
					'guests'      => max( 1, (int) ( $_POST['m_guests'] ?? 2 ) ),
					'amount'      => max( 0, (float) ( $_POST['m_amount'] ?? 0 ) ),
					'currency'    => strtoupper( vpms_get_settings()['currency'] ),
					'status'      => 'paid', 'source' => 'manual',
					'note'        => sanitize_text_field( wp_unslash( $_POST['m_note'] ?? '' ) ),
				) );
				if ( is_wp_error( $res ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success"><p>Booking added. The dates will also be blocked on the platforms via iCal. If an email was provided, the guest will receive the automated emails.</p></div>';
				}
			}
		}

		if ( $act === 'sync_now' ) {
			vpms_run_ical_import();
			echo '<div class="notice notice-success"><p>iCal sync completed — see feed status below.</p></div>';
		}
	}

	$rows = $wpdb->get_results(
		"SELECT * FROM $table WHERE status != 'pending' OR created_at >= UTC_TIMESTAMP() - INTERVAL 41 MINUTE
		 ORDER BY checkin DESC LIMIT 200"
	);

	$labels = array(
		'paid' => '✅ Paid', 'pending' => '⏳ Pending', 'cancelled' => '❌ Cancelled',
		'external' => '🔗 Platform', 'blocked' => '🔒 Blocked', 'refunded' => '↩️ Refunded',
		'expired' => '⌛ Expired', 'payment_conflict' => '⚠️ Payment conflict',
		'refund_pending' => '⏳ Refund pending', // legacy (μεταφέρθηκε σε 1.2.3)
	);
	// Το refund status εμφανίζεται ΞΕΧΩΡΙΣΤΑ από το booking status.
	$refund_labels = array(
		'pending'         => '⏳ Pending',
		'requires_action' => '⏳ Requires action',
		'processing'      => '⏳ Processing',
		'succeeded'       => '↩️ Refunded',
		'failed'          => '⚠️ Failed',
		'canceled'        => '⚠️ Canceled',
	);
	$sources = array( 'direct' => 'Website', 'booking' => 'Booking.com', 'airbnb' => 'Airbnb', 'manual' => 'Manual' );

	$last_sync = get_option( 'vpms_last_sync' );
	$sync      = vpms_get_sync_status();
	?>
	<div class="wrap">
		<h1>Bookings</h1>

		<?php if ( $refund_confirm ) :
			$rb = vpms_get_booking( $refund_confirm );
			if ( $rb && in_array( $rb->status, array( 'paid', 'cancelled', 'payment_conflict' ), true )
				&& ! empty( $rb->stripe_payment_intent )
				&& ! in_array( (string) $rb->refund_status, array( 'pending', 'requires_action', 'processing', 'succeeded' ), true ) ) :
				$is_retry = in_array( (string) $rb->refund_status, array( 'failed', 'canceled' ), true ); ?>
			<div class="notice notice-warning" style="padding:14px 16px;">
				<h2 style="margin-top:0;"><?php echo $is_retry ? 'Retry full refund' : 'Confirm full refund'; ?></h2>
				<?php if ( $is_retry && $rb->refund_last_error ) : ?>
					<p style="color:#a4362a;"><strong>Previous attempt:</strong> <?php echo esc_html( $rb->refund_last_error ); ?></p>
				<?php endif; ?>
				<p>You are about to refund <strong><?php echo esc_html( vpms_format_money( $rb->amount, $rb->currency ) ); ?></strong>
					to <strong><?php echo esc_html( $rb->guest_name ? $rb->guest_name : 'guest' ); ?></strong>
					(booking #<?php echo (int) $rb->id; ?>,
					<?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $rb->checkin ) ) . ' – ' . date_i18n( 'd/m/Y', strtotime( $rb->checkout ) ) ); ?>)
					via the Stripe Refund API. This cannot be undone.</p>
				<form method="post" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'vpms_admin' ); ?>
					<input type="hidden" name="vpms_action" value="refund">
					<input type="hidden" name="booking_id" value="<?php echo (int) $rb->id; ?>">
					<button class="button button-primary">Yes, refund now</button>
				</form>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vpms' ) ); ?>">No, go back</a>
			</div>
			<?php endif;
		endif; ?>

		<form method="post" style="display:inline-block;margin:8px 12px 8px 0;">
			<?php wp_nonce_field( 'vpms_admin' ); ?>
			<input type="hidden" name="vpms_action" value="sync_now">
			<button class="button">🔄 Sync iCal now</button>
			<?php if ( $last_sync ) echo '<span style="margin-left:8px;color:#666;">Last sync run: ' . esc_html( $last_sync ) . '</span>'; ?>
		</form>
		<?php if ( get_option( 'vpms_payment_conflicts' ) || get_option( 'vpms_booking_conflicts' ) ) : ?>
		<form method="post" style="display:inline-block;margin:8px 0;" onsubmit="return confirm('Dismiss all conflict notices? Make sure you have resolved the double bookings first.');">
			<?php wp_nonce_field( 'vpms_admin' ); ?>
			<input type="hidden" name="vpms_action" value="clear_conflicts">
			<button class="button">✔ Dismiss conflict notices</button>
		</form>
		<?php endif; ?>
		<?php if ( get_option( 'vpms_refund_migrated' ) ) : ?>
		<form method="post" style="display:inline-block;margin:8px 0;" onsubmit="return confirm('Dismiss the refund migration notice? Make sure you have verified each migrated refund in the Stripe Dashboard first.');">
			<?php wp_nonce_field( 'vpms_admin' ); ?>
			<input type="hidden" name="vpms_action" value="clear_refund_migration">
			<button class="button">✔ Dismiss refund migration notice</button>
		</form>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:760px;margin-top:8px;">
			<thead><tr><th>iCal feed</th><th>Last successful sync</th><th>Last error</th><th>Events</th><th>+ / ~ / −</th></tr></thead>
			<tbody>
			<?php foreach ( array( 'booking' => 'Booking.com', 'airbnb' => 'Airbnb' ) as $src => $label ) :
				$st = isset( $sync[ $src ] ) ? $sync[ $src ] : array(); ?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><?php echo esc_html( isset( $st['last_success'] ) ? $st['last_success'] : '—' ); ?></td>
					<td><?php
						if ( ! empty( $st['last_error'] ) ) {
							echo '<span style="color:#a4362a;">' . esc_html( $st['last_error'] ) . '</span>'
								. ( ! empty( $st['last_error_at'] ) ? ' <small>(' . esc_html( $st['last_error_at'] ) . ')</small>' : '' );
						} else {
							echo '—';
						}
					?></td>
					<td><?php echo isset( $st['events_found'] ) ? (int) $st['events_found'] : '—'; ?></td>
					<td><?php echo isset( $st['added'] )
						? (int) $st['added'] . ' / ' . (int) $st['updated'] . ' / ' . (int) $st['deleted'] : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:24px;">Block dates</h2>
		<form method="post" style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:640px;">
			<?php wp_nonce_field( 'vpms_admin' ); ?>
			<input type="hidden" name="vpms_action" value="block">
			From <input type="date" name="block_from" required>
			To (checkout) <input type="date" name="block_to" required>
			Note <input type="text" name="block_note" placeholder="e.g. personal use">
			<button class="button button-primary">Block</button>
		</form>

		<h2 style="margin-top:24px;">New booking (phone)</h2>
		<form method="post" style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:640px;">
			<?php wp_nonce_field( 'vpms_admin' ); ?>
			<input type="hidden" name="vpms_action" value="manual_add">
			<p>Check-in <input type="date" name="m_from" required>
			   Check-out <input type="date" name="m_to" required>
			   Guests <input type="number" name="m_guests" value="2" min="1" style="width:60px;"></p>
			<p>Name <input type="text" name="m_name" required>
			   Email <input type="email" name="m_email" placeholder="optional">
			   Phone <input type="text" name="m_phone"></p>
			<p>Amount <input type="number" step="0.01" min="0" name="m_amount" style="width:110px;" placeholder="0.00">
			   Note <input type="text" name="m_note" placeholder="e.g. paid by bank transfer">
			   <button class="button button-primary">Add booking</button></p>
			<p class="description">If an email is provided, the guest will automatically receive the arrival and thank-you emails.</p>
		</form>

		<table class="widefat striped" style="margin-top:24px;">
			<thead><tr>
				<th>ID</th><th>Check-in</th><th>Check-out</th><th>Guest</th>
				<th>Guests</th><th>Amount</th><th>Source</th><th>Status</th><th>Refund</th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="10">No bookings yet.</td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo (int) $r->id; ?></td>
					<td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $r->checkin ) ) ); ?></td>
					<td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $r->checkout ) ) ); ?></td>
					<td>
						<?php echo esc_html( $r->guest_name ? $r->guest_name : '—' ); ?><br>
						<small><?php echo esc_html( $r->guest_email ); ?> <?php echo esc_html( $r->guest_phone ); ?></small>
						<?php if ( $r->note ) echo '<br><small><em>' . esc_html( $r->note ) . '</em></small>'; ?>
					</td>
					<td><?php echo (int) $r->guests; ?></td>
					<td><?php echo $r->amount > 0 ? esc_html( vpms_format_money( $r->amount, $r->currency ) ) : '—'; ?></td>
					<td><?php echo esc_html( isset( $sources[ $r->source ] ) ? $sources[ $r->source ] : $r->source ); ?></td>
					<td><?php echo esc_html( isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status );
						if ( $r->status === 'refunded' && $r->refunded_at ) {
							echo '<br><small>' . esc_html( date_i18n( 'd/m/Y', strtotime( $r->refunded_at ) ) ) . '</small>';
						} ?></td>
					<td><?php
						$rs = (string) $r->refund_status;
						if ( $rs === '' ) {
							echo '—';
						} else {
							echo esc_html( isset( $refund_labels[ $rs ] ) ? $refund_labels[ $rs ] : $rs );
							if ( $rs === 'succeeded' && $r->refunded_at ) {
								echo '<br><small>' . esc_html( date_i18n( 'd/m/Y', strtotime( $r->refunded_at ) ) ) . '</small>';
							} elseif ( in_array( $rs, array( 'failed', 'canceled' ), true ) && $r->refund_last_error ) {
								echo '<br><small style="color:#a4362a;">' . esc_html( $r->refund_last_error ) . '</small>';
							}
						}
					?></td>
					<td>
						<?php if ( in_array( $r->status, array( 'paid', 'blocked' ), true ) ) : ?>
						<form method="post" style="display:inline;" onsubmit="return confirm('Cancel this booking? The dates will be released. No refund will be issued.');">
							<?php wp_nonce_field( 'vpms_admin' ); ?>
							<input type="hidden" name="vpms_action" value="cancel">
							<input type="hidden" name="booking_id" value="<?php echo (int) $r->id; ?>">
							<button class="button button-small">Cancel</button>
						</form>
						<?php endif; ?>
						<?php if ( $r->status === 'paid' && $r->source === 'direct'
							&& ( ! $r->confirmation_owner_sent || ( $r->guest_email && ! $r->confirmation_guest_sent ) ) ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'vpms_admin' ); ?>
							<input type="hidden" name="vpms_action" value="resend_confirmation">
							<input type="hidden" name="booking_id" value="<?php echo (int) $r->id; ?>">
							<button class="button button-small">✉ Resend confirmation</button>
						</form>
						<?php endif; ?>
						<?php
						$refund_eligible = in_array( $r->status, array( 'paid', 'cancelled', 'payment_conflict' ), true )
							&& ! empty( $r->stripe_payment_intent );
						$rs = (string) $r->refund_status;
						if ( $refund_eligible && $r->status !== 'refunded' ) :
							if ( $rs === '' ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'vpms_admin' ); ?>
							<input type="hidden" name="vpms_action" value="refund_confirm">
							<input type="hidden" name="booking_id" value="<?php echo (int) $r->id; ?>">
							<button class="button button-small">Full refund via Stripe</button>
						</form>
							<?php elseif ( in_array( $rs, array( 'failed', 'canceled' ), true ) ) : ?>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'vpms_admin' ); ?>
							<input type="hidden" name="vpms_action" value="refund_confirm">
							<input type="hidden" name="booking_id" value="<?php echo (int) $r->id; ?>">
							<button class="button button-small">↻ Retry full refund</button>
						</form>
							<?php elseif ( in_array( $rs, array( 'pending', 'requires_action', 'processing' ), true ) ) : ?>
						<span class="description" style="color:#8a6d00;">Refund in progress…</span>
							<?php endif;
						endif; ?>
						<?php if ( $r->guest_email || ( $r->guest_name && $r->guest_name !== 'Anonymized' ) ) : ?>
						<form method="post" style="display:inline;" onsubmit="return confirm('Remove the guest personal data from this booking? Dates and amounts are kept.');">
							<?php wp_nonce_field( 'vpms_admin' ); ?>
							<input type="hidden" name="vpms_action" value="anonymize">
							<input type="hidden" name="booking_id" value="<?php echo (int) $r->id; ?>">
							<button class="button button-small">Erase personal data</button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ---------- Τιμές ανά ημέρα ---------- */
function vpms_admin_rates() {
	global $wpdb;

	if ( isset( $_POST['vpms_rate_action'] ) && check_admin_referer( 'vpms_rates' ) && current_user_can( 'manage_options' ) ) {
		$from  = sanitize_text_field( wp_unslash( $_POST['rate_from'] ?? '' ) );
		$to    = sanitize_text_field( wp_unslash( $_POST['rate_to'] ?? '' ) );
		$price = (float) ( $_POST['rate_price'] ?? 0 );
		if ( $_POST['vpms_rate_action'] === 'set_range' && vpms_valid_date( $from ) && vpms_valid_date( $to ) && $to >= $from && $price > 0 ) {
			vpms_set_rate_range( $from, $to, $price );
			echo '<div class="notice notice-success"><p>Prices saved.</p></div>';
		} elseif ( $_POST['vpms_rate_action'] === 'delete_range' && vpms_valid_date( $from ) && vpms_valid_date( $to ) ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}vpms_rates WHERE rate_date BETWEEN %s AND %s", $from, $to
			) );
			echo '<div class="notice notice-success"><p>Custom prices removed (the default now applies).</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>Please check the dates and the price.</p></div>';
		}
	}

	$s     = vpms_get_settings();
	$rates = $wpdb->get_results(
		"SELECT rate_date, price FROM {$wpdb->prefix}vpms_rates
		 WHERE rate_date >= CURDATE() ORDER BY rate_date LIMIT 400"
	);

	// Ομαδοποίηση συνεχόμενων ημερών με ίδια τιμή για ευανάγνωστη λίστα
	$groups = array();
	foreach ( $rates as $r ) {
		$last = $groups ? count( $groups ) - 1 : -1;
		if ( $last >= 0
			&& $groups[ $last ]['price'] == $r->price
			&& ( new DateTime( $groups[ $last ]['to'] ) )->modify( '+1 day' )->format( 'Y-m-d' ) === $r->rate_date ) {
			$groups[ $last ]['to'] = $r->rate_date;
		} else {
			$groups[] = array( 'from' => $r->rate_date, 'to' => $r->rate_date, 'price' => $r->price );
		}
	}
	?>
	<div class="wrap">
		<h1>Daily pricing</h1>
		<p>Default price: <strong><?php echo esc_html( vpms_format_money( $s['default_price'], $s['currency'] ) ); ?></strong> / night
			(change it in <a href="<?php echo esc_url( admin_url( 'admin.php?page=vpms-settings' ) ); ?>">Settings</a>).
			Here you can set different prices for specific periods — e.g. seasons, holidays, weekends.</p>

		<form method="post" style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:640px;">
			<?php wp_nonce_field( 'vpms_rates' ); ?>
			<input type="hidden" name="vpms_rate_action" value="set_range">
			From <input type="date" name="rate_from" required>
			To <input type="date" name="rate_to" required>
			Price/night <input type="number" step="0.01" min="1" name="rate_price" style="width:100px;">
			<button class="button button-primary">Save</button>
		</form>

		<form method="post" style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:640px;margin-top:8px;">
			<?php wp_nonce_field( 'vpms_rates' ); ?>
			<input type="hidden" name="vpms_rate_action" value="delete_range">
			Reset to default: From <input type="date" name="rate_from" required>
			To <input type="date" name="rate_to" required>
			<button class="button">Reset</button>
		</form>

		<h2 style="margin-top:24px;">Active custom prices</h2>
		<table class="widefat striped" style="max-width:640px;">
			<thead><tr><th>From</th><th>To</th><th>Price/night</th></tr></thead>
			<tbody>
			<?php if ( ! $groups ) : ?>
				<tr><td colspan="3">No custom prices — the default applies everywhere.</td></tr>
			<?php endif; ?>
			<?php foreach ( $groups as $g ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $g['from'] ) ) ); ?></td>
					<td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $g['to'] ) ) ); ?></td>
					<td><?php echo esc_html( vpms_format_money( $g['price'], $s['currency'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ---------- Ρυθμίσεις ---------- */
function vpms_admin_settings() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	// Ανανέωση iCal token (ξεχωριστό κουμπί + nonce ανά κανάλι)
	if ( isset( $_POST['vpms_regen_token'] ) && check_admin_referer( 'vpms_tokens' ) ) {
		$channel = sanitize_key( wp_unslash( $_POST['vpms_regen_token'] ) );
		if ( in_array( $channel, array( 'airbnb', 'booking' ), true ) ) {
			update_option( 'vpms_ical_token_' . $channel, vpms_generate_token(), false );
			echo '<div class="notice notice-success"><p>New token generated for the ' . esc_html( ucfirst( $channel ) ) . ' feed. Update the URL on the platform.</p></div>';
		}
	}

	if ( isset( $_POST['vpms_save_settings'] ) && check_admin_referer( 'vpms_settings' ) ) {
		$s = vpms_get_settings();

		foreach ( array( 'property_name', 'stripe_pk', 'ical_booking', 'ical_airbnb', 'notify_email',
			'checkin_time', 'checkout_time', 'wifi_ssid', 'wifi_pass', 'review_link', 'host_phone' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) $s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}

		// Email αποστολέα: έγκυρο email ή κενό (= προεπιλογή WordPress)
		if ( isset( $_POST['from_email'] ) ) {
			$fe = sanitize_email( wp_unslash( $_POST['from_email'] ) );
			$s['from_email'] = ( $fe && is_email( $fe ) ) ? $fe : '';
		}

		// Secrets: αν το πεδίο μείνει κενό διατηρείται το υπάρχον·
		// διαγραφή μόνο με το ρητό checkbox. Δεν εμφανίζονται ποτέ ξανά στο HTML.
		foreach ( array( 'stripe_sk', 'stripe_whsec' ) as $key ) {
			if ( ! empty( $_POST[ $key . '_clear' ] ) ) {
				$s[ $key ] = '';
			} elseif ( isset( $_POST[ $key ] ) && trim( (string) wp_unslash( $_POST[ $key ] ) ) !== '' ) {
				$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		// Currency: έγκυρος 3γράμματος ISO κωδικός
		$cur = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'EUR' ) ) );
		$s['currency'] = preg_match( '/^[A-Z]{3}$/', $cur ) ? $cur : 'EUR';

		$s['default_price']   = max( 0, (float) ( $_POST['default_price'] ?? 150 ) );
		$s['cleaning_fee']    = max( 0, (float) ( $_POST['cleaning_fee'] ?? 0 ) );
		$s['min_nights']      = max( 1, (int) ( $_POST['min_nights'] ?? 1 ) );
		$s['max_guests']      = max( 1, (int) ( $_POST['max_guests'] ?? 8 ) );
		$s['max_nights']      = max( 1, (int) ( $_POST['max_nights'] ?? 60 ) );
		$s['advance_months']  = max( 1, (int) ( $_POST['advance_months'] ?? 24 ) );
		$s['prearrival_days'] = max( 0, (int) ( $_POST['prearrival_days'] ?? 3 ) );
		$s['arrival_info']    = sanitize_textarea_field( wp_unslash( $_POST['arrival_info'] ?? '' ) );
		update_option( 'vpms_settings', $s );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	if ( isset( $_POST['vpms_test_emails'] ) && check_admin_referer( 'vpms_settings' ) ) {
		vpms_send_test_emails();
		echo '<div class="notice notice-success"><p>Two test emails were sent to ' . esc_html( vpms_get_settings()['notify_email'] ) . ' (check your spam folder too).</p></div>';
	}

	$s       = vpms_get_settings();
	$webhook = home_url( '/wp-json/vpms/v1/stripe-webhook' );
	$url_air = vpms_ical_export_url( 'airbnb' );
	$url_bkg = vpms_ical_export_url( 'booking' );

	$field = function ( $label, $name, $value, $type = 'text', $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . ( $type === 'number' ? ' step="0.01"' : '' ) . '>';
		if ( $help ) echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		echo '</td></tr>';
	};

	// Secret πεδίο: ποτέ δεν κάνει echo την αποθηκευμένη τιμή στο HTML.
	$secret_field = function ( $label, $name, $configured, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="password" name="' . esc_attr( $name ) . '" value="" class="regular-text" autocomplete="new-password" placeholder="'
			. ( $configured ? 'Configured — leave empty to keep' : 'Not configured' ) . '"> ';
		echo $configured
			? '<span style="color:#008a20;font-weight:600;">✔ Configured</span>'
			: '<span style="color:#a4362a;font-weight:600;">✖ Missing</span>';
		echo '<br><label style="margin-top:4px;display:inline-block;"><input type="checkbox" name="' . esc_attr( $name ) . '_clear" value="1"> Delete the stored value</label>';
		if ( $help ) echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		echo '</td></tr>';
	};

	$stripe_mode = '';
	if ( ! empty( $s['stripe_sk'] ) ) {
		$stripe_mode = ( strpos( $s['stripe_sk'], 'sk_live_' ) === 0 || strpos( $s['stripe_sk'], 'rk_live_' ) === 0 ) ? 'Live' : 'Test';
	}
	$sync      = vpms_get_sync_status();
	$next_cron = wp_next_scheduled( 'vpms_ical_sync' );
	?>
	<div class="wrap">
		<h1>Villa PMS Settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'vpms_settings' ); ?>
			<h2>Property</h2>
			<table class="form-table">
				<?php
				$field( 'Property name', 'property_name', $s['property_name'], 'text',
					'Shown in emails, the booking confirmation and Stripe Checkout. Default: the site title.' );
				?>
			</table>

			<h2>Pricing &amp; booking rules</h2>
			<table class="form-table">
				<?php
				$field( 'Default price / night', 'default_price', $s['default_price'], 'number' );
				$field( 'Cleaning fee (per stay)', 'cleaning_fee', $s['cleaning_fee'], 'number' );
				$field( 'Minimum nights', 'min_nights', $s['min_nights'], 'number' );
				$field( 'Maximum nights per booking', 'max_nights', $s['max_nights'], 'number' );
				$field( 'Maximum guests', 'max_guests', $s['max_guests'], 'number' );
				$field( 'Booking window (months ahead)', 'advance_months', $s['advance_months'], 'number' );
				$field( 'Currency', 'currency', $s['currency'], 'text', 'ISO code, e.g. EUR, USD, GBP' );
				$field( 'Notification email', 'notify_email', $s['notify_email'], 'email' );
				$field( 'Email sender (From)', 'from_email', $s['from_email'], 'email',
					'All guest emails (confirmation, arrival, thank-you) will be sent from this address, e.g. <code>booking@isleofolive.gr</code>. '
					. 'Leave empty to use the WordPress default. The address should belong to your domain (and have SPF/DKIM set up, or use an SMTP plugin) so emails do not land in spam.' );
				?>
			</table>

			<h2>Stripe</h2>
			<table class="form-table">
				<?php
				$field( 'Publishable Key', 'stripe_pk', $s['stripe_pk'], 'text', 'From Stripe Dashboard &rarr; Developers &rarr; API keys' );
				$secret_field( 'Secret Key', 'stripe_sk', ! empty( $s['stripe_sk'] ) );
				$secret_field( 'Webhook Signing Secret', 'stripe_whsec', ! empty( $s['stripe_whsec'] ),
					'Add a webhook in Stripe with URL: <code>' . esc_html( $webhook ) . '</code> and enable <strong>all three</strong> events: '
					. '<code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.expired</code>. '
					. '<strong>Required</strong> — without the signing secret, incoming webhooks are rejected. '
					. '(Refund finalisation is reconciled automatically via the Stripe API — no refund events needed.)' );
				?>
			</table>

			<h2>Automated guest emails</h2>
			<table class="form-table">
				<?php
				$field( 'Check-in time', 'checkin_time', $s['checkin_time'], 'text', 'e.g. 15:00' );
				$field( 'Check-out time', 'checkout_time', $s['checkout_time'] );
				$field( 'Days before arrival', 'prearrival_days', $s['prearrival_days'], 'number', 'How many days before check-in the arrival email is sent (default 3)' );
				$field( 'WiFi network', 'wifi_ssid', $s['wifi_ssid'] );
				$field( 'WiFi password', 'wifi_pass', $s['wifi_pass'] );
				$field( 'Contact phone', 'host_phone', $s['host_phone'], 'text' );
				$field( 'Review link', 'review_link', $s['review_link'], 'url', 'e.g. your Google review link — included in the thank-you email' );
				?>
				<tr><th scope="row">Arrival instructions</th><td>
					<textarea name="arrival_info" rows="5" class="large-text"><?php echo esc_textarea( $s['arrival_info'] ); ?></textarea>
					<p class="description">Address, driving directions, where to park, how to collect the keys. Included as-is in the arrival email.</p>
				</td></tr>
			</table>
			<p><button class="button" name="vpms_test_emails" value="1">✉️ Send me both test emails</button></p>

			<h2>iCal sync</h2>
			<table class="form-table">
				<tr><th scope="row">Export URL for <strong>Airbnb</strong></th><td>
					<code style="user-select:all;"><?php echo esc_html( $url_air ); ?></code>
					<p class="description">Paste this in Airbnb → Calendar → Availability → Connect calendars → Import. It contains your direct/manual bookings, blocks and Booking.com reservations.</p>
				</td></tr>
				<tr><th scope="row">Export URL for <strong>Booking.com</strong></th><td>
					<code style="user-select:all;"><?php echo esc_html( $url_bkg ); ?></code>
					<p class="description">Paste this in Booking.com Extranet → Rates &amp; Availability → Calendar → Sync calendars. It contains your direct/manual bookings, blocks and Airbnb reservations.</p>
				</td></tr>
				<?php
				$field( 'iCal URL from Booking.com', 'ical_booking', $s['ical_booking'], 'url', 'The export link provided by Booking.com (HTTPS)' );
				$field( 'iCal URL from Airbnb', 'ical_airbnb', $s['ical_airbnb'], 'url', 'The export link provided by Airbnb (HTTPS)' );
				?>
			</table>

			<p><button class="button button-primary" name="vpms_save_settings" value="1">Save settings</button></p>
		</form>

		<form method="post" style="margin-top:8px;">
			<?php wp_nonce_field( 'vpms_tokens' ); ?>
			<button class="button" name="vpms_regen_token" value="airbnb" onclick="return confirm('Generate a new token for the Airbnb feed? The old URL will stop working and you must update it on Airbnb.');">🔑 Regenerate Airbnb feed token</button>
			<button class="button" name="vpms_regen_token" value="booking" onclick="return confirm('Generate a new token for the Booking.com feed? The old URL will stop working and you must update it on Booking.com.');">🔑 Regenerate Booking.com feed token</button>
		</form>

		<hr>
		<h2>System status</h2>
		<table class="widefat striped" style="max-width:760px;">
			<tbody>
				<tr><td>Stripe secret key</td><td><?php echo ! empty( $s['stripe_sk'] ) ? '✅ Configured' : '❌ Missing'; ?></td></tr>
				<tr><td>Stripe webhook secret</td><td><?php echo ! empty( $s['stripe_whsec'] ) ? '✅ Configured' : '❌ Missing (webhooks are rejected)'; ?></td></tr>
				<tr><td>Stripe mode</td><td><?php echo $stripe_mode ? esc_html( $stripe_mode ) : '—'; ?></td></tr>
				<tr><td>Stripe webhook events required</td><td>⚠️ Make sure ALL of these are enabled on the Stripe webhook:<br>
					<code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.expired</code></td></tr>
				<tr><td>Booking.com import feed</td><td><?php echo ! empty( $s['ical_booking'] ) ? '✅ Configured' : '❌ Missing'; ?></td></tr>
				<tr><td>Airbnb import feed</td><td><?php echo ! empty( $s['ical_airbnb'] ) ? '✅ Configured' : '❌ Missing'; ?></td></tr>
				<tr><td>Booking.com export URL</td><td><code style="font-size:11px;"><?php echo esc_html( $url_bkg ); ?></code></td></tr>
				<tr><td>Airbnb export URL</td><td><code style="font-size:11px;"><?php echo esc_html( $url_air ); ?></code></td></tr>
				<tr><td>Next scheduled iCal sync (WP-Cron)</td><td><?php echo $next_cron ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'Y-m-d H:i:s' ) ) : '❌ Not scheduled'; ?></td></tr>
				<?php foreach ( array( 'booking' => 'Booking.com', 'airbnb' => 'Airbnb' ) as $src => $label ) :
					$st = isset( $sync[ $src ] ) ? $sync[ $src ] : array(); ?>
				<tr><td><?php echo esc_html( $label ); ?> — last successful sync</td><td><?php echo esc_html( isset( $st['last_success'] ) ? $st['last_success'] : '—' ); ?></td></tr>
				<tr><td><?php echo esc_html( $label ); ?> — last error</td><td><?php echo ! empty( $st['last_error'] ) ? esc_html( $st['last_error'] ) : '—'; ?></td></tr>
				<?php endforeach; ?>
				<tr><td>WordPress timezone</td><td><?php echo esc_html( wp_timezone_string() ); ?></td></tr>
				<tr><td>Plugin database version</td><td><?php echo esc_html( get_option( 'vpms_db_version', '—' ) ); ?></td></tr>
			</tbody>
		</table>

		<hr>
		<h2>Using on your site</h2>
		<p>Place the shortcode <code>[villa_booking]</code> on any page where you want the calendar and booking form to appear.</p>
	</div>
	<?php
}

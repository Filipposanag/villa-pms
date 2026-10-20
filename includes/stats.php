<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Στατιστικά: πληρότητα, έσοδα, μέση τιμή, ανά πηγή, ανά μήνα
 * --------------------------------------------------------------- */

/**
 * Υπολογισμός στατιστικών έτους.
 * Κάθε νύχτα κράτησης αποδίδεται στον μήνα της· τα έσοδα κατανέμονται
 * αναλογικά ανά νύχτα (κράτηση που "πατάει" σε 2 μήνες μοιράζεται).
 */
function vpms_compute_year_stats( $year ) {
	global $wpdb;
	$from = "$year-01-01";
	$to   = ( $year + 1 ) . '-01-01';

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT checkin, checkout, amount, status, source FROM {$wpdb->prefix}vpms_bookings
		 WHERE checkin < %s AND checkout > %s AND status IN ('paid','external','blocked')",
		$to, $from
	) );

	$m = array();
	for ( $i = 1; $i <= 12; $i++ ) {
		$m[ $i ] = array(
			'direct' => 0, 'booking' => 0, 'airbnb' => 0, 'manual' => 0, 'blocked' => 0,
			'revenue' => 0.0,
		);
	}

	foreach ( $rows as $r ) {
		$nights_total = max( 1, ( new DateTime( $r->checkin ) )->diff( new DateTime( $r->checkout ) )->days );
		$per_night    = $r->status === 'paid' ? ( (float) $r->amount / $nights_total ) : 0;

		if ( $r->status === 'blocked' ) {
			$group = 'blocked';
		} elseif ( $r->source === 'booking' ) {
			$group = 'booking';
		} elseif ( $r->source === 'airbnb' ) {
			$group = 'airbnb';
		} elseif ( $r->source === 'manual' ) {
			$group = 'manual';
		} else {
			$group = 'direct';
		}

		$d   = new DateTime( $r->checkin );
		$end = new DateTime( $r->checkout );
		while ( $d < $end ) {
			if ( (int) $d->format( 'Y' ) === (int) $year ) {
				$mon = (int) $d->format( 'n' );
				$m[ $mon ][ $group ]++;
				$m[ $mon ]['revenue'] += $per_night;
			}
			$d->modify( '+1 day' );
		}
	}
	return $m;
}

function vpms_admin_stats() {
	$year    = isset( $_GET['vpms_year'] ) ? (int) $_GET['vpms_year'] : (int) current_time( 'Y' );
	$stats   = vpms_compute_year_stats( $year );
	$s       = vpms_get_settings();
	$cur     = strtoupper( $s['currency'] ); // ISO code — όχι hardcoded €
	$months  = array( 1=>'January','February','March','April','May','June',
	                  'July','August','September','October','November','December' );

	$tot = array( 'occ_nights'=>0, 'days'=>0, 'revenue'=>0.0, 'paid_nights'=>0,
	              'direct'=>0,'booking'=>0,'airbnb'=>0,'manual'=>0,'blocked'=>0 );
	?>
	<div class="wrap">
		<h1>Statistics <?php echo (int) $year; ?></h1>
		<p>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'vpms_year', $year - 1 ) ); ?>">‹ <?php echo $year - 1; ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'vpms_year', $year + 1 ) ); ?>"><?php echo $year + 1; ?> ›</a>
		</p>

		<table class="widefat striped" style="max-width:980px;">
			<thead><tr>
				<th>Month</th>
				<th style="width:220px;">Occupancy</th>
				<th>Nights<br><small>Site / Booking / Airbnb / Phone</small></th>
				<th>Revenue</th>
				<th>Avg rate/night</th>
				<th>Blocked</th>
			</tr></thead>
			<tbody>
			<?php for ( $i = 1; $i <= 12; $i++ ) :
				$d    = $stats[ $i ];
				$dim  = (int) ( new DateTime( sprintf( '%d-%02d-01', $year, $i ) ) )->format( 't' );
				$occ_nights  = $d['direct'] + $d['booking'] + $d['airbnb'] + $d['manual'];
				$occ_pct     = $dim ? round( 100 * $occ_nights / $dim ) : 0;
				$paid_nights = $d['direct'] + $d['manual'];
				$adr         = $paid_nights ? $d['revenue'] / $paid_nights : 0;

				$tot['occ_nights'] += $occ_nights;  $tot['days'] += $dim;
				$tot['revenue'] += $d['revenue'];   $tot['paid_nights'] += $paid_nights;
				foreach ( array('direct','booking','airbnb','manual','blocked') as $k ) $tot[ $k ] += $d[ $k ];
			?>
				<tr>
					<td><strong><?php echo esc_html( $months[ $i ] ); ?></strong></td>
					<td>
						<div style="background:#e8e6de;border-radius:6px;overflow:hidden;height:18px;position:relative;">
							<div style="background:#1f5b74;height:100%;width:<?php echo $occ_pct; ?>%;"></div>
							<span style="position:absolute;top:0;left:8px;font-size:11px;line-height:18px;color:<?php echo $occ_pct > 40 ? '#fff' : '#444'; ?>;"><?php echo $occ_pct; ?>%</span>
						</div>
					</td>
					<td><?php echo (int)$d['direct'] . ' / ' . (int)$d['booking'] . ' / ' . (int)$d['airbnb'] . ' / ' . (int)$d['manual']; ?></td>
					<td><?php echo $d['revenue'] > 0 ? esc_html( number_format( $d['revenue'], 0 ) . " $cur" ) : '—'; ?></td>
					<td><?php echo $adr > 0 ? esc_html( number_format( $adr, 0 ) . " $cur" ) : '—'; ?></td>
					<td><?php echo $d['blocked'] ? (int) $d['blocked'] : '—'; ?></td>
				</tr>
			<?php endfor; ?>
			</tbody>
			<tfoot><tr style="font-weight:600;background:#f6f4ee;">
				<td>Total <?php echo (int) $year; ?></td>
				<td>Year occupancy: <?php echo $tot['days'] ? round( 100 * $tot['occ_nights'] / $tot['days'] ) : 0; ?>%</td>
				<td><?php echo (int)$tot['direct'] . ' / ' . (int)$tot['booking'] . ' / ' . (int)$tot['airbnb'] . ' / ' . (int)$tot['manual']; ?></td>
				<td><?php echo esc_html( number_format( $tot['revenue'], 0 ) . " $cur" ); ?></td>
				<td><?php echo $tot['paid_nights'] ? esc_html( number_format( $tot['revenue'] / $tot['paid_nights'], 0 ) . " $cur" ) : '—'; ?></td>
				<td><?php echo (int) $tot['blocked']; ?></td>
			</tr></tfoot>
		</table>

		<p style="color:#666;max-width:980px;margin-top:14px;">
			💡 Revenue and average rate cover paid bookings made via the website and by phone (the iCal feeds of
			Booking/Airbnb do not carry amounts — you can see those in their extranets). Occupancy counts all
			sources. Blocked dates (personal use) are not counted in occupancy.
		</p>
	</div>
	<?php
}

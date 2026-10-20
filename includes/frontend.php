<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Frontend: shortcode [villa_booking]
 * Γλώσσα επισκεπτών: Αγγλικά (διεθνές κοινό)
 * Παλέτα: olive / Ionian blue / stone
 * --------------------------------------------------------------- */
add_shortcode( 'villa_booking', function () {
	$s    = vpms_get_settings();
	$prop = vpms_property_name();

	// Η σελίδα κράτησης χρησιμοποιεί per-visitor REST nonce — δεν πρέπει
	// να σερβίρεται από full-page cache (θα λήξει το nonce → 403).
	if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );

	// Επιστροφή από Stripe: fallback επιβεβαίωσης αν δεν έχει προλάβει το webhook.
	// Χρησιμοποιεί την ΙΔΙΑ πλήρη server-side επαλήθευση με το webhook —
	// ποτέ δεν μαρκάρεται paid μόνο επειδή βρέθηκε booking ID στα metadata.
	if ( isset( $_GET['vpms_success'], $_GET['session_id'] ) ) {
		$confirmed = false;
		$session   = vpms_stripe_get_session( sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) );
		if ( $session && ! empty( $session['metadata']['booking_id'] ) ) {
			$result    = vpms_mark_paid_verified( (int) $session['metadata']['booking_id'], $session );
			$confirmed = in_array( $result, array( 'paid', 'already' ), true );
		}
		if ( $confirmed ) {
			return '<div class="vpms-notice vpms-notice--ok">
				<strong>Your booking is confirmed! 🎉</strong><br>
				A confirmation email is on its way. We look forward to welcoming you to ' . esc_html( $prop ) . '.
			</div>' . vpms_frontend_styles();
		}
		return '<div class="vpms-notice vpms-notice--warn">
			Your payment could not be confirmed yet. If you were charged, you will receive an email shortly — otherwise please try again.
		</div>' . vpms_frontend_styles();
	}
	if ( isset( $_GET['vpms_cancel'] ) ) {
		// Ακριβές μήνυμα: hold = Stripe session (~31') + 10' grace ≈ 40 λεπτά.
		echo '<div class="vpms-notice vpms-notice--warn">Payment was cancelled and no charge was made. Your selected dates may stay reserved for up to about 40 minutes before they are released automatically.</div>';
	}

	$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';

	ob_start(); ?>
	<div id="vpms-app"
		data-api="<?php echo esc_attr( rest_url( 'vpms/v1' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		data-currency="<?php echo esc_attr( strtoupper( $s['currency'] ) ); ?>">
		<div class="vpms-cal-wrap">
			<div class="vpms-cal-head">
				<button type="button" class="vpms-nav" id="vpms-prev" aria-label="Previous month">‹</button>
				<div class="vpms-months-titles"></div>
				<button type="button" class="vpms-nav" id="vpms-next" aria-label="Next month">›</button>
			</div>
			<div class="vpms-months" id="vpms-months"></div>
			<div class="vpms-legend">
				<span><i class="vpms-dot vpms-dot--free"></i> Available</span>
				<span><i class="vpms-dot vpms-dot--busy"></i> Booked</span>
				<span><i class="vpms-dot vpms-dot--sel"></i> Your stay</span>
			</div>
			<p class="vpms-error" id="vpms-cal-error" role="alert" hidden></p>
		</div>

		<div class="vpms-summary" id="vpms-summary" hidden>
			<div class="vpms-summary-dates" id="vpms-sum-dates"></div>
			<div class="vpms-summary-lines" id="vpms-sum-lines"></div>
			<div class="vpms-summary-total" id="vpms-sum-total"></div>
		</div>

		<form class="vpms-form" id="vpms-form" hidden>
			<div class="vpms-row">
				<label>Full name<input type="text" name="name" required autocomplete="name" maxlength="190"></label>
				<label>Email<input type="email" name="email" required autocomplete="email" maxlength="190"></label>
			</div>
			<div class="vpms-row">
				<label>Phone<input type="tel" name="phone" autocomplete="tel" maxlength="60"></label>
				<label>Guests<select name="guests" id="vpms-guests"></select></label>
			</div>
			<label class="vpms-privacy">
				<input type="checkbox" name="privacy" id="vpms-privacy" required>
				<span>I agree to the processing of my personal data for this booking as described in the
					<?php if ( $privacy_url ) : ?>
						<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener">Privacy Policy</a>.
					<?php else : ?>
						Privacy Policy.
					<?php endif; ?>
				</span>
			</label>
			<button type="submit" class="vpms-submit" id="vpms-submit">Continue to payment →</button>
			<p class="vpms-error" id="vpms-error" role="alert" hidden></p>
			<p class="vpms-secure">🔒 Secure payment via Stripe</p>
		</form>
	</div>
	<?php
	echo vpms_frontend_styles();
	vpms_frontend_script();
	return ob_get_clean();
} );

function vpms_frontend_styles() {
	return '<style>
	#vpms-app{--vpms-ink:#1C2830;--vpms-sea:#1f5b74;--vpms-sea-soft:#e6f0f3;--vpms-olive:#B9975E;
		--vpms-stone:#f6f4ee;--vpms-line:#e2e0d6;--vpms-busy:#d7d5cb;
		max-width:680px;margin:0 auto;font-size:16px;color:var(--vpms-ink);
		font-family:"DM Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}
	#vpms-app *,#vpms-app *::before,#vpms-app *::after{box-sizing:border-box;}
	/* Πλήρες reset στα κουμπιά — προστασία από CSS του theme */
	#vpms-app button{all:unset;box-sizing:border-box;cursor:pointer;text-align:center;
		font-family:inherit;-webkit-tap-highlight-color:transparent;}
	.vpms-notice{max-width:680px;margin:0 auto 16px;padding:14px 18px;border-radius:10px;}
	.vpms-notice--ok{background:#eef2e6;border:1px solid #c9d4b3;}
	.vpms-notice--warn{background:#fdf3e3;border:1px solid #ecd7ae;}
	.vpms-cal-wrap{background:var(--vpms-stone);border:1px solid var(--vpms-line);border-radius:14px;
		padding:16px;overflow:hidden;}
	.vpms-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
	.vpms-months-titles{display:flex;gap:0;flex:1;font-weight:600;letter-spacing:.06em;
		text-transform:uppercase;font-size:12px;}
	.vpms-months-titles span{flex:1;text-align:center;}
	#vpms-app .vpms-nav{display:inline-block;border:1px solid var(--vpms-line) !important;background:#fff;border-radius:8px;
		width:34px;height:34px;font-size:18px;line-height:34px;color:var(--vpms-ink);flex:none;}
	#vpms-app .vpms-nav:hover{background:var(--vpms-sea-soft);}
	.vpms-months{display:grid;grid-template-columns:1fr 1fr;gap:18px;width:100%;}
	.vpms-months>*{min-width:0;}
	@media(max-width:640px){.vpms-months{grid-template-columns:1fr;}
		.vpms-months .vpms-month:last-child{display:none;}
		.vpms-months-titles span:last-child{display:none;}}
	.vpms-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;width:100%;}
	.vpms-dow{font-size:10px;text-align:center;color:#7d8474;padding:4px 0 6px;text-transform:uppercase;letter-spacing:.05em;}
	#vpms-app .vpms-day{position:relative;aspect-ratio:1;min-width:0;width:100%;border-radius:8px;background:#fff;
		font-size:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;
		gap:0;color:var(--vpms-ink);line-height:1.25;}
	#vpms-app .vpms-day small{font-size:9px;color:var(--vpms-olive);line-height:1;}
	#vpms-app .vpms-day:hover:not(:disabled):not(.is-empty){outline:2px solid var(--vpms-sea);outline-offset:-1px;}
	#vpms-app .vpms-day:disabled{background:var(--vpms-busy);color:#94988c;cursor:not-allowed;text-decoration:line-through;}
	#vpms-app .vpms-day.is-empty,#vpms-app .vpms-day.is-empty:disabled{background:transparent;cursor:default;text-decoration:none;}
	#vpms-app .vpms-day.is-selected{background:var(--vpms-sea);color:#fff;}
	#vpms-app .vpms-day.is-selected small{color:#cfe3ea;}
	#vpms-app .vpms-day.in-range{background:var(--vpms-sea-soft);}
	.vpms-legend{display:flex;gap:16px;margin-top:12px;font-size:12px;color:#6d7466;}
	.vpms-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:4px;vertical-align:-1px;}
	.vpms-dot--free{background:#fff;border:1px solid var(--vpms-line);}
	.vpms-dot--busy{background:var(--vpms-busy);}
	.vpms-dot--sel{background:var(--vpms-sea);}
	.vpms-summary{margin-top:16px;padding:14px 18px;background:#fff;border:1px solid var(--vpms-line);border-radius:12px;}
	.vpms-summary-dates{font-weight:600;margin-bottom:6px;}
	.vpms-summary-lines div{display:flex;justify-content:space-between;font-size:14px;color:#565e50;padding:2px 0;}
	.vpms-summary-total{display:flex;justify-content:space-between;font-weight:700;font-size:18px;
		border-top:1px solid var(--vpms-line);margin-top:8px;padding-top:8px;}
	.vpms-form{margin-top:16px;}
	.vpms-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
	@media(max-width:520px){.vpms-row{grid-template-columns:1fr;}}
	.vpms-form label{display:flex;flex-direction:column;font-size:13px;font-weight:600;gap:4px;letter-spacing:.03em;}
	.vpms-form input,.vpms-form select{padding:10px 12px;border:1px solid var(--vpms-line);border-radius:8px;
		font-size:15px;font-weight:400;background:#fff;width:100%;font-family:inherit;color:var(--vpms-ink);}
	.vpms-form .vpms-privacy{flex-direction:row;align-items:flex-start;gap:8px;font-weight:400;
		font-size:13px;color:#565e50;margin-bottom:12px;}
	.vpms-form .vpms-privacy input{width:auto;margin-top:2px;}
	.vpms-form .vpms-privacy a{color:var(--vpms-sea);}
	#vpms-app .vpms-submit{display:block;width:100%;padding:14px 0;border-radius:10px;
		background:var(--vpms-sea);color:#fff;font-size:16px;font-weight:600;letter-spacing:.02em;}
	#vpms-app .vpms-submit:hover{filter:brightness(1.08);}
	#vpms-app .vpms-submit:disabled{opacity:.6;cursor:wait;}
	.vpms-error{color:#a4362a;font-size:14px;margin-top:8px;}
	.vpms-secure{text-align:center;font-size:12px;color:#7d8474;margin-top:8px;}
	</style>';
}

function vpms_frontend_script() { ?>
	<script>
	(function(){
		const app = document.getElementById('vpms-app');
		if (!app) return;
		const API = app.dataset.api, NONCE = app.dataset.nonce, CUR = app.dataset.currency || 'EUR';
		const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
		const DOW = ['Mo','Tu','We','Th','Fr','Sa','Su'];

		let view = new Date(); view.setDate(1);
		let data = {days:{}, min_nights:1, cleaning_fee:0, max_guests:8};
		let checkin = null, checkout = null;

		const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
		// Δυναμικό νόμισμα με Intl.NumberFormat + fallback αν ο κωδικός
		// νομίσματος δεν υποστηρίζεται από τον browser.
		let money, dayPrice;
		try {
			const moneyFmt = new Intl.NumberFormat('en-GB', {style:'currency', currency: CUR});
			const dayFmt = new Intl.NumberFormat('en-GB', {style:'currency', currency: CUR, maximumFractionDigits: 0});
			money = n => moneyFmt.format(n);
			dayPrice = n => dayFmt.format(n);
		} catch (e) {
			money = n => n.toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' ' + CUR;
			dayPrice = n => Math.round(n) + ' ' + CUR;
		}
		const parse = s => new Date(s+'T12:00:00');

		async function load(){
			const err = document.getElementById('vpms-cal-error');
			const from = new Date(view.getFullYear(), view.getMonth(), 1);
			const to = new Date(view.getFullYear(), view.getMonth()+2, 1);
			try {
				const r = await fetch(API+'/availability?from='+fmt(from)+'&to='+fmt(to));
				const j = await r.json();
				if (!r.ok || j.error || !j.days){
					// ΔΕΝ αντικαθιστούμε τα data με error response — κρατάμε ό,τι είχαμε.
					err.textContent = j.error || 'Could not load availability. Please try again.';
					err.hidden = false;
					return;
				}
				err.hidden = true;
				data = j;
				try { sessionStorage.removeItem('vpmsReloaded'); } catch(e) {}
			} catch(ex) {
				err.textContent = 'Connection problem while loading the calendar. Please try again.';
				err.hidden = false;
				return;
			}
			fillGuests();
			render();
		}

		function fillGuests(){
			const sel = document.getElementById('vpms-guests');
			if (sel.options.length) return;
			for (let i=1;i<=data.max_guests;i++){
				sel.add(new Option(i + (i===1?' guest':' guests'), i, i===2, i===2));
			}
		}

		function render(){
			const wrap = document.getElementById('vpms-months');
			const titles = document.querySelector('.vpms-months-titles');
			wrap.innerHTML = ''; titles.innerHTML = '';
			for (let m=0;m<2;m++){
				const base = new Date(view.getFullYear(), view.getMonth()+m, 1);
				titles.insertAdjacentHTML('beforeend','<span>'+MONTHS[base.getMonth()]+' '+base.getFullYear()+'</span>');
				wrap.appendChild(renderMonth(base));
			}
			renderSummary();
		}

		function renderMonth(base){
			const div = document.createElement('div');
			div.className = 'vpms-month';
			const grid = document.createElement('div');
			grid.className = 'vpms-grid';
			DOW.forEach(d => grid.insertAdjacentHTML('beforeend','<div class="vpms-dow">'+d+'</div>'));
			const firstDow = (base.getDay()+6)%7; // εβδομάδα από Δευτέρα
			for (let i=0;i<firstDow;i++) grid.insertAdjacentHTML('beforeend','<button class="vpms-day is-empty" disabled></button>');
			const daysInMonth = new Date(base.getFullYear(), base.getMonth()+1, 0).getDate();
			for (let d=1;d<=daysInMonth;d++){
				const date = new Date(base.getFullYear(), base.getMonth(), d);
				const key = fmt(date);
				const info = data.days[key];
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'vpms-day';
				btn.dataset.date = key;
				const price = info ? '<small>'+dayPrice(info.price)+'</small>' : '';
				btn.innerHTML = d + price;
				const available = info && info.available;
				const selectingCheckout = checkin && !checkout;
				let enabled;
				if (selectingCheckout && key > checkin){
					// Μέχρι και την πρώτη κατειλημμένη μέρα (επιλέξιμη ως αναχώρηση)·
					// ό,τι είναι μετά από αυτήν μένει κλειδωμένο και γκρι.
					const limit = firstBlockedAfter(checkin);
					enabled = (limit === null) ? available : (key <= limit);
				} else {
					enabled = available;
				}
				if (!enabled) btn.disabled = true;
				if (key === checkin || key === checkout) btn.classList.add('is-selected');
				else if (checkin && checkout && key > checkin && key < checkout) btn.classList.add('in-range');
				btn.addEventListener('click', () => pick(key));
				grid.appendChild(btn);
			}
			div.appendChild(grid);
			return div;
		}

		// Πρώτη κατειλημμένη μέρα μετά το check-in (όριο για checkout)
		function firstBlockedAfter(start){
			let d = parse(start); d.setDate(d.getDate()+1);
			while (true){
				const k = fmt(d);
				if (!data.days[k]) return null; // εκτός φορτωμένων μηνών
				if (!data.days[k].available) return k;
				d.setDate(d.getDate()+1);
			}
		}

		function nightsFree(a, b){
			let d = parse(a);
			const end = parse(b);
			while (d < end){
				const k = fmt(d);
				if (!data.days[k] || !data.days[k].available) return false;
				d.setDate(d.getDate()+1);
			}
			return true;
		}

		function pick(key){
			const err = document.getElementById('vpms-cal-error');
			err.hidden = true;
			if (!checkin || (checkin && checkout)){
				checkin = key; checkout = null;
			} else if (key <= checkin){
				checkin = key;
			} else {
				const nights = Math.round((parse(key)-parse(checkin))/86400000);
				if (nights < data.min_nights){
					err.textContent = 'Minimum stay: ' + data.min_nights + ' nights.';
					err.hidden = false;
					return;
				}
				if (data.max_nights && nights > data.max_nights){
					err.textContent = 'Maximum stay: ' + data.max_nights + ' nights.';
					err.hidden = false;
					return;
				}
				if (!nightsFree(checkin, key)){
					err.textContent = 'The selected dates include booked nights. Please choose a different range.';
					err.hidden = false; checkin = key; checkout = null;
					render(); return;
				}
				checkout = key;
			}
			render();
		}

		function renderSummary(){
			const sum = document.getElementById('vpms-summary');
			const form = document.getElementById('vpms-form');
			if (!checkin || !checkout){ sum.hidden = true; form.hidden = true; return; }
			let total = 0, d = parse(checkin);
			const end = parse(checkout);
			let nights = 0;
			while (d < end){ total += data.days[fmt(d)].price; nights++; d.setDate(d.getDate()+1); }
			const pretty = s => new Date(s+'T12:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'long',year:'numeric'});
			document.getElementById('vpms-sum-dates').textContent = pretty(checkin) + ' → ' + pretty(checkout);
			let lines = '<div><span>'+nights+' night'+(nights>1?'s':'')+'</span><span>'+money(total)+'</span></div>';
			if (data.cleaning_fee > 0){
				lines += '<div><span>Cleaning fee</span><span>'+money(data.cleaning_fee)+'</span></div>';
				total += data.cleaning_fee;
			}
			document.getElementById('vpms-sum-lines').innerHTML = lines;
			document.getElementById('vpms-sum-total').innerHTML = '<span>Total</span><span>'+money(total)+'</span>';
			sum.hidden = false; form.hidden = false;
		}

		document.getElementById('vpms-prev').addEventListener('click', () => {
			const now = new Date(); now.setDate(1); now.setHours(0,0,0,0);
			const prev = new Date(view.getFullYear(), view.getMonth()-1, 1);
			if (prev >= now){ view = prev; load(); }
		});
		document.getElementById('vpms-next').addEventListener('click', () => {
			const next = new Date(view.getFullYear(), view.getMonth()+1, 1);
			// Μην πλοηγείσαι πέρα από το επιτρεπόμενο booking window.
			if (data.booking_window_end && fmt(next) > data.booking_window_end) return;
			view = next; load();
		});

		document.getElementById('vpms-form').addEventListener('submit', async e => {
			e.preventDefault();
			const btn = document.getElementById('vpms-submit');
			const err = document.getElementById('vpms-error');
			err.hidden = true;
			btn.disabled = true; btn.textContent = 'Please wait…';
			const f = new FormData(e.target);
			try {
				const r = await fetch(API+'/book', {
					method: 'POST',
					headers: {'Content-Type':'application/json','X-WP-Nonce':NONCE},
					body: JSON.stringify({
						checkin, checkout,
						name: f.get('name'), email: f.get('email'),
						phone: f.get('phone'), guests: f.get('guests'),
						privacy_accepted: document.getElementById('vpms-privacy').checked
					})
				});
				const j = await r.json();
				if (j.checkout_url){ window.location.href = j.checkout_url; return; }
				if (r.status === 403){
					// Ληγμένο nonce (π.χ. cached σελίδα): μήνυμα + ΜΙΑ ανανέωση.
					err.textContent = 'Your session expired. Refreshing the page…';
					err.hidden = false;
					try {
						if (!sessionStorage.getItem('vpmsReloaded')){
							sessionStorage.setItem('vpmsReloaded','1');
							setTimeout(() => window.location.reload(), 1200);
						}
					} catch(e) {}
					return;
				}
				err.textContent = j.error || 'Something went wrong. Please try again.';
				err.hidden = false;
				if (r.status === 409) load(); // ανανέωση διαθεσιμότητας
			} catch(ex){
				err.textContent = 'Connection problem. Please try again.';
				err.hidden = false;
			}
			btn.disabled = false; btn.textContent = 'Continue to payment →';
		});

		load();
	})();
	</script>
<?php }

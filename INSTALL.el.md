# Villa PMS v1.2.4 — Οδηγίες Εγκατάστασης & Λειτουργίας

## 1. Εγκατάσταση
1. WordPress admin → Πρόσθετα → Προσθήκη νέου → Μεταφόρτωση προσθέτου
2. Ανέβασε το `villa-pms.zip` και πάτα «Ενεργοποίηση»

## 2. Αναβάθμιση σε 1.2.4
> ⚠️ Πάρε backup πριν από κάθε update (βάση + αρχεία).

1. Απενεργοποίησε την παλιά έκδοση, ανέβασε τη νέα, ενεργοποίησε. **Δεν υπάρχει migration βάσης**
   (κανένα νέο column) — τίποτα δεν χάνεται.
2. Αλλαγή συμπεριφοράς: το `checkout.session.expired` webhook **δεν** ελευθερώνει πλέον αμέσως τις
   ημερομηνίες. Η κράτηση μένει «Pending» και μπλοκάρει μέχρι `stripe_expires_at + ~10'`· το ωριαίο
   cleanup την κάνει «Expired» μετά το grace. Έτσι μια πληρωμή που φτάνει με μικρή καθυστέρηση
   επιβεβαιώνεται κανονικά χωρίς να έχουν χαθεί οι μέρες. Τα Stripe webhook events παραμένουν τα ίδια τρία.
3. Οι αποτυχίες εγγραφής στη βάση (refund flow & iCal sync) καταγράφονται πλέον ρητά ως
   reconciliation / sync errors για admin review, αντί να περνούν σιωπηλά ως επιτυχία.

## 2. Αναβάθμιση σε 1.2.3
> ⚠️ Πάρε backup πριν από κάθε update (βάση + αρχεία).

1. Απενεργοποίησε την παλιά έκδοση, ανέβασε τη νέα, ενεργοποίησε.
2. Migration (αυτόματο, idempotent): νέα columns `refund_status`, `refund_requested_at`,
   `refund_last_error`, `refund_attempts`. Το refund γίνεται πλέον **ξεχωριστό από το booking status**.
3. Παλιές κρατήσεις που ήταν σε κατάσταση «Refund pending» μεταφέρονται αυτόματα σε **Paid** με
   **Refund status: Pending**, ώστε να ΜΗΝ ελευθερωθούν κατά λάθος οι ημερομηνίες. Θα δεις admin
   warning με τα IDs τους — έλεγξε το κάθε refund στο Stripe Dashboard και μετά κάνε «Dismiss».
4. Κανένα booking/setting/Stripe ID/iCal token/email/ημερομηνία δεν χάνεται. Τα Stripe webhook
   events παραμένουν τα ίδια τρία (`checkout.session.completed`, `checkout.session.async_payment_succeeded`,
   `checkout.session.expired`). Το refund finalisation γίνεται αυτόματα μέσω του Stripe API (καμία
   αλλαγή στα webhook events).

## 2. Αναβάθμιση σε 1.2.2
> ⚠️ Πάρε backup πριν από κάθε update (βάση + αρχεία).

1. Απενεργοποίησε την παλιά έκδοση, ανέβασε τη νέα, ενεργοποίησε.
2. Migration (αυτόματο, idempotent): νέα columns `stripe_expires_at`, `confirmation_guest_sent`, `confirmation_owner_sent`, `confirmation_last_error` + νέα status values `payment_conflict`, `refund_pending`. Κανένα υπάρχον booking/setting/iCal token δεν χάνεται.
3. ΣΗΜΑΝΤΙΚΟ: στο Stripe webhook πρέπει να είναι ενεργά ΚΑΙ ΤΑ ΤΡΙΑ events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`.
4. Νέο: το hold των direct κρατήσεων είναι πλέον ~40 λεπτά (Stripe session ~31' + 10' grace). Τα expired records κρατιούνται 7 ημέρες.

## 2β. Αναβάθμιση από 1.2.0 σε 1.2.1
> ⚠️ Πάρε backup πριν από κάθε update (βάση + αρχεία).

1. Απενεργοποίησε την 1.2.0, ανέβασε τη νέα έκδοση, ενεργοποίησε.
2. Δεν υπάρχουν νέα columns — μόνο νέο status `expired` και νέες ρυθμίσεις (συγχωνεύονται αυτόματα). Κανένα υπάρχον booking/setting/token δεν χάνεται. Τα iCal URLs ΔΕΝ αλλάζουν.
3. Νέο στο 1.2.1: τα pending που λήγουν δεν διαγράφονται πια αμέσως — γίνονται «Expired» και καθαρίζονται μετά από 48 ώρες (για καθυστερημένα webhooks/diagnostics).

## 2β. Αναβάθμιση από έκδοση 1.1.0
> ⚠️ **Πάρε backup πριν από κάθε update** — βάση δεδομένων (πίνακες `wp_vpms_bookings`, `wp_vpms_rates`, options) και αρχεία. Οι περισσότεροι hosts έχουν εργαλείο backup· εναλλακτικά ένα plugin όπως UpdraftPlus αρκεί.

1. Απενεργοποίησε (ΜΗΝ διαγράψεις) την παλιά έκδοση.
2. Ανέβασε τη νέα έκδοση (αντικατάσταση φακέλου `villa-pms` ή upload του νέου zip).
3. Ενεργοποίησε. Η μετάβαση σχήματος βάσης (v1.2.0) τρέχει αυτόματα με dbDelta:
   - Προστίθενται columns: `stripe_payment_intent`, `paid_at`, `refund_id`, `refunded_at`, `privacy_consent_at`
   - Προστίθενται indexes: `source`, `stripe_session`
   - **Δεν** διαγράφονται κρατήσεις, **δεν** αλλάζουν booking IDs, **δεν** χάνονται ρυθμίσεις.
4. ΣΗΜΑΝΤΙΚΟ μετά την αναβάθμιση: τα iCal export URLs άλλαξαν (τώρα είναι δύο, με token). Πήγαινε **Villa PMS → Settings → iCal sync** και ενημέρωσε το URL **και στο Airbnb και στο Booking.com**. Το παλιό `/?vpms_ical=export` επιστρέφει πλέον 403.

## 3. Ρυθμίσεις (Villa PMS → Settings)
- **Property name**: το όνομα του καταλύματος (εμφανίζεται σε emails, επιβεβαίωση, Stripe).
- Τιμή/νύχτα, ελάχιστες/μέγιστες νύχτες, μέγιστα άτομα, booking window, νόμισμα (ISO π.χ. EUR/USD/GBP), έξοδα καθαρισμού.

### Stripe
1. Λογαριασμός στο https://stripe.com (προμήθεια ~1.5% + 0.25€ για ευρωπαϊκές κάρτες).
2. Dashboard → Developers → API keys → αντέγραψε **Publishable** και **Secret** key στις ρυθμίσεις.
3. Dashboard → Developers → Webhooks → Add endpoint:
   - URL: `https://ΤΟΣΑΪΤΣΟΥ.gr/wp-json/vpms/v1/stripe-webhook`
   - Events (ΚΑΙ ΤΑ ΤΡΙΑ): `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`
   - Αντέγραψε το **Signing secret** (whsec_...) στις ρυθμίσεις.
4. Οι πληρωμές είναι μόνο άμεσες card-based (μαζί με Apple/Google Pay) — όχι delayed bank/voucher methods.
5. ⚠️ Το Webhook Signing Secret είναι **υποχρεωτικό**: χωρίς αυτό το plugin απορρίπτει όλα τα webhooks (HTTP 503) και εμφανίζει προειδοποίηση στο admin. Καμία κράτηση δεν μαρκάρεται πληρωμένη από μη υπογεγραμμένο event.
5. Ξεκίνα με **Test mode** keys (κάρτα δοκιμής: 4242 4242 4242 4242).
6. Πριν χαρακτηριστεί μια κράτηση πληρωμένη, το plugin επαληθεύει server-side: session ID, booking ID, payment status, **ακριβές ποσό σε cents** και **νόμισμα**. Η ίδια επαλήθευση ισχύει και στο success-page fallback.

### Refunds
Το **refund είναι ξεχωριστό από το booking status**. Η λίστα κρατήσεων έχει δύο στήλες: **Status**
(Paid / Cancelled / Payment conflict / Refunded …) και **Refund** (Pending / Requires action /
Processing / Refunded / Failed / Canceled).

**Full refund via Stripe** επιτρέπεται για κρατήσεις **paid, cancelled ή payment_conflict** με
αποθηκευμένο payment intent → οθόνη επιβεβαίωσης → Stripe Refund API με idempotency key ανά
προσπάθεια. Ελέγχεται το πραγματικό status του refund:
- `succeeded` → booking **Refunded** (οι μέρες ελευθερώνονται).
- `pending/requires_action` → Refund **Pending** — το booking status ΔΕΝ αλλάζει· αν ήταν paid, οι
  μέρες μένουν δεσμευμένες. Η τελική κατάσταση κλείνει αυτόματα κάθε ώρα μέσω του Stripe API.
- `failed/canceled` → καμία αλλαγή στο booking· εμφανίζεται κουμπί **Retry full refund** (νέα
  προσπάθεια με νέο idempotency key).
- άγνωστο/κενό status → ΠΟΤΕ δεν θεωρείται επιτυχία· διατηρείται το refund ID, εμφανίζεται warning
  και ζητείται έλεγχος στο Stripe Dashboard.

Αν το Stripe refund δημιουργηθεί αλλά αποτύχει η τοπική ενημέρωση της βάσης, εμφανίζεται σοβαρό
**reconciliation** μήνυμα (όχι σκέτο «failed») με το refund ID — τα χρήματα έχουν επιστραφεί. Η απλή
**Cancel** ΔΕΝ επιστρέφει χρήματα — είναι ξεχωριστές ενέργειες.

### Συγχρονισμός iCal
Υπάρχουν **δύο ξεχωριστά export feeds**, το καθένα με δικό του μυστικό token:
- **Export URL για Airbnb**: περιέχει direct/manual κρατήσεις, blocks **και** τις κρατήσεις Booking.com.
- **Export URL για Booking.com**: περιέχει direct/manual κρατήσεις, blocks **και** τις κρατήσεις Airbnb.

Έτσι ένα δωμάτιο που κλείνει στο Booking.com μπλοκάρει και το Airbnb (και αντίστροφα), χωρίς κυκλικό συγχρονισμό: τα δικά μας events έχουν UID με πρόθεμα `vpms-` και αγνοούνται όταν επιστρέφουν μέσω import.

**Δώσε το σωστό URL σε κάθε πλατφόρμα** (από Villa PMS → Settings):
- Booking.com: Extranet → Τιμές & Διαθεσιμότητα → Ημερολόγιο → Συγχρονισμός ημερολογίων → Προσθήκη σύνδεσης
- Airbnb: Ημερολόγιο → Διαθεσιμότητα → Σύνδεση ημερολογίων → Εισαγωγή

**Πάρε τα δικά τους ημερολόγια** (Export link) από τα ίδια μενού και βάλ' τα στις ρυθμίσεις (πεδία import).

Μπορείς να ανανεώσεις το token κάθε feed με τα κουμπιά «Regenerate token» (το παλιό URL παύει να ισχύει).

Ο συγχρονισμός τρέχει κάθε 15 λεπτά και χειροκίνητα με «Sync iCal now». Η κατάσταση κάθε feed (τελευταίο επιτυχές sync / σφάλμα / αριθμός events) φαίνεται στη σελίδα Bookings και στο System status.

## 4. Τιμές ανά ημέρα (Villa PMS → Pricing)
Όρισε διαφορετική τιμή για οποιοδήποτε διάστημα. Όπου δεν έχεις ορίσει κάτι, ισχύει η προεπιλεγμένη.

## 5. Εμφάνιση στη σελίδα
Βάλε το shortcode **`[villa_booking]`** σε όποια σελίδα θες (π.χ. «Κρατήσεις»). Ο επισκέπτης πρέπει να αποδεχθεί την Πολιτική Απορρήτου (checkbox) πριν την κράτηση — όρισε σελίδα Privacy Policy στο WordPress (Ρυθμίσεις → Απόρρητο) για να εμφανίζεται ο σύνδεσμος.

## 5β. Email αποστολέα (From)
Στο **Villa PMS → Settings → Email sender (From)** βάλε π.χ. `booking@isleofolive.gr` — όλα τα emails προς guests (επιβεβαίωση, άφιξη, ευχαριστήριο) και οι ειδοποιήσεις ιδιοκτήτη θα φεύγουν από αυτή τη διεύθυνση με όνομα το Property name. Σημαντικό για να μην πηγαίνουν στα spam: η διεύθυνση πρέπει να ανήκει στο domain του site και το domain να έχει σωστό SPF/DKIM (ή χρησιμοποίησε SMTP plugin, π.χ. WP Mail SMTP, με τον mail server του isleofolive.gr).

## 5γ. Caching και σελίδα κράτησης
Η σελίδα με το `[villa_booking]` χρησιμοποιεί per-visitor security nonce και **πρέπει να εξαιρεθεί από full-page caching / CDN HTML caching** (π.χ. στο LiteSpeed/WP Rocket/Cloudflare: exclude τη σελίδα «Κρατήσεις»). Το plugin δηλώνει `DONOTCACHEPAGE` για τα caching plugins που το σέβονται, και αν παρόλα αυτά σερβιριστεί ληγμένο nonce, το frontend κάνει μία αυτόματη ασφαλή ανανέωση.

## 6. Privacy / GDPR
- Η αποδοχή της Πολιτικής Απορρήτου ελέγχεται και server-side και αποθηκεύεται η ημερομηνία της.
- Κουμπί «Erase personal data» ανά κράτηση (ανωνυμοποίηση — μένουν ημερομηνίες/ποσά).
- Ενσωμάτωση στα WordPress Tools → Export/Erase Personal Data (με βάση το email).
- Τα iCal feeds και τα sync logs δεν περιέχουν ποτέ ονόματα, emails, τηλέφωνα ή ποσά.

## Σημαντικές σημειώσεις
- ⚠️ **Το iCal ΔΕΝ είναι real-time Channel Manager.** Συγχρονίζει μόνο διαθεσιμότητα (όχι τιμές) και με καθυστέρηση: 15 λεπτά από το plugin + έως 1-2 ώρες από τις πλατφόρμες (το Airbnb και το Booking.com τραβούν το ημερολόγιο στους δικούς τους χρόνους). Για μία βίλα ο κίνδυνος διπλοκράτησης είναι μικρός αλλά όχι μηδενικός. Αν χρειάζεσαι real-time sync και τιμές, χρειάζεσαι επίσημο Channel Manager (API-based).
- Το WP-Cron τρέχει μόνο όταν υπάρχει επισκεψιμότητα. Για αξιόπιστο συγχρονισμό όρισε **πραγματικό server cron** στο hosting και απενεργοποίησε το WP-Cron:
  1. Στο `wp-config.php`: `define( 'DISABLE_WP_CRON', true );`
  2. Στο hosting (cPanel → Cron Jobs): `*/15 * * * * wget -q -O - https://ΤΟΣΑΪΤΣΟΥ.gr/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
- Οι direct κρατήσεις κρατούν τις ημερομηνίες για **~40 λεπτά** (Stripe Checkout session ~31' + 10' grace για καθυστερημένα webhooks). Αν αποτύχει η δημιουργία ή η αποθήκευση του Stripe Checkout session, το session λήγει server-side και το pending διαγράφεται — οι μέρες ελευθερώνονται αμέσως. Πληρωμή που φτάσει σε expired κράτηση επιβεβαιώνεται ΜΟΝΟ αν οι μέρες είναι ακόμη ελεύθερες, αλλιώς μαρκάρεται «Payment conflict» για refund.
- Το δημόσιο booking endpoint έχει rate limiting (10 προσπάθειες / 15 λεπτά ανά επισκέπτη, χωρίς αποθήκευση IP σε αναγνώσιμη μορφή).

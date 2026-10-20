# Villa PMS — Changelog

## 1.2.4 (2026-07-14) — Webhook grace & DB-write hardening

Στοχευμένες διορθώσεις. Καμία νέα λειτουργία, καμία αλλαγή στο frontend design,
κανένα νέο database column.

### includes/stripe.php
- **`checkout.session.expired`**: ΔΕΝ αλλάζει πλέον αμέσως την pending κράτηση σε `expired`
  (αυτό ακύρωνε το grace period). Η κράτηση παραμένει **pending** και μπλοκάρει τις ημερομηνίες
  μέχρι `stripe_expires_at + 10'`· το hourly cleanup κάνει τη μετάβαση σε `expired` μετά το
  grace. Έτσι ένα καθυστερημένο `checkout.session.completed` μπορεί ακόμη να κάνει την κράτηση
  **paid** χωρίς να έχουν ελευθερωθεί οι μέρες.
- Επαλήθευση expired webhook: απαιτεί booking ID, ύπαρξη κράτησης, και **το session ID του event
  να ταιριάζει με το αποθηκευμένο `stripe_session`** (`hash_equals`). Άσχετο/παλιό session δεν
  αλλάζει τίποτα. Αν η κράτηση είναι ήδη `paid/refunded/cancelled/payment_conflict` → **HTTP 200**
  χωρίς αλλαγή. Πάντα idempotent 200 + diagnostic στο webhook log.
- **Refund flow — έλεγχος όλων των DB updates**: network error, Stripe API error, failed/canceled,
  unknown status, και το reconciliation (succeeded / failed / canceled). Αν ένα update επιστρέψει
  `false`, καταγράφεται reconciliation error, ΔΕΝ δηλώνεται επιτυχία και δεν επιτρέπεται
  ανεξέλεγκτο δεύτερο refund. Ειδικά, αν μετά από **network error** αποτύχει η αλλαγή
  `processing → failed`, εμφανίζεται **σοβαρό reconciliation warning** (η κράτηση μπορεί να μείνει
  κλειδωμένη σε `processing`).

### includes/ical.php
- `vpms_apply_external_events()`: το `updated`/`deleted` count αυξάνεται **μόνο** αν το DB
  update/delete πέτυχε· αποτυχία insert/update/delete καταγράφεται ως **sync error** (με σύντομη,
  χωρίς-PII περιγραφή) και το πραγματικό OTA event παραμένει καταγεγραμμένο για admin review.
  Αν υπάρξει οποιοδήποτε database error, το sync **δεν** δηλώνεται ως πλήρως επιτυχημένο (δεν
  ενημερώνεται το `last_success`, μπαίνει `last_error`).

### villa-pms.php
- Έκδοση 1.2.4. Κανένα νέο column. Το activation/upgrade παραμένει idempotent (dbDelta + η
  υπάρχουσα migration `refund_pending`). Καμία απώλεια bookings/Stripe IDs/refund states/iCal
  tokens/settings/emails/logs.

### Database migrations (1.2.3 → 1.2.4)
- **Καμία**. Δεν προστίθενται/αφαιρούνται columns. Μόνο αλλαγές λογικής.


## 1.2.3 (2026-07-14) — Refund flow separation

Το refund γίνεται πλέον **ξεχωριστό από το booking status**. Το booking status
(paid/cancelled/payment_conflict) καθορίζει τη διαθεσιμότητα των ημερομηνιών· ένα
νέο `refund_status` καθορίζει την πορεία της επιστροφής χρημάτων. Καμία αλλαγή στο
frontend design, καμία αφαίρεση λειτουργίας.

### villa-pms.php
- Έκδοση 1.2.3. Νέα columns (dbDelta, χωρίς απώλεια δεδομένων): `refund_status VARCHAR(30)`,
  `refund_requested_at DATETIME`, `refund_last_error VARCHAR(190)`, `refund_attempts SMALLINT UNSIGNED`,
  + index `refund_status`.
- Idempotent migration: παλιές εγγραφές με `status='refund_pending'` → `status='paid'` +
  `refund_status='pending'` ώστε να ΜΗΝ ελευθερωθούν κατά λάθος οι ημερομηνίες. Καταγράφονται
  τα IDs (`vpms_refund_migrated`) για admin warning & έλεγχο.

### includes/stripe.php
- Το `refund_pending` ΔΕΝ χρησιμοποιείται πλέον ως booking status.
- `vpms_stripe_refund_booking()` ξαναγράφτηκε:
  - Atomic «claim» (conditional UPDATE → `refund_status='processing'`) ώστε δύο ταυτόχρονα κλικ
    να μην ξεκινήσουν δεύτερο refund.
  - Idempotency key ανά προσπάθεια `vpms-refund-{id}-{attempt_number}`. Νέα ρητή προσπάθεια μετά
    από **failed/canceled** → νέο attempt number (atomic αύξηση στη βάση). Επανάληψη μετά από
    **σφάλμα δικτύου** → ίδιο key (Stripe dedupe, κανένα διπλό refund).
  - Χειρισμός Stripe status: **μόνο** `succeeded` → booking `refunded` (+refund_id, refunded_at,
    καθαρισμός error). `pending`/`requires_action` → booking αμετάβλητο, refund σε εξέλιξη.
    `failed`/`canceled` → booking αμετάβλητο, retry επιτρεπτό. **Άγνωστο/κενό** → ΠΟΤΕ succeeded:
    διατήρηση refund_id, reconciliation error, admin warning. Καμία default-branch που θεωρεί
    άγνωστο refund ως επιτυχία.
  - Refund επιτρέπεται για `paid`, `cancelled` **και** `payment_conflict` (με έγκυρο payment intent).
  - Έλεγχος αποτελέσματος κάθε DB update: αν το Stripe refund δημιουργήθηκε αλλά απέτυχε το τοπικό
    update → σοβαρό reconciliation warning, διατήρηση refund ID, καμία νέα refund ενέργεια. Τα
    reconciliation logs cap στα 50.
- `vpms_reconcile_pending_refunds()` δουλεύει τώρα βάσει `refund_status IN ('pending','requires_action')`
  ανεξάρτητα από το booking status· `succeeded`→refunded, `failed/canceled`→refund_status + «καίει»
  attempt (retry με νέο key), booking status αμετάβλητο.
- Webhook payment_conflict idempotent: επαναλαμβανόμενη verified πληρωμή σε ήδη `payment_conflict`
  κράτηση → HTTP 200, κανένα διπλό conflict log. Αποτυχία DB στη δημιουργία του `payment_conflict`
  → non-2xx ώστε η Stripe να ξαναστείλει.

### includes/ical.php
- Όταν αλλάζουν οι ημερομηνίες ενός υπάρχοντος external event: re-check overlap εξαιρώντας το ίδιο
  external booking ID, ενημέρωση του event (η OTA κράτηση είναι πραγματική) και conflict warning
  μόνο αν υπάρχει overlap. Δεν ξανακαταγράφεται το ίδιο conflict σε sync χωρίς αλλαγή ημερομηνιών.

### includes/admin.php
- Λίστα κρατήσεων: ξεχωριστές στήλες **Status** και **Refund**. Κουμπί refund κρύβεται σε
  pending/requires_action/processing/succeeded· εμφανίζεται **Retry full refund** σε failed/canceled·
  κανένα δεύτερο refund σε succeeded. Οθόνη επιβεβαίωσης/retry με το προηγούμενο σφάλμα.
- Admin warning για τις migrated εγγραφές (1.2.2→1.2.3) + κουμπί dismiss.

### Database migrations (1.2.2 → 1.2.3)
1. +columns: `refund_status`, `refund_requested_at`, `refund_last_error`, `refund_attempts` (+index).
2. Δεδομένα: `status='refund_pending'` → `status='paid'` + `refund_status='pending'` (idempotent).
3. Νέο option `vpms_refund_migrated` (capped 50). Καμία απώλεια bookings/settings/Stripe IDs/iCal
   tokens/emails/ημερομηνιών/υπαρχόντων statuses.


## 1.2.2 (2026-07-13) — Audit fixes (2ος γύρος)

### villa-pms.php
- Έκδοση 1.2.2. Νέα columns: `stripe_expires_at`, `confirmation_guest_sent`, `confirmation_owner_sent`, `confirmation_last_error` (dbDelta, χωρίς απώλεια δεδομένων).
- Cleanup: pending→expired ΜΟΝΟ μετά το Stripe expiration (+10' grace, fallback created_at+41')· expired κρατιούνται 7 ημέρες (όχι 48h)· payment_conflict/refund_pending δεν διαγράφονται ποτέ αυτόματα. Ωριαία retry αποτυχημένων confirmations + refund reconciliation.

### includes/stripe.php
- Checkout Session: `payment_method_types=card` (μόνο άμεσες card πληρωμές, incl. Apple/Google Pay)· `expires_at` ~31'.
- Το ΠΡΑΓΜΑΤΙΚΟ `expires_at` του Stripe response αποθηκεύεται στο `stripe_expires_at` μαζί με το session ID, με έλεγχο επιτυχίας· σε αποτυχία αποθήκευσης το ορφανό session λήγει server-side (POST /expire) και επιστρέφεται WP_Error → το pending διαγράφεται.
- `vpms_mark_paid_verified()`: session verification ΠΡΙΝ από το idempotent 'already' — άσχετο session δεν παίρνει ποτέ επιτυχή απάντηση.
- Expired→paid ΜΟΝΟ μέσω `vpms_recover_expired_payment()`: global booking lock → availability check (εξαιρώντας την ίδια κράτηση) → αν ελεύθερες: paid· αν όχι: status `payment_conflict`, αποθήκευση intent, ΚΑΝΕΝΑ email, σοβαρό admin notice, καταγραφή (χωρίς PII).
- Refunds: ελέγχεται το `$data['status']` — succeeded→refunded· pending/requires_action→`refund_pending` (όχι «completed», μέρες δεσμευμένες)· failed/canceled→καμία αλλαγή + diagnostic. Νέο ωριαίο reconciliation `vpms_reconcile_pending_refunds()` μέσω Stripe Refund API.

### includes/db.php
- `vpms_transition_to_paid()`: ΜΟΝΟ από pending (το expired πάει μέσω reconciliation).
- Pending hold βασίζεται στο `stripe_expires_at`+10' (όχι μόνο created_at). Νέο `vpms_overlapping_active_ids()`.
- Confirmation emails με αποθηκευμένο delivery state ανά παραλήπτη: αποτυχία wp_mail → καταγραφή σφάλματος, retry από cron/admin, ποτέ διπλή αποστολή μετά από επιτυχία. Νέο `vpms_retry_failed_confirmations()`.

### includes/ical.php
- Το apply (additions/updates/deletions) εκτελείται κάτω από το global booking lock (finally release) — όχι race με direct/manual bookings.
- Imported OTA event που επικαλύπτει ενεργή κράτηση: αποθηκεύεται ΚΑΙ καταγράφεται booking conflict με σοβαρό admin notice (source, dates, booking IDs — χωρίς PII).

### includes/admin.php
- Admin notices για payment conflicts & calendar conflicts + κουμπί «Dismiss conflict notices».
- Refund flow: χειρισμός «Refund pending» (όχι ψεύτικο completed)· κουμπί όχι σε refund_pending.
- Νέο «Resend confirmation» (μόνο όταν κάποιο email απέτυχε). Labels: Payment conflict, Refund pending.
- Stripe help + System status: απαιτούμενα και τα 3 webhook events.

### includes/frontend.php
- Cancel message: ακριβής χρόνος hold (~40 λεπτά). JS: όριο `max_nights` κατά την επιλογή checkout (το server-side validation παραμένει).

### README.md
- Webhook events (3), νέο hold (~40'), refund statuses, card-only πληρωμές, οδηγίες 1.2.2.

### Database migrations (1.2.1 → 1.2.2)
1. +columns: `stripe_expires_at DATETIME`, `confirmation_guest_sent TINYINT(1)`, `confirmation_owner_sent TINYINT(1)`, `confirmation_last_error VARCHAR(190)`.
2. Νέα status values: `payment_conflict`, `refund_pending` (χωράνε στο υπάρχον VARCHAR(20)).
3. Νέα options: `vpms_payment_conflicts`, `vpms_booking_conflicts` (capped 20). Idempotent, καμία απώλεια bookings/settings/tokens.

## 1.2.1 (2026-07-13) — Audit fixes

### villa-pms.php
- Έκδοση 1.2.1. Νέο setting `from_email` (email αποστολέα).
- Cleanup lifecycle: pending >30' → status `expired` (δεν μπλοκάρει μέρες, ΔΕΝ διαγράφεται)· expired >48h → διαγραφή. Καθυστερημένα webhooks/diagnostics δεν χάνονται.

### includes/stripe.php
- Checkout Session με `expires_at` (30' + 60s clock-skew margin) — δεν πληρώνεται session μετά τη λήξη του hold.
- Webhook: σωστά HTTP status codes. 200 ΜΟΝΟ όταν το event καταχωρήθηκε ή ήταν idempotent· 400 για missing booking reference / failed verification· 404 για ανύπαρκτη κράτηση· 500 για αποτυχία βάσης· 200 χωρίς επεξεργασία για άσχετα events.
- Νέα events: `checkout.session.async_payment_succeeded` (async πληρωμές — επιβεβαίωση ΜΟΝΟ όταν payment_status=paid) και `checkout.session.expired` (η κράτηση γίνεται expired και ελευθερώνει μέρες).
- Ασφαλές webhook log (event type, booking ID, αποτέλεσμα — ποτέ payload/PII/secrets), capped 20 εγγραφές.
- Refund reconciliation: αν το Stripe refund πετύχει αλλά αποτύχει το τοπικό update, εμφανίζεται σοβαρό reconciliation μήνυμα με το refund ID (όχι σκέτο "failed") και καταγράφεται.

### includes/db.php
- `vpms_transition_to_paid()`: status + paid_at + stripe_payment_intent σε ΕΝΑ atomic UPDATE (δεν υπάρχει paid χωρίς intent). Δέχεται pending ή expired (καθυστερημένο webhook έγκυρης πληρωμής τιμάται).
- Νέο `vpms_mail()`: όλα τα emails του plugin με προσαρμοσμένο From (setting) χωρίς επηρεασμό άλλων WordPress emails.

### includes/ical.php
- Database-level named lock ανά source (`vpms_ical_<source>_<site>`): ταυτόχρονο cron + «Sync now» → το δεύτερο τερματίζει με log, καμία αλλαγή. Release σε finally.
- Parser: `STATUS:CANCELLED` και `METHOD:CANCEL`. Cancelled events δεν δημιουργούνται, υπάρχοντα με ίδιο UID διαγράφονται, δεν εξάγονται.
- Ισχυρότερο VCALENDAR validation πριν από deletions: ακριβώς ένα πλήρες block, VERSION, ισοσκελισμένα VEVENT, έλεγχος truncation (τέλος εγγράφου + response-size limit).

### includes/rest.php
- Availability response: `booking_window_end` + `max_nights`· μέρες εκτός booking window επιστρέφονται μη διαθέσιμες.

### includes/frontend.php
- JS: Next απενεργοποιείται πέρα από το booking window· σωστό error handling στο load() (δεν γίνεται render με error response)· Intl.NumberFormat fallback για μη υποστηριζόμενα currency codes· σε 403 (ληγμένο nonce/cache) σαφές μήνυμα + μία ασφαλής ανανέωση με guard.
- `DONOTCACHEPAGE` στη σελίδα του shortcode.
- Το success fallback χρησιμοποιεί τους νέους κωδικούς αποτελέσματος.

### includes/emails.php
- Thank-you email: αποστολή ΜΟΝΟ από την επόμενη ημέρα του checkout (όχι την ίδια μέρα).
- Όλα τα emails μέσω `vpms_mail()` (custom From).

### includes/admin.php
- Refund button + confirmation και για `cancelled` κρατήσεις με payment intent (χωρίς υπάρχον refund).
- Reconciliation errors εμφανίζονται ως «RECONCILIATION REQUIRED», όχι «Refund failed».
- Νέο πεδίο ρύθμισης «Email sender (From)». Label για status `expired`.

### Database migrations (1.2.0 → 1.2.1)
- Κανένα νέο column. Νέο status value `expired` (χωράει στο υπάρχον VARCHAR(20)). Νέο settings key `from_email` και options `vpms_webhook_log`, `vpms_refund_reconciliation` (capped). Idempotent, καμία απώλεια δεδομένων.

## 1.2.0 (2026-07-13) — Production hardening

### villa-pms.php
- Έκδοση 1.1.0 → 1.2.0, δήλωση `Requires PHP: 8.1`.
- Νέα columns στη βάση μέσω dbDelta (χωρίς απώλεια δεδομένων): `stripe_payment_intent`, `paid_at`, `refund_id`, `refunded_at`, `privacy_consent_at`. Νέα indexes: `source`, `stripe_session`.
- Νέες προεπιλογές ρυθμίσεων: `property_name`, `max_nights`, `advance_months`. Η ενεργοποίηση/αναβάθμιση συγχωνεύει νέα κλειδιά χωρίς να πειράζει τις υπάρχουσες τιμές.
- Δημιουργία κρυπτογραφικών tokens (ένα ανά iCal feed) κατά την ενεργοποίηση/αναβάθμιση.
- Νέες helper functions: `vpms_generate_token()`, `vpms_default_settings()`, `vpms_property_name()`.

### includes/db.php
- `vpms_valid_date()`: αυστηρό validation με `checkdate()` — απορρίπτει 2026-02-31 κ.λπ.
- Database-level locking με MySQL `GET_LOCK` (`vpms_acquire_booking_lock` / `vpms_release_booking_lock`).
- `vpms_create_booking_atomic()`: lock → re-check διαθεσιμότητας → server-side ποσό → insert → release (πάντα, ακόμα και σε exception).
- `vpms_transition_to_paid()`: ατομικό `UPDATE ... WHERE status='pending'` — βάση των idempotent emails.
- `vpms_delete_booking()`, `vpms_format_money()` (ISO currency, όχι hardcoded €).
- Emails επιβεβαίωσης: δυναμικό property name, δυναμικές ώρες check-in/out, δυναμικό νόμισμα.

### includes/ical.php (σε μεγάλο βαθμό ξαναγραμμένο)
- Δύο ξεχωριστά export feeds: `?vpms_ical=export&channel=airbnb|booking&token=...`.
- Κάθε feed περιλαμβάνει paid direct/manual, blocks και τις external κρατήσεις του ΑΛΛΟΥ καναλιού· εξαιρεί events του ίδιου καναλιού, pending, cancelled, refunded.
- Token validation με `hash_equals`, 403 χωρίς έγκυρο token, κουμπιά regenerate στο admin.
- RFC 5545: PRODID, CALSCALE, METHOD:PUBLISH, σταθερό UID (`vpms-{id}@host`), DTSTAMP, DTSTART/DTEND;VALUE=DATE (DTEND=checkout, exclusive), SUMMARY χωρίς προσωπικά δεδομένα, STATUS:CONFIRMED, TRANSP:OPAQUE, escaping + line folding 75 octets.
- Import: `wp_safe_remote_get` (SSL verification, προστασία SSRF), URL validation, timeout 20s, όριο 2 MB, έλεγχος έγκυρου VCALENDAR. Blank/malformed/HTTP error → καμία αλλαγή στη βάση. Διαγραφή ακυρωμένων external μόνο μετά από επιτυχές, έγκυρο sync του συγκεκριμένου source.
- Parser: folded lines, VALUE=DATE, DATE-TIME (κρατά το ημερολογιακό μέρος), escaped summaries, UIDs, DTEND προαιρετικό.
- Loop protection: events με UID prefix `vpms-` αγνοούνται στο import (όχι duplicates/loops).
- Sync logging ανά source: last attempt/success/error, HTTP status, events found, added/updated/deleted. Ιστορικό capped στα 20 entries.

### includes/stripe.php
- Webhook: χωρίς ρυθμισμένο signing secret απορρίπτονται ΟΛΑ τα events (503) + admin notice. Signature verification πριν από κάθε επεξεργασία (hash_equals, ανοχή 5').
- Κοινή function `vpms_verify_session_pays_booking()` (webhook + success fallback): session ID = αποθηκευμένο, booking ID στα metadata/client_reference_id, payment_status=paid, amount_total = ποσό σε cents, currency = νόμισμα κράτησης.
- `vpms_mark_paid_verified()`: ατομική μετάβαση pending→paid — ένα μόνο confirmation email ακόμη και με διπλά webhooks/ταυτόχρονο redirect. Αποθήκευση payment intent + paid_at.
- Refunds: `vpms_stripe_refund_booking()` — μόνο με payment intent, Idempotency-Key ανά κράτηση, αποθήκευση refund ID + ημερομηνίας, status `refunded`, όχι δεύτερο refund, όχι ψεύτικη επιτυχία σε σφάλμα του Stripe.

### includes/rest.php
- Rate limiting στο /book: 10 προσπάθειες/15' ανά επισκέπτη, keyed hash IP (HMAC με salt) σε transient — δεν αποθηκεύεται IP.
- Αυστηρό validation: ημερομηνίες (checkdate), booking window (default 24 μήνες), μέγιστες νύχτες (default 60), guests 1..max, μήκη name/phone, server-side έλεγχος αποδοχής Privacy Policy.
- Δημιουργία κράτησης μέσω `vpms_create_booking_atomic()` — ταυτόχρονα requests: μόνο ένα περνά, το άλλο 409.
- Αποτυχία δημιουργίας Stripe session → άμεση διαγραφή του pending booking (502, οι μέρες ελεύθερες).

### includes/frontend.php
- Αφαίρεση hardcoded «Isle of Olive» → setting Property name.
- Αφαίρεση hardcoded € → Intl.NumberFormat με ISO code από το backend (ημερολόγιο, summary, total).
- Success page: πλήρης server-side επαλήθευση (ίδια με webhook) πριν εμφανιστεί επιβεβαίωση.
- Cancel μήνυμα: ακριβές («οι μέρες μπορεί να μείνουν δεσμευμένες έως 30'»).
- Checkbox αποδοχής Privacy Policy (required) με link στη σελίδα Privacy του WordPress.

### includes/admin.php
- Stripe secrets: δεν εμφανίζονται ποτέ ξανά στο HTML — «Configured/Missing», κενό πεδίο = διατήρηση, ρητό checkbox διαγραφής.
- Δύο iCal export URLs με σαφείς οδηγίες ανά πλατφόρμα + κουμπιά regenerate token (nonce).
- Πίνακας κατάστασης sync ανά feed (Bookings) + πλήρες «System status» (Settings).
- Refund flow με οθόνη επιβεβαίωσης· Cancel ξεχωριστό από refund (χωρίς αυτόματο refund).
- Κουμπί «Erase personal data» (ανωνυμοποίηση) ανά κράτηση.
- Manual bookings & blocks: atomic overlap validation (lock) — αδύνατο το overlap.
- Admin notice όταν λείπει το webhook signing secret ενώ το Stripe είναι ενεργό.
- Capability checks (`manage_options`) σε όλες τις ενέργειες + υπάρχοντα nonces.

### includes/privacy.php (νέο αρχείο)
- `vpms_anonymize_booking()` + ενσωμάτωση στα WordPress personal data exporter/eraser hooks με βάση το email.

### includes/emails.php
- Δυναμικό property name (setting) αντί για get_bloginfo.

### includes/stats.php
- Νόμισμα: ISO code από το setting αντί για hardcoded €.

### README.md
- Πλήρως ενημερωμένο: Stripe/webhook setup, iCal import/export ανά πλατφόρμα, πραγματικό server cron, εξήγηση ότι το iCal δεν είναι real-time Channel Manager, οδηγίες backup & αναβάθμισης.

## Database migrations (1.1.0 → 1.2.0)
1. `wp_vpms_bookings`: +columns `stripe_payment_intent VARCHAR(190)`, `paid_at DATETIME`, `refund_id VARCHAR(190)`, `refunded_at DATETIME`, `privacy_consent_at DATETIME`.
2. `wp_vpms_bookings`: +indexes `source`, `stripe_session`.
3. Options: +`vpms_ical_token_airbnb`, +`vpms_ical_token_booking` (auto-generated), +`vpms_sync_status`, +`vpms_sync_history` (capped), settings merge (`property_name`, `max_nights`, `advance_months`).
4. Idempotent: μπορεί να τρέξει πολλές φορές (dbDelta + add-if-missing tokens), δεν αγγίζει υπάρχοντα δεδομένα/IDs/ρυθμίσεις.

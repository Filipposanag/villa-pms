# Villa PMS 1.2.4 — TEST REPORT

Περιβάλλον ελέγχου: Linux sandbox με **Node.js v22** αλλά **ΧΩΡΙΣ PHP interpreter, WordPress,
MySQL, Stripe test account ή OTA test environment**. Ό,τι δεν εκτελέστηκε πραγματικά σημειώνεται
ρητά ως **Not executed** — ΠΟΤΕ ως "Passed".

## 1. Πραγματικά εκτελεσμένα (σε αυτό το περιβάλλον)

| Test | Μέθοδος | Αποτέλεσμα |
|---|---|---|
| `node --check` στο frontend JavaScript (εξαγωγή από το shortcode) | Node.js v22, πραγματική εκτέλεση | ✅ PASS |
| Δομικός έλεγχος 10/10 PHP αρχείων: PHP/HTML mode-switching (`<?php`/`?>`), ισοσκελισμένα `() [] {}`, κλειστά strings/heredocs/`/* */`, PHP open tag | Custom static checker (Python), πραγματική εκτέλεση | ✅ PASS (10/10) |
| Λογικό μοντέλο 1.2.4 — 18 assertions για τα νέα σενάρια (expired webhook / grace / refund DB failures / iCal DB failures) | Python, πραγματική εκτέλεση | ✅ PASS (18/18) |

> ⚠️ Ο δομικός PHP checker ΔΕΝ είναι ισοδύναμος με `php -l`. Ελέγχει ισοζύγιο συμβόλων και
> τερματισμό strings/comments, ΟΧΙ semantics, τύπους ή WordPress runtime.

### Λογικό μοντέλο — αντιστοίχιση στα ζητούμενα tests (18/18)
- **expired webhook πριν το grace** → booking παραμένει `pending` και **occupied** ✅
- **cleanup μετά το grace** → booking γίνεται `expired` (πριν το grace: παραμένει pending) ✅
- **completed webhook μετά από expired event αλλά μέσα στο grace** → booking γίνεται `paid`
  (γιατί το expired webhook ΔΕΝ άλλαξε το status) ✅
- **expired webhook με λάθος Stripe session** → καμία αλλαγή (session_mismatch, HTTP 200) ✅
- **expired webhook σε ήδη paid/refunded/cancelled/payment_conflict** → HTTP 200, καμία αλλαγή ✅
- **refund DB update failure** (succeeded/network/failed) → reconciliation warning, κράτηση μένει
  `processing`, νέο refund **blocked** ✅
- **iCal update/delete DB failure** → το count ΔΕΝ αυξάνεται, `db_errors` καταγράφεται, το sync
  ΔΕΝ δηλώνεται πλήρως επιτυχημένο (σωστά counts) ✅

## 2. Επαληθευμένα με code review (ΟΧΙ runtime)

- `checkout.session.expired`: guard σειρά booking-id → ύπαρξη → `hash_equals` session → non-pending
  short-circuit → pending «noted» χωρίς αλλαγή status. Πάντα HTTP 200 idempotent.
- Το pending hold (`stripe_expires_at` + 10' grace) παραμένει η μόνη πηγή αλήθειας για την
  απελευθέρωση ημερομηνιών (μέσω του hourly `vpms_cleanup`).
- Κάθε DB update στο refund flow ελέγχεται (`false === $result`) με reconciliation logging (cap 50).
- iCal: `added/updated/deleted` αυξάνονται μόνο σε επιτυχία· `db_errors` + `db_error_notes`
  σταματούν το `last_success` και γράφουν `last_error`.

## 3. Integration tests — **Not executed**

Χωρίς διαθέσιμο περιβάλλον, ΔΕΝ εκτελέστηκαν και ΔΕΝ δηλώνονται "Passed":

- **`php -l villa-pms.php` / `php -l includes/*.php`** σε πραγματικό PHP interpreter — **Not executed**
  (δεν υπάρχει PHP στο sandbox· ο δομικός έλεγχος ΔΕΝ είναι ισοδύναμος).
- Live Stripe: `checkout.session.expired` + καθυστερημένο `checkout.session.completed` εντός grace,
  refunds με πραγματικά statuses, webhook delivery/retries — **Not executed**.
- Πραγματικά ταυτόχρονα HTTP requests / cron races — **Not executed**.
- dbDelta σε πραγματική MySQL & πραγματικές DB-write αποτυχίες (refund & iCal) — **Not executed**.
- Πραγματικό iCal fetch/update/delete από Airbnb/Booking.com — **Not executed**.

## 4. Πριν το production (checklist)

1. `for f in villa-pms.php includes/*.php; do php -l "$f"; done` στον server (πρέπει: no syntax errors).
2. Staging + Stripe Test mode: άφησε ένα checkout session να λήξει, στείλε `checkout.session.expired` →
   η κράτηση πρέπει να μείνει **Pending** (occupied). Μετά στείλε καθυστερημένο
   `checkout.session.completed` εντός ~40' → πρέπει να γίνει **Paid**.
3. Άφησε pending να περάσει το grace → το ωριαίο cleanup πρέπει να την κάνει **Expired**.
4. Στείλε `checkout.session.expired` με λάθος/παλιό session ή σε ήδη paid κράτηση → **καμία αλλαγή, 200**.
5. Δοκίμασε refund· προσομοίωσε DB failure (π.χ. read-only) → πρέπει να εμφανιστεί reconciliation
   warning και να ΜΗΝ επιτρέπεται δεύτερο refund.
6. Προκάλεσε iCal DB error → το feed status πρέπει να δείξει **error** (όχι πλήρη επιτυχία).

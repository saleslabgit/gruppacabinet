# WEBPAY

Local implementation of TASK-2026-09-21-12. Real Sandbox acceptance remains
NOT VERIFIED. No credentials or external payments are required by local tests.
The architecture correction in the task is authoritative: a completely lost
notify cannot be reconstructed safely by the documented get_transaction.

## Provider contract checked 2026-09-22

Official sources:

- [Environment](https://docs.webpay.by/generalInfo/devEnvironment/)
- [Form fields](https://docs.webpay.by/paymentIntegration/cardIntegration/paymentFormFields/)
- [Order signature](https://docs.webpay.by/paymentIntegration/cardIntegration/orderSignature/)
- [Standard notify](https://docs.webpay.by/paymentIntegration/cardIntegration/paymentNotification/)
- [Transaction verification](https://docs.webpay.by/paymentIntegration/cardIntegration/paymentVerification/)
- [Transaction types](https://docs.webpay.by/paymentIntegration/cardIntegration/transactionTypes/)
- [API prerequisites](https://docs.webpay.by/API/operations/)

Environment selection is fixed in `App\Payments\Webpay`; no request-supplied
provider URLs or TLS overrides are accepted.

| WEBPAY_ENV | HTML form action | XML API endpoint | wsb_test |
|---|---|---|---|
| sandbox | https://securesandbox.webpay.by/ | https://sandbox.webpay.by | 1 |
| production | https://payment.webpay.by/ | https://billing.webpay.by | 0 |

Configure `WEBPAY_ENV`, `WEBPAY_STORE_ID`, `WEBPAY_SECRET_KEY`,
`WEBPAY_API_USERNAME`, `WEBPAY_API_PASSWORD`; optional `WEBPAY_HTTP_TIMEOUT`
defaults to 15 seconds (bounded to 1–30 in transport; connect timeout 5).
Form/attempt creation requires the store and secret; API checks additionally
require API credentials. Free operations do not require any provider config.
The existing typed placement/extension price settings must be positive.
Unset or zero paid-operation prices fail closed before group/payment creation.
There are no default business prices.

## Form

The existing placement Blade view first submits an owner-only CSRF POST
`/payments/{payment}/start`; this starts the same attempt and renders a standard
HTML POST form to WEBPAY with a “Перейти в WEBPAY” button. No automatic external
request is made by application code. Refresh does not create another attempt.

Fields: `*scart` (empty), `wsb_version=2`, `wsb_storeid`, opaque `wsb_order_num`
(`GP-` plus 32 random hexadecimal characters), `wsb_test`, `wsb_currency_id=BYN`,
random `wsb_seed`, one invoice line (name, quantity 1, price), `wsb_total`,
return/cancel/notify URLs, `wsb_signature`. Personal/customer/card fields are
omitted. Invoice price and total use exactly the same decimal string.
Amounts are integer minor units; formatting and parsing never use float.

For v2, the lowercase hexadecimal SHA1 input is the concatenation, without
separators, of seed, store ID, merchant order, test flag, currency, total and
SecretKey, in that order. Only the derived signature goes into the browser.
The official published form example is used as a known-vector test.

## Notify and trusted binding

`POST <APP_URL>/webpay/notify` is outside the web/session/auth/CSRF middleware
group. Global string trimming and empty-to-null conversion are bypassed for
this endpoint so signed bytes are preserved. The deployment base path matters:
with `APP_URL=https://host/cabinet`, the notify URL is
`https://host/cabinet/webpay/notify`.

The standard notify signature is lowercase hexadecimal MD5 over the ordered
concatenation: batch_timestamp, currency_id, amount, payment_method, order_id,
site_order_id, transaction_id, payment_type, rrn, SecretKey. Comparisons use
`hash_equals`. Card-inclusive notification mode is rejected; do not enable it
for this MVP store. No received signature/card/raw body is persisted.

Signed `site_order_id + transaction_id` supplies the trusted binding. Before
mutation the central service validates local order, exact amount, BYN, cc,
transaction ID, provider order consistency and supported type. A transaction
already attached to a different payment cannot be reused; the DB unique key
also protects concurrent cross-payment attempts.

Each request reaching the controller gets a technical journal row when the DB
is available. Whitelisted fields are format/length checked, including on
invalid requests. RRN, signatures, credentials, arbitrary extra fields and card
fields are omitted. Logs contain only a local payment ID and fixed technical
code. Admin pages reapply the whitelist when rendering stored provider data.
Accepted/duplicate processing returns HTTP 200 `OK`; rejected input returns
400 `Rejected`; a transient processing failure returns 503 `Unavailable`.

## XML API and browser return

`get_transaction` is an HTTPS form POST with `*API` empty and
`API_XML_REQUEST` containing a UTF-8 XML `wsb_api_request`: command,
authorization username and MD5(password), fields/transaction_id. XML text is
escaped. TLS verification stays enabled and redirects are disabled. Network
exceptions are replaced with fixed technical errors without previous exception.

Responses are limited to 64 KiB before XML parsing. DTD/entity declarations,
malformed XML, missing or duplicate relevant elements are rejected. Parsing
uses LIBXML_NONET without entity substitution. Response MD5 covers, in order:
transaction_id, batch_timestamp, currency_id, amount, payment_method,
payment_type, WEBPAY order_id, rrn, SecretKey. A requested/returned transaction
mismatch is rejected. Only a sanitized field set can reach provider_response.

The documented response has no merchant site_order_id. A valid signature and
matching amount alone do not establish local identity. The central service
requires a previously verified binding and matching transaction/provider order
for every API-driven change. Browser `wsb_tid` and order parameters are ignored
for binding, storage and API selection. Return/cancel routes require ownership.
They render local state and may run a due, trusted-bound recovery check; merely
opening either route never declares a financial outcome.

## State mapping and effects

| Provider type | Pending local payment |
|---|---|
| 1 Completed / 4 Authorized | succeeded, after all consistency/binding checks |
| 2 Declined / 8 Failed | failed |
| 7 Voided | cancelled (only while pending) |
| Other types, including partial/full refunds | unchanged; unsupported/manual review |

WEBPAY describes type 7 as a void after authorization. It is never treated as
an application refund of an already succeeded payment. A succeeded/refunded
record is not downgraded by failure/void notifications. Refund accounting
always remains an explicit admin action; refund transaction types are not an
automatic refund API or accounting trigger.

A new paid placement creates an awaiting_payment group and created payment
atomically, for both admin and psychologist creation. The group's original
free snapshot remains immutable. First trusted success changes it to draft
through the existing status-history service. A free creation remains draft
without a payment.

New extension attempts use the current owner tariff. Paid attempts snapshot
amount, eligible group status/expiry and placement days. No dates change until
success. Active success adds the stored days to expiry and clears its warning
marker; expired success transitions to approved without resetting publication
or expiry dates. Clock passage after a started eligible attempt does not
invalidate its success. New attempts and first start must satisfy the window.
An existing created/pending attempt is reused despite later tariff changes;
free extension is blocked while an unfinished paid extension exists.

All confirmation paths lock payment then group in a DB transaction; duplicate
success is acknowledged without another status history row or extension.
A verified success with an unexpected product state retains its verified
binding and pending state for recovery/manual investigation.

Only trusted failed/cancelled attempts permit retry. A retry creates a new
order/row at the current price; repeated submissions reuse the later attempt.
A new extension retry uses current owner tariff and current eligibility.
Historical rows are not deleted. There is no psychologist payment-history menu.

## Bounded recovery

`payments:queue-recovery-checks` runs every five minutes with withoutOverlapping.
It selects only pending attempts started at least 20 minutes ago and having
verified binding plus authoritative transaction_id. One unique database job
per payment is queued, using the same framework UniqueLock convention as mail.
A shared cache lock serializes return/job provider checks (120 seconds versus
at most 30 seconds transport and 45 seconds job timeout).

At most four actual API calls: first eligible evaluation, then at least 10,
30 and 55 minutes after that first check. No new call at or beyond 60 minutes.
Times/counters are reserved transactionally before the call; failed transport
consumes a slot and leaves pending. A due check is revalidated in the job.
Successful payments are never selected. Worker crashes do not create unlimited
attempts. Shared persistent cache and a running database worker are required.

For unbound payments no job/API call occurs. Manual review is derived at
started_at + 20 minutes. For bound payments it is derived after four calls or
one hour after the first call. Merely classifying manual review does not
increment provider-call counters or mutate payment/group status. There is no
permanent polling and no manual “mark paid” action. Operators investigate in
WEBPAY/support and arrange trusted notification delivery; without signed
merchant-order binding product effects remain blocked.

## Admin accounting

List/detail pages query local data only. Search, status/type/owner filters,
Minsk calendar date ranges, deterministic newest-first pagination and safe
notification journal are available. The detail links to owner/group, displays
actual amount/status/timestamps/check count and the existing confirmation UI.

Physically refund in WEBPAY first. Then open the succeeded local payment and
choose “Отметить возврат выполненным в WEBPAY”, enter a required comment
(up to 16000 characters), and confirm the modal. The CSRF-protected admin-policy
POST locks the payment, changes it to refunded, records UTC refunded_at and
minimal payment.refunded audit metadata. No provider call occurs. A repeat is
rejected. Existing group deletion rules still apply; succeeded unrefunded
payments block deletion, including soft-deleted historical payments.

## Local verification and external acceptance

Automated provider fixtures are synthetic. Http::fake/preventStrayRequests
exercise XML transport and notification flow; MySQL child processes cover
competing confirmations. No real Sandbox payment, real notify delivery, real
get_transaction or physical Sandbox refund has been verified locally.

Staging checklist:

- Obtain Sandbox store/secret/API credentials and enable API access with WEBPAY
  support as needed. Confirm ordinary card notification mode and single-stage
  merchant configuration. Never commit credentials.
- Use public HTTPS with correct APP_URL/base path and reachable notify route.
- Configure positive approved test prices, run migrations, queue and scheduler.
- Inspect v2 form and actual notify delivery; test success, decline, cancel,
  browser-before-notify, notify-before-browser and duplicate notify.
- Verify actual get_transaction XML/signature for a trusted-bound transaction;
  do not invent site_order_id or weaken binding for an unbound return.
- Verify active/expired extensions, window boundaries, changed owner tariff,
  API unavailability and finite manual-review behavior.
- Perform a physical Sandbox refund in WEBPAY and then account for it locally.
- Verify admin/owner authorization, logs/journal redaction and no secret/card data.

Production switch checklist:

- Complete and record Sandbox acceptance first; follow docs/deployment.md.
- Confirm merchant contract/domain `gruppa.info`, allowed callback host and
  `/cabinet` base path, production API permissions and notification mode.
- Securely provision production credentials, set WEBPAY_ENV=production,
  approved real prices and public HTTPS APP_URL; rebuild config cache/restart
  workers. Inspect production action URL and wsb_test=0 without exposing keys.
- Back up DB/files/config, verify scheduler/queue/SMTP/logging/rollback plan,
  and perform separately authorized production acceptance. This task does
  not deploy or make any production transaction.

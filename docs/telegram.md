# Telegram administrator notifications

Configure `TELEGRAM_BOT_TOKEN` and numeric `TELEGRAM_ADMIN_CHAT_ID` privately, then
refresh config/worker processes. Empty placeholders are committed in `.env.example`.
Only HTTPS Bot API `sendMessage` is used, without parse mode or attachments.

Events: psychologist text feedback, new/rejected-resubmitted pending psychologist,
and owner-submitted draft/revision group moderation. Intake replay never queues a
second event. Messages identify the psychologist by ID/name/email and link to admin;
group events also include group ID/title. No questionnaire/documents are sent.

Feedback is trimmed, limited to 2800 characters, text only and limited to two
requests per minute per authenticated psychologist. Its success means queued;
queue insertion failure returns a retryable error. Intake/group changes commit
before queue dispatch; unavailable Telegram/queue cannot undo those changes.

Database worker: three attempts, 60/300-second backoff, 45-second timeout;
HTTP connect/request limits are 5/20 seconds, redirects disabled. Check safe
`telegram.queue_unavailable` / `telegram.delivery_failed` codes and entity/event IDs.
Never log/copy the request URL (it embeds the token), tokens, chat IDs, response
payloads, serialized queue payloads or feedback text. Exceptions deliberately omit
the raw HTTP exception and its chain. Jobs store IDs/text, not Telegram credentials.

A retry after a lost remote response can deliver a duplicate (Telegram has no
application idempotency key here); intake replay suppression is separate from
transport delivery guarantees. Automated tests use HTTP fakes with stray-request
prevention. Real bot/chat delivery requires an operator-approved external smoke.

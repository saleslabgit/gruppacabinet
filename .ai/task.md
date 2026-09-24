# Task: TASK-2026-09-24-04

Status: planned
Created from: aff00f66d2dd75ac00025586bb03288d95abae28 (main)

## Title

Admin group controls, psychologist-hidden rejected groups, placement date editing, and deleted-email reuse

## Goal

Implement four approved product changes without adding arbitrary manual group-status overrides:

1. administrators can soft-delete groups in any status, subject to existing payment safety;
2. when a psychologist deletes a rejected group, it disappears only from the psychologist cabinet and remains visible to administrators as a normal rejected group;
3. administrators can manually correct group placement publication/expiry dates;
4. a new public psychologist application may reuse the email of a soft-deleted psychologist by creating a new user record instead of returning `psychologist_conflict`.

Do **not** implement the previously discussed generic admin "change group status manually" control in this task.

## Facts

- Current HEAD is `aff00f66d2dd75ac00025586bb03288d95abae28`.
- Groups already use Laravel soft deletes.
- Current admin group delete policy is intentionally limited to abandoned old `awaiting_payment` / `draft` groups.
- Current psychologist delete policy allows only `draft` and `rejected` groups and blocks deletion when a succeeded payment has not been marked refunded.
- Current psychologist delete calls the same group soft-delete path as admin delete; therefore a psychologist-deleted rejected group also disappears from the admin cabinet.
- Current admin group edit may change ordinary group content regardless of status but cannot edit `published_at` / `expires_at`.
- Placement logic stores dates in UTC. Existing presentation converts them to `Europe/Minsk`.
- `placement_days` is the duration snapshot used by the lifecycle/extension logic and is not currently an admin-editable content field.
- Current intake loads psychologists with `withTrashed()` and treats any soft-deleted email match as `psychologist_conflict`.
- The database already permits reuse of a soft-deleted psychologist email through the generated `active_email` unique key; admin psychologist validation also treats only non-deleted users as email conflicts.
- Existing group statuses and transition matrix are explicit and must remain the source of lifecycle state.
- Existing succeeded/unrefunded-payment safety must remain intact.
- Existing status history must continue to contain only real status transitions.

## Scope

### 1. Administrator may soft-delete groups in any status

Change the admin delete authorization/UI so an eligible administrator may soft-delete any non-deleted group regardless of its status.

Required rules:

- keep soft delete; do not hard-delete groups;
- keep the current safety rule: deletion is forbidden if the group has any succeeded payment, including soft-deleted historical payment rows, whose refund has not been recorded;
- the payment safety applies to all statuses;
- psychologist delete rules remain separate;
- admin delete does not change the group status before deletion;
- admin delete must not create fake status-history entries;
- record the admin delete as a business audit entry (stable action code, e.g. `group_deleted`) with only minimal non-sensitive metadata such as current status;
- the existing confirmation remains mandatory;
- for an `active` group, show a clear warning that publication on `gruppa.info` must be removed manually before/when deleting;
- for an `approved` group, show a warning to verify/remove any manual public-catalog publication if applicable;
- warnings are operational guidance, not a new automatic external-site integration.

Do not expose soft-deleted groups in the normal admin group list after an administrator deletes them.

### 2. Psychologist deleting a rejected group hides it only from that psychologist

Add an explicit nullable group field for the psychologist-side deletion/hide state, using a clear name such as:

`psychologist_deleted_at`

Semantics:

- this is **not** Laravel soft delete;
- only the psychologist-owner flow uses it;
- when the owner deletes a `rejected` group:
  - leave `gp_groups.deleted_at = null`;
  - leave status exactly `rejected`;
  - set `psychologist_deleted_at` to the current UTC time;
  - do not remove payments, applications, status history, UUID or other related data;
  - do not create a status transition;
  - redirect successfully as today;
- after that action the group must disappear from the psychologist's list and behave as nonexistent/inaccessible through psychologist group show/edit/delete/extension/application-management routes;
- administrators must continue to see/open/search/filter the group normally as status `rejected`;
- admin views must not silently filter on `psychologist_deleted_at`;
- a psychologist deleting a `draft` group keeps the current real soft-delete behavior;
- all existing succeeded/unrefunded-payment deletion safety remains: a rejected paid group cannot be psychologist-hidden until the refund is recorded;
- a hidden rejected group may later be soft-deleted by an administrator under the new admin delete rule.

Implement one reusable psychologist-visibility rule/scope rather than scattering inconsistent checks across routes.

#### Existing-data migration

Create an additive production-safe migration:

- add nullable timestamp `psychologist_deleted_at` to `gp_groups`, with an index if useful for the owner-list query;
- deterministically backfill existing rows that are currently soft-deleted **and** have status `rejected`:
  - copy their old `deleted_at` value into `psychologist_deleted_at`;
  - clear `deleted_at` so they become admin-visible again;
  - keep status `rejected` and all related data unchanged;
- do not restore soft-deleted groups in other statuses;
- do not hard-delete anything.

The migration must include a safe `down()`. Document any unavoidable information-loss limitation of rollback if needed.

### 3. Administrator may manually edit placement dates

Extend the existing admin group edit form for an existing group with:

- `published_at`;
- `expires_at`.

Do **not** add `placement_days` as a manually editable field.

Required behavior:

- only administrators can submit these fields;
- psychologist-crafted requests cannot change them;
- do not show them on admin group creation as required content;
- use the existing display convention: human input is presented as `Europe/Minsk` local date/time and converted to UTC for storage;
- prefill the fields from current stored values converted to `Europe/Minsk`;
- allow nullable values where the current lifecycle legitimately has no placement dates;
- when both are non-null, require `expires_at > published_at`;
- editing these dates does **not** change group status automatically;
- editing these dates does **not** change `placement_days`;
- when `expires_at` changes, always set `expiry_warning_sent_at = null`;
- when only `published_at` changes and `expires_at` does not, do not reset the warning marker solely for that reason;
- keep the update transactional;
- record a business audit entry with a stable action code such as `group_placement_dates_changed`;
- audit metadata may contain only old/new UTC ISO timestamps for `published_at` / `expires_at`, not group questionnaire content or PII;
- do not create group status-history rows for date-only edits.

Lifecycle remains unchanged:

- active groups whose manually-set `expires_at` is due will still be handled by the existing expiration scheduler;
- changing dates never performs `active -> expired` or any reverse transition synchronously;
- free/paid extension logic continues using the stored `placement_days` snapshot exactly as before.

### 4. Public psychologist application may reuse a soft-deleted email

Change the Stage 11 psychologist intake conflict logic.

Required matrix for a **new request ID**:

- no active user with the email, even if one or more soft-deleted historical users exist -> create a new pending psychologist with a new user ID;
- active pending/rejected user -> keep current resubmission/update behavior;
- active approved user -> `psychologist_conflict`;
- active disabled user -> `psychologist_conflict`;
- a soft-deleted historical row never gets restored or mutated by the new intake;
- historical documents, trainings, groups, payments and other data continue pointing to the historical user;
- the new user starts a fresh lifecycle and fresh related data;
- same-ID idempotent replay behavior remains unchanged;
- concurrent same-email intake must still be protected by the existing active-email uniqueness/concurrency handling and must not create two active users.

Do not change the public-site `form_cabinet.php` contract for this behavior.

### 5. UI and documentation

Update the existing approved Blade pages only; do not create parallel pages.

Update relevant SPEC/docs to reflect the implemented behavior:

- admin delete is available for all statuses subject to payment safety;
- psychologist "delete" for rejected means hide from psychologist cabinet while preserving the rejected group for admin;
- draft psychologist delete remains real soft delete;
- admin can edit placement publication/expiry dates, in Minsk UI time / UTC storage, without editing `placement_days`;
- manual date correction does not itself change status;
- soft-deleted psychologist email can be used for a fresh public application/new user;
- existing group status transition matrix is unchanged;
- generic manual admin status override is explicitly **not** part of this change.

## Out Of Scope

Do NOT:

- add a generic admin status dropdown;
- add arbitrary manual status transitions;
- bypass the existing group status transition service;
- change the enum transition matrix;
- auto-publish or auto-unpublish anything on `gruppa.info`;
- edit `placement_days` manually;
- change payment/refund semantics;
- auto-refund WEBPAY;
- change extension pricing/tariff rules;
- hard-delete groups/users/payments/documents/history;
- restore a soft-deleted psychologist when their email is reused;
- merge old and new psychologist histories;
- change public intake payload fields;
- change WEBPAY signing/trust;
- change auth/session/password flows;
- add dependencies.

## Constraints

- Laravel 12 / PHP ^8.2.
- MySQL production compatibility remains required.
- Migration must be additive and safe for retained production data.
- All multi-row/state-changing operations remain transactional where applicable.
- Status remains the sole group lifecycle source of truth.
- Soft-delete semantics for admin group deletion remain intact.
- Preserve private documents, payments, applications, status history and audit boundaries.
- No real production PII, uploads, logs or credentials in tests/commits.
- No real external WEBPAY/mail calls.
- Keep the diff focused on these approved changes.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Admin can soft-delete a group in every group status when payment safety permits.
2. Admin cannot delete any group with a succeeded payment lacking recorded refund.
3. Admin deletion is audited and does not fabricate a status transition.
4. Active/approved admin delete UI includes the required manual-publication warning.
5. Psychologist deleting a draft still performs ordinary soft delete.
6. Psychologist deleting a rejected group sets `psychologist_deleted_at`, keeps `deleted_at` null and keeps status `rejected`.
7. That hidden rejected group disappears from all psychologist-owned group flows but remains fully visible/searchable/filterable to admin as rejected.
8. Existing soft-deleted rejected groups are migrated to the new psychologist-hidden state and become visible to admin again.
9. Other pre-existing soft-deleted group statuses remain soft-deleted.
10. Admin may edit `published_at` and `expires_at` on an existing group using Minsk-local UI values stored as UTC.
11. Psychologist requests cannot change placement dates.
12. If both placement dates are present, expiry must be later than publication.
13. Changing `expires_at` resets `expiry_warning_sent_at`; changing only publication date does not.
14. Manual date edits do not change status or `placement_days`.
15. Manual date edits are audited with minimal old/new timestamp metadata and do not create status-history rows.
16. A fresh psychologist application may reuse the email of a soft-deleted psychologist and creates a distinct new pending user.
17. The old soft-deleted psychologist and all historical relations remain unchanged.
18. Active pending/rejected/approved/disabled email behavior remains correct.
19. Same-ID replay and concurrent same-email safety remain correct.
20. No generic admin manual-status control or transition-matrix change is introduced.
21. Relevant migration, group workflow/policy/UI, intake and concurrency tests pass.
22. Full MySQL suite passes.
23. Pint and Larastan pass.
24. Documentation matches implemented behavior.
25. No secrets, logs, real PII/uploads or unrelated artifacts are committed.

## Checks

Run and report exact results for:

1. migration/backfill test on disposable MySQL covering:
   - rejected soft-deleted -> admin-visible + `psychologist_deleted_at` preserved from old delete time;
   - non-rejected soft-deleted remains deleted;
   - rollback behavior;
2. focused `GroupWorkflowTest`;
3. focused `GroupLifecycleTest`;
4. relevant payment-era group tests proving succeeded/unrefunded deletion protection remains;
5. admin/psychologist group page tests including direct-route access after psychologist hide;
6. focused `IntegrationIntakeTest` for deleted-email reuse and active-user matrix;
7. `IntegrationConcurrencyTest` for same-email/idempotency safety;
8. relevant psychologist admin tests for email uniqueness consistency;
9. focused audit-log assertions;
10. full MySQL test suite;
11. Pint;
12. Larastan;
13. `composer check-platform-reqs`;
14. `php artisan view:cache`;
15. browser smoke for the changed admin group form/delete warning and psychologist rejected-delete flow at desktop/mobile widths;
16. `git diff --check`;
17. final git/staged/secrets/artifact review.

Database suites sharing the test database must run sequentially.

Do not make real external mail or WEBPAY requests.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is `aff00f66d2dd75ac00025586bb03288d95abae28`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- read relevant SPEC sections for deletion, group lifecycle/statuses, moderation, placement dates, admin groups and Stage 11 repeated psychologist intake;
- inspect:
  - `Group`;
  - `GroupPolicy`;
  - `GroupWorkflow`;
  - `GroupLifecycleService`;
  - `GroupStatusTransitionService`;
  - admin and psychologist `GroupController`;
  - `GroupRequest` and `GroupActionRequest`;
  - group Blade list/detail/form/delete actions;
  - `GroupPages` and date-time formatting helpers;
  - `AuditService`;
  - `IntakeService`;
  - user email uniqueness migration/validation;
  - group workflow/lifecycle/payment/intake/concurrency tests;
- do not overwrite unknown local changes.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- use a migration for the new group field/backfill;
- keep admin delete and psychologist hide as distinct domain operations;
- do not represent psychologist hide as a status transition;
- do not let psychologist-hidden state leak into admin filtering;
- keep all UI date conversions explicit between Minsk display/input and UTC storage;
- do not add a manual status override;
- preserve payment safety and WEBPAY behavior;
- keep tests deterministic and synthetic.

Before commit:

- run all applicable checks above;
- inspect full diff and staged files;
- verify the migration is production-safe and no `migrate:fresh` assumption is required;
- verify no manual status dropdown/route/service bypass was added;
- verify no `.env`, credentials, tokens, logs, real PII/uploads or unrelated artifacts are staged;
- update `.ai/report.md` with factual results only, including migration/deployment notes.

If complete, commit with:

`codex: TASK-2026-09-24-04 expand group admin controls and deleted-email reuse`

If blocked/partial/failed, record the real status and reason in `.ai/report.md`; do not present incomplete work as done.

Do not create an accept commit.

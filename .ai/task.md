# Task: TASK-2026-09-21-03

Status: planned
Created from: 2fdf26b751c27ec6e0a60e419085d5ecab2a43cc (main)

## Title

Stage 5 — Implement real administrator CRUD for psychologists, moderation actions, tariff/access management, private documents, audit, and session revocation

## Goal

Implement the complete Stage 5 psychologist-administration milestone from SPEC.md using the accepted Stage 3 Blade views and the Stage 4 authentication/access foundation.

An authenticated administrator must be able to:

- list psychologists with search, filters, and pagination;
- open a psychologist profile with all stored questionnaire fields;
- create and edit psychologists;
- approve or reject pending psychologists through the existing domain transition service;
- change free/paid tariff;
- enable or disable cabinet access;
- soft-delete psychologists;
- upload, list, securely view/download, and delete private psychologist documents;
- see relevant business-audit history;
- have disable/reject/delete revoke all sessions immediately.

This stage is only administrator management of psychologists and their documents. Do not implement psychologist self-service, invitation/password email delivery, group CRUD, public questionnaire intake, or later payment/API workflows.

## Facts

- Stage 4 is accepted through commit 2fdf26b751c27ec6e0a60e419085d5ecab2a43cc.
- Real administrator authentication and /admin role protection already exist.
- Approved final views already exist for admin psychologist list/detail/form/documents.
- Prototype routes must continue rendering the same final Blade files with synthetic fixtures.
- gp_users, gp_user_documents, gp_audit_log, database sessions, and required indexes already exist.
- UserStatusTransitionService already enforces pending -> approved, pending -> rejected, rejected -> pending.
- approved -> rejected is intentionally not a normal transition; approved users are disabled instead.
- SessionInvalidator already rotates remember_token and deletes target database sessions.
- AuditService already writes stable non-sensitive business audit entries.
- The local filesystem disk root is storage/app/private.
- status is lifecycle truth; accept is compatibility-only and derived centrally.
- gp_users.free is the current psychologist tariff; existing gp_groups.free values are historical snapshots and must not change when user tariff changes.
- Development admin remains admin@gruppa.test / password.

## Assumptions

- Administrator management routes operate only on psychologists with admin=false.
- Administrator accounts must never be editable/deletable through psychologist CRUD.
- A psychologist manually created by an administrator:
  - is forced to admin=false;
  - starts pending;
  - starts enabled;
  - has password=null;
  - receives no email in this stage.
- On create, administrator may select the initial free/paid tariff.
- For existing psychologists, status, tariff, and access changes happen only through explicit confirmed actions, not ordinary profile editing.
- Soft delete is implemented; restore is out of scope.
- Pagination default is 20 psychologists per page, newest first with deterministic ID tie-break.
- Product requirements do not specify document max size. Use configurable technical default:
  PSYCHOLOGIST_DOCUMENT_MAX_KB=10240.
- Allowed document business types are diploma, certificate, license/membership, state registration certificate.
- Allowed actual file content types are PDF, JPEG, PNG.

## Unknowns

- Approved education dictionary item values remain unavailable. Load real active DB items; do not invent values.
- Password invitation/resend email remains Stage 11–12.
- Restore of soft-deleted psychologists is not part of Stage 5.

## Scope

### 1. Real admin routes and navigation

Add real routes under /admin/psychologists for:

- index;
- create/store;
- show;
- edit/update;
- approve;
- reject;
- enable/disable;
- tariff change;
- soft delete;
- documents list/upload/view/download/delete.

All routes stay behind the existing active-admin middleware.

Update real admin navigation so Psychologists links to the real list.
Never route real production actions into /_prototype.
Prototype navigation remains synthetic/no-op.

### 2. Policies and IDOR protection

Use Laravel policies.

Psychologist management policy must require an authenticated administrator and target admin=false.

Administrator accounts must not be returned/manipulated by changing an ID.

Document access must require:
- active administrator;
- parent psychologist admin=false;
- document belongs to that psychologist.

Use nested scoped binding or an equally explicit ownership check.
Cross-psychologist user/document ID substitution must fail safely.
Do not add a permissions package.

### 3. Psychologist list

Connect the approved admin users index to real data.

Show:
- safe full name from nullable name parts;
- email;
- phone;
- status;
- free/paid;
- enabled/disabled;
- registration date.

Implement:
- search across name parts, email, phone;
- status filter;
- free/paid filter;
- pagination;
- empty/no-results state;
- filter persistence across pages.

Exclude admin=true and soft-deleted records.
Order by created_at desc then id desc.
No per-row queries/N+1.
Add a query-count regression test.

### 4. Psychologist detail

Connect the approved detail view to real data.

Show all relevant questionnaire fields from SPEC section 6, including education/license, confirmations, consent, status, tariff, access state, registration date.

Also show:
- real document count and document management link;
- relevant audit history;
- group count/basic summary if safely available.

Do not create Stage 7 production group-management links.

Never expose password or remember token.

### 5. Create psychologist

Connect approved form to POST + CSRF + Form Request.

Server must force:
- admin=false;
- status=pending;
- disabled=false;
- password=null.

Request must not be able to set:
- admin;
- status;
- accept;
- password;
- remember_token;
- deleted_at.

Email is required.
Profile fields follow nullable schema.
Allow initial free/paid tariff.
Validate active-email uniqueness and retain DB constraint as race protection.
Do not send mail.
Redirect to real detail with success notice.

### 6. Edit psychologist profile

Connect approved edit form to real update.

Ordinary profile update may edit questionnaire/profile fields and email only.

It must not directly write:
- status;
- accept;
- admin;
- password;
- remember_token;
- deleted_at;
- free;
- disabled.

Tariff/access/status are dedicated actions.

Use Form Request validation and active-email uniqueness.

### 7. Education dictionary data

Load education_type dictionary from DB.

Offer active items only.
If an existing psychologist references an inactive item, preserve/display it while editing so data is not silently lost.
Do not invent dictionary values.
Do not implement dictionary CRUD.

### 8. Approve

Explicit confirmation action for pending -> approved.

Requirements:
- use UserStatusTransitionService;
- no direct status assignment in controller;
- AuditService action user.approved;
- current admin as actor;
- only minimal old/new status metadata;
- transition + audit coordinated transactionally;
- no email;
- no password assignment.

Invalid transition must not partially write audit/state.

### 9. Reject

Explicit confirmation action for pending -> rejected.

Requirements:
- use UserStatusTransitionService;
- audit user.rejected;
- call SessionInvalidator;
- coordinate transition/audit/session invalidation safely;
- no email.

Do not add approved -> rejected.

### 10. Enable / disable

Dedicated confirmed actions.

Disable:
- set disabled=true;
- SessionInvalidator immediately;
- audit user.disabled with old/new values.

Enable:
- set disabled=false;
- audit user.enabled;
- do not create session.

Repeated actions must be safely idempotent or clearly rejected without misleading duplicate state changes.

### 11. Free / paid

Dedicated confirmed tariff action.

Requirements:
- update gp_users.free;
- audit user.tariff_changed with minimal old/new value;
- do not update existing gp_groups.free;
- explicitly test existing group snapshot remains unchanged;
- no payment behavior.

### 12. Soft delete

Dedicated destructive confirmed action.

Requirements:
- only admin=false psychologist;
- soft delete only;
- SessionInvalidator immediately;
- audit user.deleted;
- preserve related historical/payment data;
- redirect list with success notice.

Restore is out of scope.

### 13. Audit presentation

Mandatory Stage 5 audit actions:
- user.approved;
- user.rejected;
- user.enabled;
- user.disabled;
- user.tariff_changed;
- user.deleted.

Do not place full questionnaire/document data in metadata.

Psychologist detail should show a human-readable chronological audit list with date, action, actor, and minimal state change where useful.
Do not dump raw JSON.

### 14. Private document configuration

Add small config for:
- private disk;
- max KB;
- allowed document type codes;
- allowed file/content types.

Add to .env.example:
PSYCHOLOGIST_DOCUMENT_MAX_KB=10240

Document the value as a configurable technical ceiling, not a business price/setting.

### 15. Upload private documents

Connect real admin document upload.

Validate:
- allowed document type;
- configured max size;
- actual content/file type limited to PDF/JPEG/PNG;
- do not trust only extension.

Store on private disk rooted at storage/app/private.
Use application-generated random filename/path.
Original filename is DB metadata only.
Persist MIME and byte size.
Never copy to public or storage/app/public.
Never expose a direct public storage URL.

If DB persistence fails after writing a file, remove orphaned file.

### 16. View / download private documents

Serve only through authorized controller endpoints.

View:
- authorize admin + nested ownership;
- inline safe response for supported files;
- safe Content-Type;
- no filesystem path exposure.

Download:
- authorize;
- stream/download through Laravel;
- safe original filename;
- no private storage URL.

Do not use Storage::temporaryUrl as a replacement for controller authorization.

Test that guessed /storage paths do not expose private documents.

### 17. Delete document

Confirmed delete action.

Requirements:
- authorize nested ownership;
- delete DB row and private file;
- cross-user document ID substitution fails;
- success notice after real deletion.

Handle storage failure deliberately; do not silently claim success with file left behind.

### 18. Reuse approved Blade views

All real pages must reuse existing Stage 3 views and components.

Each relevant view must support both:
- prototype fixture mode;
- real backend mode.

Real list/detail/forms/documents use real routes, CSRF, validation, old input, real DB options/actions.
Prototype mode remains no-op and retains all variants.

Existing prototype "Resend password setup" may remain for catalog coverage.
Real Stage 5 detail must not offer a working resend-email action; hide it or mark it unavailable until email stage.

Do not redesign the accepted Stage 3 interface.

### 19. Admin navigation/home

Real admin navigation should expose:
- Home;
- Psychologists;
- Logout.

Do not add real group/payment/dictionary/settings routes yet.

Admin home may show a real pending-psychologist count/link if simple.
Other future work-queue items must not use fixture counts.

### 20. Transaction/orchestration

Use a small explicit service for multi-effect psychologist actions if helpful.

Coordinate transactionally where possible:
- status + audit;
- disable + token/session invalidation + audit;
- reject + invalidation + audit;
- tariff + audit;
- soft delete + invalidation + audit.

Do not build a generic workflow framework.

Filesystem writes require explicit compensation/cleanup because filesystem and MySQL are not one transaction.

### 21. Tests

All tests run on MySQL.

Cover at minimum:

#### List
- admin-only access;
- no admin accounts in psychologist list;
- name/email/phone search;
- status filter;
- free/paid filter;
- pagination/query preservation;
- no N+1/per-row query pattern.

#### Create/update
- successful create;
- forced pending/admin=false/disabled=false/password=null;
- initial tariff;
- active-email uniqueness;
- validation errors;
- profile update;
- protected fields cannot be mass-written;
- prototype form still no-op.

#### State/actions
- approve through domain transition;
- reject through domain transition;
- invalid transition no partial write;
- enable/disable;
- tariff change;
- soft delete;
- correct audit actor/action/minimal metadata;
- no email dispatch;
- existing group tariff snapshot unchanged.

#### Session invalidation
- disable removes target sessions;
- reject removes target sessions;
- delete removes target sessions;
- other users' sessions remain;
- remember token rotates.

#### Authorization/IDOR
- psychologist cannot access admin CRUD;
- admin cannot manage another admin via psychologist routes;
- soft-deleted psychologist is not exposed by normal route;
- cross-psychologist document IDs fail safely.

#### Documents
- valid PDF/JPEG/PNG;
- invalid text/executable content rejected;
- max size enforced;
- generated storage name;
- original filename DB metadata only;
- private physical storage;
- no public direct URL;
- authorized view/download;
- cross-owner access denied;
- delete removes DB row + file;
- orphan cleanup on failed persistence where reasonably testable.

#### Regression
- Stage 4 auth/access remains green;
- 31 prototype groups / 249 variants remain;
- production excludes prototype/foundation routes;
- no Stage 6/7+ implementation.

### 22. Documentation

Update:
- docs/architecture.md;
- docs/development.md;
- docs/project-status.md;
- docs/ui-pages.md only where real route wiring needs documentation.

Document private storage and configurable document max size.

## Explicit Out Of Scope

Do not implement:
- public questionnaire API/registration;
- invitation/password email;
- resend password email;
- SMTP;
- temporary/product password assignment;
- psychologist self-service profile/documents;
- group CRUD/moderation;
- applications;
- payments;
- dictionary/settings CRUD;
- scheduler business jobs;
- WEBPAY;
- production deployment;
- user restore.

Do not create real links to unimplemented stages.

## Constraints

- Follow WORKFLOW.md and AGENTS.md.
- Reuse accepted Stage 3 UI.
- Stay behind Stage 4 admin access.
- Use Laravel policies and Form Requests.
- Use UserStatusTransitionService for approve/reject.
- Use SessionInvalidator for disable/reject/delete.
- Use AuditService for mandatory audit.
- status, not accept, drives lifecycle.
- Profile request cannot directly write protected workflow/access fields.
- Documents remain private outside public web root.
- Never log/audit document bodies or full questionnaires.
- Never send email in Stage 5.
- Preserve all 249 prototype variants.
- Tests use MySQL only.
- No new frontend framework/build pipeline.
- No secrets/real personal data.
- Do not alter .ai/task.md.

## Acceptance Criteria

1. Real admin navigation reaches real psychologist list.
2. Only active authenticated admins access Stage 5 routes.
3. Psychologists receive 403 for Stage 5 routes.
4. Index contains only non-deleted admin=false psychologists.
5. Search works for name/email/phone.
6. Status and free/paid filters work.
7. Pagination works and preserves filters.
8. List has no N+1/per-row query pattern.
9. Admin creates psychologist using approved Blade form.
10. Created psychologist is forced pending/admin=false/disabled=false/password=null with chosen initial tariff.
11. Profile editing cannot bypass protected status/tariff/access fields.
12. Active-email uniqueness is enforced by validation and DB.
13. Detail shows all relevant questionnaire data without password/token.
14. Approve uses UserStatusTransitionService.
15. Reject uses UserStatusTransitionService.
16. Invalid transition cannot partially write state/audit.
17. Approve/reject audit actor/action/metadata are correct and non-sensitive.
18. Disable immediately invalidates target sessions and audits.
19. Enable restores access eligibility and audits.
20. Tariff action audits and does not mutate existing group snapshots.
21. Soft delete invalidates sessions, audits, and preserves historical relations.
22. Admin accounts cannot be manipulated through psychologist routes.
23. Documents exist only on private disk.
24. Upload enforces max size and actual allowed file type.
25. Storage filename/path is application-generated.
26. Authorized admin views/downloads documents only through controller.
27. Cross-psychologist document IDOR is blocked.
28. Private documents have no direct public URL.
29. Delete removes DB row and private file.
30. Existing real/prototype Blade files are reused.
31. Real Stage 5 UI has no working resend-password-email action.
32. No mail is sent by create/approve.
33. Stage 4 auth/access remains green.
34. All 31/249 prototype variants remain green.
35. Full MySQL suite passes.
36. Pint passes.
37. Larastan passes.
38. composer check-platform-reqs passes.
39. Blade compilation passes.
40. Documentation matches implementation.
41. Final diff is limited to Stage 5 admin psychologist/document functionality, necessary UI integration, tests/docs/config, and .ai/report.md.

## Verification Commands

Run and report exact results.

1. Confirm Docker services and real admin login.
2. Migrate/seed without destructive reset of normal development DB.
3. Verify through real Docker HTTP runtime:
   - list/search/filter;
   - create/edit;
   - approve/reject;
   - tariff change;
   - enable/disable;
   - soft delete;
   - document upload/view/download/delete.
4. Verify psychologist role receives 403.
5. Verify admin-account ID substitution fails.
6. Verify cross-psychologist document ID substitution fails.
7. Verify guessed public storage path does not expose private file.
8. Verify no email/job is emitted by create/approve.
9. Verify disable/reject/delete remove target database sessions.
10. Run:
   - docker compose exec -T php php artisan test
   - docker compose exec -T php ./vendor/bin/pint --test
   - docker compose exec -T php ./vendor/bin/phpstan analyse --no-progress
   - docker compose exec -T php composer check-platform-reqs
   - docker compose exec -T php php artisan view:cache
11. Inspect real route list and production route isolation.
12. Inspect private/public storage tree.
13. Inspect representative audit rows for sensitive data.
14. Inspect query count for populated psychologist index.
15. Inspect git diff/status/staged files.
16. Confirm no uploaded test files, .env, real user data, secrets, browser artifacts, or unrelated files are staged.

## Hard Workflow Gate

Before changing files:

- read WORKFLOW.md, AGENTS.md, SPEC.md, docs/project-status.md, docs/ui-pages.md, and this .ai/task.md;
- run git log --oneline -5;
- run git status --short;
- confirm base commit 2fdf26b751c27ec6e0a60e419085d5ecab2a43cc;
- inspect existing Stage 3 psychologist/document views and Stage 4 auth/session services;
- do not overwrite unknown local changes.

During implementation:

- stay strictly in Stage 5;
- do not implement email/onboarding or Stage 6/7 flows;
- keep protected fields out of ordinary profile mass assignment;
- keep documents private;
- preserve prototype behavior and accepted design;
- do not alter .ai/task.md;
- do not change governance/spec files.

Before commit:

- run all required checks;
- perform real HTTP CRUD/document smoke verification;
- update .ai/report.md with routes/controllers/requests/policies/services, list/filter/pagination, audit/session behavior, document storage/security, checks, facts/assumptions/unknowns;
- inspect complete diff and staged files;
- stage only Stage 5 files plus .ai/report.md;
- confirm no private uploads, secrets, runtime artifacts, or unrelated files are staged.

Completion:

- use Status: done only if all Stage 5 acceptance criteria are satisfied;
- otherwise use partial, blocked, or failed;
- if complete, commit with:

codex: TASK-2026-09-21-03 implement psychologist admin CRUD

- do not create an accept commit.

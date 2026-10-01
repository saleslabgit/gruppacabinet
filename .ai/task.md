# Task: TASK-2026-10-01-05

Status: planned
Created from: 695ee2e6e234a177a12b3dd00b53540157c045ae (main)

## Title

Correct pause timing semantics and hidden-group lifecycle processing

## Goal

Correct TASK-2026-10-01-04 before acceptance.

Two product rules are authoritative:

1. **Pause means publication control only.**
   Pausing a group removes it from publication, but the placement end date never
   moves. Time continues to run while the group is paused.
2. A group hidden/deleted by its psychologist must no longer receive placement
   warning mail or later be processed by the placement-expiration scheduler.

Keep the rest of TASK-2026-10-01-04 intact.

## Facts

- Current implementation commit:
  `695ee2e6e234a177a12b3dd00b53540157c045ae`.
- Current TASK-2026-10-01-04 implementation is not accepted yet.
- It currently implements:
  - `active -> paused`;
  - async MODX unpublish on pause/delete/expiration;
  - async MODX publish on resume;
  - publication revisions/idempotency/stale-result protection;
  - deletion semantics requested by the product owner;
  - optional secondary-currency price and editor/UI changes.
- Current implementation **incorrectly freezes placement time**:
  - it records `paused_at`;
  - successful resume adds `now - paused_at` to `expires_at`.
- Current `GroupLifecycleService::expireDueGroups()` only processes `active`.
- Current expiry-warning command/job only process `active`.
- Psychologist deletion keeps the row non-soft-deleted for admin visibility and
  sets `psychologist_deleted_at`. Therefore an active psychologist-hidden group
  can currently still be selected by expiry warnings and later expiration.
- Participant intake already rejects `psychologist_deleted_at !== null`.

## Product decisions

### Pause does not freeze placement time

Pause remains:

```text
active -> paused
```

and still asynchronously requests MODX `published=false`.

However:

- **never modify `expires_at` when pausing**;
- **never modify `expires_at` when resuming**;
- the placement clock continues normally while paused;
- `paused_at` may remain as operational/history metadata, but must not be used
  to add time to the placement;
- successful resume clears `paused_at` and transitions `paused -> active`
  only after confirmed remote publish;
- successful resume does not reset/extend the placement end date.

Example:

```text
expires_at = 2026-10-20 12:00
pause      = 2026-10-10 12:00
resume     = 2026-10-15 12:00

after resume:
expires_at = 2026-10-20 12:00
```

### Paused groups can expire

Because the end date keeps running, a paused group must not remain paused past
its placement deadline.

Add allowed transition:

```text
paused -> expired
```

`groups:expire` must process due groups in both statuses:

```text
active
paused
```

provided:

- `expires_at <= now()`;
- the group has not been hidden by its psychologist;
- normal soft-delete/global-scope rules still apply.

For due paused groups:

- transition `paused -> expired`;
- clear `paused_at`;
- desired MODX publication state remains/becomes `unpublished`;
- schedule a newer unpublished publication revision when a remote Resource ID
  exists, so any older pending/in-flight resume publish cannot win;
- local expiration commits independently of MODX.

### Resume is allowed only before expiry

Owner resume requires:

- status `paused`;
- not disabled;
- visible to owner;
- valid remote Resource ID;
- `expires_at > now()`.

If the group is already due, resume must not publish it.

The publication job must recheck the deadline:

- before remote publish;
- after remote publish returns, before local `paused -> active`.

Race rule:

If a resume request was valid when queued but `expires_at` is reached while the
remote publish request is in flight:

1. do **not** reactivate the group;
2. locally move/leave it in `expired` through the normal transition/history
   boundary;
3. create a newer desired `unpublished` publication revision;
4. queue remote unpublish after commit.

Thus a late publish response can never leave an expired local group publicly
visible.

### Expiry warning while paused

Pause is only publication state; placement time still runs.

Therefore expiry warnings should treat a visible paused placement the same as a
visible active placement for the remaining-time threshold.

Eligible warning statuses:

```text
active
paused
```

All existing owner/account/disabled/date/sent guards remain.

A queued warning job must recheck the current status and may send only for
`active` or `paused`.

### Psychologist-hidden groups leave placement automation

Once:

```text
psychologist_deleted_at IS NOT NULL
```

the group is intentionally removed from that psychologist's lifecycle.

It must not:

- be selected by `groups:queue-expiry-warnings`;
- send a previously queued expiry-warning email;
- be selected by `groups:expire`;
- be expired by a stale/direct `expireOne()` call;
- have `expiry_warning_sent_at` marked by a stale warning job.

The delete-triggered MODX publication job still runs because it is the mechanism
that removes the already synchronized group from the public site.

Admin visibility/history/payment preservation from TASK-2026-10-01-04 remains.

## Scope

### 1. Remove pause-duration extension

Update `SetGroupModxPublication` resume success handling.

Remove:

- calculation of pause duration;
- `expires_at->addSeconds(...)`;
- any test/docs/UI wording claiming paused time is returned to the placement.

Successful current resume revision:

1. remote publication is confirmed;
2. row-lock/recheck group;
3. confirm it is still current, paused, not deleted/hidden/disabled, same remote
   identity, and `expires_at > now()`;
4. clear `paused_at`;
5. transition `paused -> active`;
6. mark publication state published.

Do not mutate `expires_at`.

Do not reset `expiry_warning_sent_at` merely because the group was resumed;
the original placement warning semantics/time window continue.

### 2. Add paused expiration

Update `GroupStatus::canTransitionTo()`:

```text
paused -> active
paused -> expired
```

Update `GroupLifecycleService`:

- bulk candidate query: status active OR paused;
- add `whereNull('psychologist_deleted_at')`;
- `expireOne()` row-lock recheck:
  - status active/paused;
  - due expires_at;
  - psychologist_deleted_at null.

On expiration:

- normal system history transition to expired;
- clear `paused_at` if set;
- schedule desired MODX unpublished after commit when remote ID exists.

Do not duplicate remote calls if no remote Resource exists.

### 3. Protect resume/expiry race

Update resume policy/domain validation to require future `expires_at`.

Before publication HTTP, a publish revision that is no longer resumable because
the deadline is due must not send publish.

After successful publication HTTP, recheck deadline under row lock.

If it became due during HTTP:

- transition paused -> expired;
- clear paused_at;
- create/schedule a newer unpublished publication revision;
- do not transition active;
- do not extend expires_at.

The old publish revision must not overwrite the newer publication intent/state.

Add concurrency/stale tests for this exact race.

### 4. Correct paused UI copy

Remove/rewrite any text such as:

- `Время размещения остановится`;
- `Оставшееся время размещения сохранится`;
- `Дата окончания будет сдвинута после возобновления`;
- equivalent documentation claims that the clock is frozen.

Pause confirmation must clearly say:

- group will be removed from publication;
- participant applications stop;
- **placement end date does not change**.

Paused summary must show that the group is unpublished/paused while its placement
continues until the existing `expires_at`.

Resume confirmation must not promise recovered time.

The displayed `Размещение до` date remains unchanged through pause/resume.

### 5. Expiry warnings for paused but not hidden groups

Update `QueueExpiryWarnings`:

- status active OR paused;
- `whereNull('psychologist_deleted_at')`;
- preserve every other existing eligibility condition and unique-lock behavior.

Update `SendExpiryWarning::handle()` recheck:

- status active OR paused;
- psychologist_deleted_at must be null.

Update the final row-locked `expiry_warning_sent_at` write with the same status
and hidden-group guard.

Do not send warning mail to psychologist-hidden groups.

### 6. Expiration exclusion for psychologist-hidden groups

Update both:

- `expireDueGroups()` query;
- `expireOne()` row-lock recheck;

to require `psychologist_deleted_at IS NULL`.

A psychologist-hidden active/paused group remains in its retained admin/audit
state and is not mutated later by placement scheduler.

### 7. Preserve accepted TASK-04 work

Do not undo unrelated implemented behavior:

- simplified rich-text toolbar;
- removed helper texts;
- optional secondary-currency field / `price_usd` mapping;
- delete available across lifecycle statuses;
- successful payments no longer block delete;
- no automatic refund;
- psychologist delete via `psychologist_deleted_at`;
- admin soft delete;
- async MODX publication endpoint/client;
- revision-scoped publication idempotency;
- shared content/publication remote lock;
- no remote Resource delete;
- initial publication remains manual;
- expired renewal remains manual publication + admin activation.

## Tests

Add/update focused tests proving:

### Pause date semantics

- pause leaves `expires_at` byte/time-equivalent;
- successful resume leaves `expires_at` unchanged;
- repeated pause/resume leaves the original expiration unchanged;
- resume does not reset `expiry_warning_sent_at`;
- UI continues to display the same placement end date;
- no UI/docs claim time is frozen/recovered.

### Paused expiration

- paused + future expires_at is not expired;
- paused + due expires_at transitions to expired;
- `paused_at` clears;
- history records paused -> expired system transition;
- remote ID schedules unpublished after commit;
- no remote ID queues nothing.

### Resume deadline/race

- resume action is forbidden/rejected once expires_at <= now;
- queued publish skips before HTTP if deadline becomes due;
- deadline crossing while HTTP is in flight never produces local active;
- late successful remote publish produces expired + newer unpublished intent;
- stale publish revision cannot overwrite the new unpublished revision;
- expires_at never changes in this race.

### Hidden-group warning/expiry regression

For psychologist-hidden active and paused groups:

- warning scheduler queues nothing;
- already queued SendExpiryWarning sends nothing;
- warning marker stays null;
- expiration command ignores them;
- direct/stale expireOne returns false;
- status remains unchanged.

Confirm delete still queues publication-unpublished where a remote Resource ID
exists.

### Existing regressions

Re-run all TASK-04 focused publication/delete tests after adjusting their
time-freeze expectations.

## Documentation

Correct all TASK-04 statements in:

- `SPEC.md`;
- `docs/modx-api.md`;
- `docs/modx-group-sync-plan.md`;
- `docs/project-status.md`;
- `docs/architecture.md`;
- `docs/development.md`;
- `docs/deployment.md`;
- `docs/ui-pages.md` where relevant.

The documentation must say:

- pause controls publication only;
- expires_at does not change;
- paused placement continues counting down;
- paused groups can automatically expire;
- expiry warnings may still be sent while paused;
- psychologist-hidden groups receive no future warning/expiration processing;
- resume before expiry republishes the same Resource and returns to active;
- initial/expired-renewal publication rules remain unchanged.

## Out Of Scope

Do NOT:

- change the external MODX endpoint contract from
  `POST /cabinet/resources/publication`;
- add remote delete;
- auto-publish initial approved groups;
- auto-refund payments;
- restore psychologist-hidden groups;
- change price/editor work from TASK-04 except regressions needed by this correction;
- make real MODX HTTP requests;
- add packages/Node/Vite;
- create an accept commit.

## Acceptance Criteria

1. Pause/resume never changes `expires_at`.
2. Paused placement time continues to elapse and paused groups expire at the
   original deadline.
3. Resume cannot reactivate/publish a group whose placement has expired.
4. A deadline race during remote publish results in expired + desired unpublished,
   never active/public.
5. Visible paused groups continue to receive normal expiry warnings.
6. Psychologist-hidden groups receive no expiry warnings and no later local
   expiration processing.
7. Delete-triggered MODX unpublish still operates for hidden/deleted groups.
8. All accepted TASK-04 editor/price/delete/publication behavior remains intact.
9. Full regression/static checks pass.
10. No real MODX request or secret/private artifact is introduced.

## Checks

Run and report exact results for:

1. focused GroupPublicationTest corrections;
2. full ExpiryWarningTest;
3. full GroupLifecycleTest + concurrency;
4. full GroupWorkflowTest;
5. ModxGroupSyncTest + ModxGroupClientTest;
6. IntegrationIntakeTest;
7. GroupContent/Prototype/payment regressions affected by TASK-04;
8. full MySQL suite;
9. Pint;
10. PHPStan;
11. composer check-platform-reqs;
12. composer validate --no-check-publish;
13. artisan view:cache;
14. artisan schedule:list;
15. git diff --check;
16. final status/diff/staged secret/artifact review.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and parent is
  `695ee2e6e234a177a12b3dd00b53540157c045ae`;
- read WORKFLOW.md, AGENTS.md, task/report;
- inspect TASK-04 implementation diff and relevant lifecycle/publication/warning
  code/tests/docs;
- verify clean/known local tree.

During implementation:

- work only within this corrective task;
- do not edit `.ai/task.md`;
- no real MODX;
- no external plugin source;
- keep publication control async/after-commit;
- preserve revision/stale protections;
- never modify expires_at because of pause/resume;
- ensure hidden groups leave placement automation;
- avoid unrelated refactors.

Before commit:

- run required checks;
- inspect complete diff/staged files;
- verify no secrets, production fixtures, uploads/base64/log/cache/vendor/private
  artifacts are staged;
- update `.ai/report.md` factually;
- explicitly state live publication endpoint was not called.

If complete, commit with:

`codex: TASK-2026-10-01-05 correct pause timing lifecycle`

Do not create an accept commit.

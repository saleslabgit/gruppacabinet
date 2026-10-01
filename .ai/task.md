# Task: TASK-2026-10-01-01

Status: planned
Created from: 39085428a15ea6a94836612ee4e61f69cbaaefd2 (main; corrective planner iteration after blocked contract-shape check)

## Title

Synchronize MODX-managed group dictionaries into Cabinet

## Goal

Implement the Cabinet-side synchronization layer for five group option lists
whose source of truth is the main MODX site:

- group format;
- participant gender;
- group type;
- approaches;
- tags.

After this task, Cabinet must keep a safe local copy of those lists for later
group-form work. Do not change the group form and do not send groups to MODX in
this task.

## Facts

- Current task base before this corrective planner commit is `39085428a15ea6a94836612ee4e61f69cbaaefd2`; implementation must start from the corrective planner HEAD.
- The public site runs MODX 3 with mxHeadless and the external
  `GruppaCabinetApi` plugin.
- The operator manually verified
  `GET https://gruppa.info/api/v1/cabinet/dictionaries`.
- The endpoint returned `data.complete=true`.
- Exact verified JSON nesting is:

```json
{
  "data": {
    "complete": true,
    "dictionaries": {
      "format": {
        "tv_id": 35,
        "tv_name": "format",
        "type": "listbox",
        "options": [
          {
            "value": "Офлайн",
            "label": "Офлайн",
            "position": 0
          }
        ]
      },
      "gender": {
        "tv_id": 39,
        "tv_name": "gender",
        "type": "listbox",
        "options": [
          {
            "value": "Смешанная",
            "label": "Смешанная",
            "position": 0
          }
        ]
      },
      "groupType": {
        "tv_id": 37,
        "tv_name": "groupType",
        "type": "listbox",
        "options": [
          {
            "value": "17",
            "label": "Супервизорская группа",
            "position": 8
          }
        ]
      },
      "approaches": {
        "tv_id": 36,
        "tv_name": "approaches",
        "type": "listbox-multiple",
        "options": [
          {
            "value": "2",
            "label": "Гештальт-терапия",
            "position": 11
          }
        ]
      },
      "tags": {
        "tv_id": 30,
        "tv_name": "tags",
        "type": "listbox-multiple",
        "options": [
          {
            "value": "77",
            "label": "гештальт",
            "position": 80
          }
        ]
      }
    }
  },
  "meta": []
}
```

This is the authoritative response shape for implementation. Tests may use a
smaller synthetic option set, but must preserve this exact nesting and field
names. Do not invent wrappers such as `data.data`, `items`, `results`,
or top-level dictionary keys.
- Verified definitions:
  - `format`: TV 35, type `listbox`, 2 options;
  - `gender`: TV 39, type `listbox`, 3 options;
  - `groupType`: TV 37, type `listbox`, 23 options;
  - `approaches`: TV 36, type `listbox-multiple`, 23 options;
  - `tags`: TV 30, type `listbox-multiple`, 105 options.
- Exact remote `value` is the identity. Labels are not unique. Verified tag
  duplicates include:
  - `зависимость`: values `51` and `105`;
  - `психосоматика`: values `30` and `57`;
  - `подростки`: values `88` and `102`.
- MODX listbox-multiple values use `||` serialization. Outbound serialization
  is later work and is out of scope here.
- Current Cabinet has `education_type`, `group_format`, and `gender`
  dictionary containers.
- Existing production `group_format` and `gender` items may already be
  referenced by groups. Their local IDs/codes/references must be preserved.
- Current dictionary admin permits manual item mutations.
- Automated verification must not call the real public site.
- Runtime credentials are private configuration and must never be committed or
  exposed in logs/UI.

## Assumptions

- The endpoint response shape remains:
  `data.complete=true` and `data.dictionaries`.
- Every option has a nonempty string `value`, nonempty string `label`, and
  nonnegative integer `position`.
- Remote `position` maps to local `sort_order`.
- MODX is authoritative only for the five lists above.
- `education_type` and arbitrary custom dictionaries remain locally managed.

## Unknowns

- Existing production labels and local codes for format/gender are not known.
- Legacy local items may not have a corresponding MODX option.
- This task does not establish the future group field mapping.

## Scope

### 1. Add MODX metadata to dictionaries

Create an additive migration.

Add to `gp_dictionaries`:

- nullable `modx_tv_name` (max 64);
- nullable `last_synced_at` timestamp;
- uniqueness for non-null MODX TV ownership.

Add to `gp_dictionary_items`:

- nullable `modx_value` (max 255);
- nullable `last_synced_at` timestamp;
- unique `(dictionary_id, modx_value)`; multiple NULL legacy values must remain
  possible in MySQL.

A dictionary is MODX-managed when `modx_tv_name` is non-null. Do not add a
second boolean flag.

Ensure/protect these containers:

| Cabinet code | MODX TV |
|---|---|
| `group_format` | `format` |
| `gender` | `gender` |
| `group_type` | `groupType` |
| `group_approach` | `approaches` |
| `group_tag` | `tags` |

Keep `education_type` local-only. Update clean-install seed/bootstrap behavior
without overwriting unrelated production items.

Do not add group type/approach/tag group relations or pivot tables yet.

### 2. Add isolated mxHeadless read client

Add private runtime config for mxHeadless base URL, credential, connect timeout,
and request timeout. Add only empty/non-secret examples to `.env.example`.

Use Laravel's HTTP client; add no package.

The client must:

- GET `/cabinet/dictionaries`;
- send JSON accept headers and configured Bearer credential;
- use bounded connect/request timeouts;
- refuse to call when required configuration is missing;
- reject non-2xx responses and malformed JSON;
- require `data.complete === true`;
- require all five expected dictionaries;
- require exact TV names and verified types:
  - format/listbox;
  - gender/listbox;
  - groupType/listbox;
  - approaches/listbox-multiple;
  - tags/listbox-multiple;
- validate option value/label/position;
- reject duplicate remote values within a dictionary;
- never log credential/header or full remote body.

### 3. Implement atomic sync service

Create `ModxDictionarySyncService` or an equivalent focused service.

Use one shared cache/distributed lock around fetch+sync so scheduled, CLI and
admin-triggered runs cannot overlap. An already-running sync must be a safe
non-destructive no-op/error.

Fetch and fully validate remote data before opening the DB mutation transaction.
Then apply all five managed dictionaries in one transaction.

For every remote option:

1. Match an already linked local item by exact
   `dictionary_id + modx_value`.
2. If found, preserve local item ID/code, update name, sort_order, active=true,
   and last_synced_at.
3. If not linked, inspect only legacy items in that same dictionary with
   `modx_value IS NULL`.
4. Legacy bootstrap comparison is Unicode-aware lowercase plus trimmed/collapsed
   whitespace only.
5. Exactly one normalized-label match: attach the remote value to that existing
   item, preserving ID/code and any existing FK.
6. More than one legacy label match: abort and roll back all five dictionaries.
7. No legacy match: create a new active item with exact remote value/name/order
   and a deterministic valid local code, e.g. `modx_` plus a collision-resistant
   lowercase hex digest prefix. Detect a real code collision and abort rather
   than guessing.

After one managed dictionary has been processed from the complete response:

- linked items whose remote value disappeared become inactive, never deleted;
- remaining legacy items with NULL modx_value become inactive so they cannot be
  selected for new MODX-bound data, but they remain stored for history;
- a disappeared value that later returns must reactivate the same item ID/code;
- update parent last_synced_at only inside the successful all-five transaction.

Any ambiguity, validation error, or DB failure rolls back all five dictionaries.

### 4. Restrict manual administration for MODX-managed items

Protect all five MODX-managed containers from deletion.

At a server-side service/domain boundary, block manual item
create/update/activate/deactivate/delete for managed dictionaries.

Extend the existing real admin dictionary UI minimally:

- indicate that the dictionary is managed by MODX and show its TV name;
- show item `modx_value` and useful last-sync state;
- hide mutation controls/add-edit form for managed items;
- keep existing CRUD unchanged for `education_type` and custom dictionaries.

Do not redesign the pages.

### 5. CLI, scheduler and admin manual sync

Add:

`php artisan modx:sync-dictionaries`

Requirements:

- success exits 0 with safe aggregate counts only;
- missing config, HTTP/contract error, ambiguity, DB failure or lock contention
  exits nonzero with sanitized diagnostics;
- no token or full remote response in output.

Schedule the command hourly with `withoutOverlapping`.

Add one admin-only POST action/button `Обновить из MODX` on the existing
dictionary administration page. It must call the same sync service and show only
sanitized success/error feedback.

### 6. Tests and docs

Use `Http::fake()`. No test may contact gruppa.info.

Cover at least:

- first complete import;
- same label with distinct remote values creates distinct items;
- legacy format/gender normalized-label bootstrap preserves item ID/code and an
  existing group's FK;
- remote rename preserves identity;
- disappearance deactivates without deleting;
- reappearance reactivates the same item;
- unmatched legacy item is preserved but inactive;
- ambiguous legacy label matching rolls back all five dictionaries;
- incomplete response, missing dictionary, wrong TV name/type, malformed
  option, duplicate remote value, HTTP failure and connection exception make no
  local sync changes;
- CLI success/failure;
- hourly scheduler registration;
- admin-only manual sync;
- manual item mutations blocked for managed dictionaries;
- local-only/custom dictionary CRUD still works;
- credential never appears in output/log/UI errors.

Update `docs/modx-group-sync-plan.md` and `docs/project-status.md`. Update
other integration/config documentation only where implementation changes facts.

## Out Of Scope

Do not:

- change the group create/edit/moderation form;
- add group_type_id, approaches/tags pivots, structured schedule, HTML
  description, image fields, or cover upload;
- implement the Cabinet-to-MODX group payload builder;
- send any group to `POST /cabinet/resources/sync`;
- add public_site_resource_id to the application schema in this task;
- change moderation/approval behavior;
- change the MODX plugin;
- add image/base64 transport or mxHeadless body-limit changes;
- decide price/public_uuid/SEO mappings;
- touch WEBPAY, email, the 405/route-cache issue, or unrelated code;
- make any real MODX request from Codex or tests;
- commit credentials, response dumps, logs, caches, uploads or production data.

## Constraints

- Laravel 12, PHP ^8.2, MySQL.
- No Node/npm/Vite.
- No new package is expected.
- Exact MODX stored value is the persistent remote identity.
- Preserve existing local IDs/codes/history whenever safely possible.
- Validate the complete remote document before DB writes.
- Apply all five dictionaries atomically.
- Network/API failure must leave the last successful local copy unchanged.
- Managed item mutation must be blocked server-side, not only hidden in Blade.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Five managed dictionaries exist with the correct MODX TV names.
2. A full mocked response synchronizes exact values, labels and order.
3. Duplicate labels with different remote values remain distinct.
4. Safely matched legacy format/gender items keep IDs/codes/FKs.
5. Rename changes label only.
6. Removed values deactivate; returning values reactivate the same item.
7. Unmatched/ambiguous legacy data is handled without guessing or deletion.
8. Any failed sync leaves all five dictionaries at their previous successful
   state.
9. Scheduler, CLI and admin action use the same non-overlapping service.
10. Managed items cannot be manually mutated; local dictionaries still can.
11. Tests make no real external HTTP calls.
12. Credentials/full remote bodies are not exposed.
13. Existing dictionary and group behavior remains passing.
14. Docs accurately distinguish this implemented cache/sync layer from future
    group-form and outbound synchronization.

## Checks

Run and report exact results for:

1. focused MODX dictionary-sync tests;
2. full `DictionaryAdminTest`;
3. relevant `GroupWorkflowTest` coverage for existing format/gender behavior;
4. deterministic command/schedule tests and `php artisan schedule:list`;
5. full MySQL test suite;
6. `php ./vendor/bin/pint --test`;
7. `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`;
8. `composer check-platform-reqs`;
9. `php artisan view:cache`;
10. `git diff --check`;
11. final git status/diff/staged-file/secret-artifact review.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this corrective planner commit and its parent is
  `39085428a15ea6a94836612ee4e61f69cbaaefd2`;
- read `WORKFLOW.md`, `AGENTS.md`, this task and current report;
- read Stage 17 in `SPEC.md`, `docs/modx-api.md`,
  `docs/modx-group-sync-plan.md`, and `docs/project-status.md`;
- inspect current dictionary models/schema/services/requests/controllers/views,
  DatabaseSeeder, services config, env example, web/console routes and
  `DictionaryAdminTest`;
- verify no unknown local changes.

During implementation:

- work only within this task;
- do not edit this task;
- do not make real MODX requests;
- never use label as persistent remote identity;
- preserve referenced local IDs/codes;
- validate full remote data before mutation;
- sync all five dictionaries atomically;
- never delete historical values during sync;
- block manual managed-item mutations server-side;
- keep credentials/remote body out of logs and errors;
- do not implement group-form or outbound group-sync work.

Before commit:

- run all applicable checks;
- inspect complete diff and staged files;
- verify no unrelated changes;
- verify no env file, credential, token, response dump, log, cache, upload or
  temporary artifact is staged;
- update `.ai/report.md` with factual results and explicitly state that real
  MODX calls were not run by Codex/tests.

If complete, commit with:

`codex: TASK-2026-10-01-01 sync MODX dictionaries`

Otherwise report the real partial/blocked/failed status.

Do not create an accept commit.

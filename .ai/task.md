# Task: TASK-2026-10-01-02

Status: planned
Created from: af89db87412d31cf7787163bff67c919b7bccc20 (main)

## Title

Expand the group schema and moderation form with MODX-ready content fields

## Goal

Make Gruppa Cabinet able to safely store and edit the complete group content
needed for the later MODX outbound mapping.

Update the existing real group form and data model to support:

- short description;
- sanitized full rich-text description;
- private group cover image;
- structured meeting days and start time;
- frequency;
- city;
- MODX-managed group type;
- MODX-managed multiple approaches;
- MODX-managed multiple tags;
- existing format, gender, duration, participant capacity and meeting price.

This task ends at Cabinet storage/UI/domain behavior. It must **not** send group
content to MODX or change the moderation approval transition.

## Facts

- Accepted base commit is `af89db87412d31cf7787163bff67c919b7bccc20`.
- TASK-2026-10-01-01 implemented local synchronization for the five
  MODX-managed dictionaries:
  - `group_format` ↔ `format`;
  - `gender` ↔ `gender`;
  - `group_type` ↔ `groupType`;
  - `group_approach` ↔ `approaches`;
  - `group_tag` ↔ `tags`.
- The group form must use only the local Cabinet DB copy of those dictionaries.
  Opening/saving the form must not make a MODX HTTP request.
- Current `gp_groups.description` exists and currently represents the only
  plain description. It must be retained and become **short description**.
- Current `gp_groups.schedule` is one free-text field. It contains legacy
  arbitrary text and must not be parsed or rewritten automatically.
- Current group form uses one shared
  `resources/views/shared/group-form.blade.php` for psychologist/admin.
- Current psychologist/admin writes pass through `GroupRequest` and
  `GroupWorkflow`; group content and submit transition are transactional.
- Current owner group creation initially creates an empty draft/awaiting-payment
  record and opens the edit form; admin creation can submit content immediately.
- Existing admin edit also supports placement date corrections and must remain
  usable for legacy groups that do not yet have the new content fields.
- Current private filesystem disk `local` points to
  `storage/app/private` and is not web-served.
- Existing `PsychologistDocuments` is a useful private-file/storage/streaming
  pattern, but group covers are separate domain data.
- No Node/npm/Vite build pipeline exists.
- The current production Composer contract requires explicit PHP extensions.
  `ext-dom` is not currently listed.
- The main-site MODX uses TinyMCE, but Cabinet does not currently ship TinyMCE.
  The interoperability requirement is clean semantic HTML, not editor-library
  identity.
- Outbound mapping documented for later work includes:
  - short description → MODX `shortDescription`;
  - full HTML → MODX `desc`;
  - cover → MODX `image`;
  - structured schedule → `days`, `startAt`, `frequency`;
  - group type/approaches/tags through local dictionary `modx_value`;
  - owner name → MIGX `leader` later.
- Exact MODX price/public UUID/image transport/outbound sync behavior is not part
  of this task.

## Assumptions

- Internal weekday codes may be stable English codes independent of MODX:
  `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun`.
- Display order is Monday through Sunday.
- Meeting start time is stored as canonical `HH:MM`; business display remains
  Minsk time.
- A group can have multiple approaches and multiple tags.
- At least one approach and at least one tag are required before psychologist
  moderation submission and for a new fully-created admin group.
- Existing inactive dictionary selections already attached to a group remain
  visible/retainable; new selections must come from active values.
- Cover images are JPEG, PNG or WebP with a configurable default technical
  ceiling of 5 MiB.
- No cover delete-without-replacement action is required in this milestone.
  Existing cover can be replaced.
- A dependency-free progressive rich-text editor is sufficient; do not vendor
  TinyMCE or another editor package in this task.

## Unknowns

- Exact MODX serialization of weekdays is still intentionally deferred to the
  outbound mapper.
- Cabinet `meeting_price` mapping to MODX `price` / `price_usd` remains
  unresolved.
- Exact MODX TV used for Cabinet `public_uuid` remains unresolved.
- Cover upload to MODX and mxHeadless body-limit changes remain future work.

## Scope

### 1. Add group content schema additively

Create a new additive migration. Do not rewrite old migrations.

Add nullable columns to `gp_groups` so existing rows survive deployment:

- `full_description_html` — LONGTEXT;
- `cover_path` — string path;
- `cover_original_name` — string(255);
- `cover_mime_type` — string(100);
- `cover_size` — unsigned bigint;
- `meeting_days` — JSON;
- `start_time` — string(5), canonical `HH:MM`;
- `frequency` — string(255);
- `city` — string(255);
- nullable `group_type_id` FK to `gp_dictionary_items`, restrict on
  dictionary item deletion.

Keep existing `description` and `schedule` columns.

Add:

```text
gp_group_approaches
- group_id
- dictionary_item_id

gp_group_tags
- group_id
- dictionary_item_id
```

Requirements:

- unique pair per pivot;
- FK to group may cascade on physical group deletion;
- FK to dictionary item must restrict deletion;
- no MODX value is copied into group rows/pivots.

Migration down must remove only this task's additions.

### 2. Extend Group model/domain relationships

Update `Group` with:

- casts for `meeting_days` array and `cover_size` integer;
- `groupType()` belongsTo;
- `approaches()` belongsToMany;
- `tags()` belongsToMany.

Update dictionary-usage accounting for:

- `group_type` → `gp_groups.group_type_id`;
- `group_approach` → `gp_group_approaches.dictionary_item_id`;
- `group_tag` → `gp_group_tags.dictionary_item_id`.

Historical/soft-deleted group references still count as usage.

### 3. Define structured group validation

Keep current title/short-description/duration/capacity/price semantics unless
explicitly changed below.

Change the user-facing meaning/label of current `description` to
**Краткое описание**. Keep its current 16000-character server ceiling for
backward compatibility.

Remove `schedule` from new form input and from ordinary content writes.
Never parse legacy `schedule`.

Add validated fields:

- `full_description_html`;
- `meeting_days[]`;
- `start_time`;
- `frequency`;
- `city`;
- `group_type_id`;
- `approach_ids[]`;
- `tag_ids[]`;
- optional uploaded `cover`.

Canonical weekdays:

| Code | Label |
|---|---|
| `mon` | Понедельник |
| `tue` | Вторник |
| `wed` | Среда |
| `thu` | Четверг |
| `fri` | Пятница |
| `sat` | Суббота |
| `sun` | Воскресенье |

Normalize stored meeting days to this canonical order regardless of request
order. Reject unknown/duplicate codes.

`start_time` must be exact `HH:MM` 24-hour time.
`frequency` and `city` are trimmed nonempty strings up to 255 characters.

Dictionary validation:

- `group_type_id` belongs only to `group_type`;
- every approach ID belongs only to `group_approach`;
- every tag ID belongs only to `group_tag`;
- new choices must be active;
- when editing an existing group, its currently attached inactive
  group type/approaches/tags may be retained, analogous to existing
  format/gender behavior;
- forged IDs from another dictionary are rejected;
- arrays must contain distinct integer IDs.

Completeness policy:

- psychologist **submit to moderation** requires all new structured fields,
  sanitized full description, at least one approach, at least one tag, and an
  existing or newly uploaded cover;
- admin **create** requires the same complete group content;
- ordinary psychologist draft/revision save and admin update of an existing
  legacy group may preserve missing new fields so rollout does not prevent
  unrelated edits/date corrections;
- if a new field is supplied during an ordinary save, validate and persist it;
- do not weaken existing required validation for title, current short
  description, format, gender, duration, capacity or meeting price.

The form itself must not depend on a live MODX request. If required managed
dictionary options are unavailable locally, show an honest blocking notice.

### 4. Add server-side clean HTML sanitizer

Create one focused sanitizer service used by all group writes.

Use built-in PHP DOM APIs; no third-party sanitizer/editor package.
If production code depends on DOM, add `ext-dom` to Composer platform
requirements and update relevant environment/deployment documentation.

Technical rich-text ceiling:

- add a configuration value under existing group configuration;
- default raw/sanitized HTML character ceiling: 100000;
- reject oversized input rather than truncating it.

Allowed semantic output elements:

```text
p
br
strong
em
ul
ol
li
h2
h3
blockquote
a
```

Normalize `b` → `strong` and `i` → `em` if convenient; do not preserve
presentation-only elements.

Stored HTML requirements:

- no `class`;
- no `style`;
- no `id`;
- no `data-*`;
- no `on*` event attributes;
- no scripts/styles/iframes/objects/embeds/forms/inputs/buttons/svg/math;
- all attributes removed except safe `href` on `a`;
- link schemes allowed only for `http`, `https`, `mailto`, or a relative
  path beginning with `/`;
- reject/remove `javascript:`, `data:`, protocol-relative or otherwise
  unsafe href values;
- no client-provided HTML class may survive storage;
- dangerous elements such as script/style/iframe must be dropped with their
  dangerous contents; harmless unsupported wrappers may be unwrapped while
  retaining safe child text/elements;
- sanitized content must contain meaningful visible text, not only empty tags.

Store **only sanitized HTML** in `full_description_html`.

When rendering the full description, raw Blade HTML output is allowed only from
this sanitized stored field. Short description remains escaped text.

### 5. Add a dependency-free rich-text editor to the existing form

Do not add TinyMCE, CKEditor, npm, Vite or a new JS dependency.

Create/reuse a Blade component for full description that:

- has a normal textarea as no-JS/accessibility fallback and form source;
- progressively enhances into a contenteditable editor through existing
  `public/ui.js`;
- keeps the textarea synchronized before submission;
- preserves old input after validation errors;
- exposes a small toolbar for at least:
  - paragraph;
  - H2;
  - H3;
  - bold;
  - italic;
  - unordered list;
  - ordered list;
  - blockquote;
  - link;
  - remove formatting;
- does not rely on client cleanliness for security;
- is keyboard/focus usable and has accessible labels;
- does not introduce inline styles/classes into the value intentionally.

Use the existing CSS tokens/components. This is an extension of the accepted
group form, not a redesign.

### 6. Add group cover storage and authorized preview

Add group-cover config, preferably in `config/groups.php`:

- disk: private `local`;
- default max KB: 5120, overridable with a non-secret env variable;
- allowed MIME types: `image/jpeg`, `image/png`, `image/webp`.

Add the empty/default config variable to `.env.example`.

Validation/storage requirements:

- accept JPEG/PNG/WebP only;
- validate actual file MIME/content, not only filename extension;
- reject GIF, SVG, PDF and oversized files;
- save under a random server-generated private path/name;
- sanitize stored original filename;
- never use a user path or filename as the storage path;
- record path/original_name/mime_type/size in the group;
- replacement must keep the previous file until the DB save succeeds;
- if DB/domain save or moderation transition fails, remove the newly stored file
  and keep the old metadata/file;
- after a successful replacement, remove the superseded old private file;
- storage/cleanup failures must not be silently presented as success;
- no cover bytes/path are written to application logs.

Add authorized inline preview routes for psychologist owner and admin, reusing
existing account/role/group authorization boundaries. Cross-owner access must
fail. Missing underlying file must return 404.

Response headers must include at least:

- correct validated Content-Type;
- `X-Content-Type-Options: nosniff`;
- `Cache-Control: private, no-store`.

No public `storage:link` URL may expose covers.

There is no standalone “delete cover” action in this task; upload replaces.

### 7. Persist scalar and many-to-many content atomically

Extend `GroupWorkflow` or an equivalently central group-content boundary.

Scalar group content and approach/tag pivots must be changed in the same DB
transaction as the existing save/submit transition.

Requirements:

- ordinary save updates supplied new fields;
- missing optional new fields on legacy ordinary edit do not erase existing
  stored values/relations;
- when approach/tag arrays are supplied, sync exactly the validated IDs;
- on moderation submit they are required and complete;
- if transition/history persistence fails, scalar fields and pivot changes roll
  back together;
- current public UUID, tariff snapshot and protected lifecycle fields remain
  protected;
- cover metadata participates in the same successful content update, with
  filesystem compensation as defined above.

Do not add outbound MODX behavior to `moderate(... Approved ...)`.

### 8. Update form and read-only group presentation

Update the existing shared group form rather than adding another page.

Target controls:

**Основная информация**

- Название группы;
- Краткое описание;
- Полное описание — rich editor;
- Обложка группы;
- Дни недели;
- Время начала;
- Периодичность;
- Город.

**Условия участия**

- Формат;
- Пол участников;
- Тип группы;
- Подходы — multiple searchable selector;
- Теги — multiple searchable selector;
- Длительность;
- Количество участников;
- Стоимость встречи.

There is **no editable leader field**. Later MODX sync derives leader from the
group owner's first + last name.

Because tags currently contain more than 100 values, do not use an unusable raw
desktop `<select multiple>` as the enhanced UX. Add one reusable,
dependency-free, progressively-enhanced multi-select component for approaches
and tags:

- underlying real `<select multiple>` remains the submitted/no-JS control;
- JS enhancement provides searchable options with checkbox/selected-state UX;
- selection writes back to the real select;
- keyboard/focus behavior must remain usable;
- values are local dictionary item IDs, never MODX raw values in the browser
  contract.

Meeting days may use seven checkboxes rather than this generic multi-select.

Read-only group detail must show:

- short description as escaped text;
- sanitized rich full description;
- structured schedule;
- frequency;
- city;
- group type;
- approaches;
- tags;
- cover preview when present;
- existing format/gender/duration/capacity/price.

Legacy compatibility:

- if a group has no structured meeting fields but has legacy `schedule`, show
  that legacy value clearly rather than losing it;
- do not invent structured values from legacy text.

Update `GroupPages` and eager loading so rendering does not introduce N+1
queries. Preserve list query-count expectations.

### 9. Prototype and accepted UI compatibility

Because the real and prototype surfaces reuse the same Blade views:

- update synthetic group fixtures/options so every existing prototype group form
  still renders;
- keep prototype business actions no-op;
- preserve responsive behavior and existing layout hierarchy;
- add only the CSS/JS needed for the new editor, multi-select, cover preview and
  fields;
- do not create a parallel group-form implementation.

Update `docs/ui-pages.md` only as needed to describe the changed shared form.

### 10. Tests and documentation

Add focused automated coverage for at least:

#### Migration/model

- existing group row survives with original `description` and legacy
  `schedule`;
- new columns are nullable;
- group type/pivot FK/uniqueness constraints;
- model relationships/casts.

#### Validation/dictionaries

- weekday canonicalization/order;
- invalid/duplicate weekdays rejected;
- exact HH:MM validation;
- group type/approach/tag dictionary ownership;
- active-only new choices;
- current inactive selections may be retained on edit;
- duplicate approach/tag IDs rejected;
- complete moderation submit/admin create requires all new content;
- legacy ordinary admin edit remains possible without filling every new field;
- form does not make a MODX HTTP request.

#### HTML security

- allowed semantic elements survive;
- classes/styles/id/data/event attributes are stripped;
- script/style/iframe/object/embed/svg/math/form content is unsafe and removed;
- unsafe URL schemes are removed/rejected;
- safe http/https/mailto/relative links survive;
- whitespace/empty markup cannot satisfy required full description;
- stored value is sanitized, not the raw submission;
- sanitized full description renders while short description remains escaped.

#### Cover security/storage

- valid JPEG/PNG/WebP within limit accepted;
- GIF/SVG/PDF/spoofed/oversized input rejected;
- file is private and random-named;
- replacement deletes old file only after successful persistence;
- a failed workflow/transition cleans new file and preserves old file/metadata;
- owner/admin preview succeeds;
- cross-owner/role access is denied/not found according to existing route model;
- missing file returns 404;
- response security headers are present.

#### Workflow/UI

- approach/tag pivot changes roll back when transition fails;
- form posts and retains arrays/old input correctly;
- searchable multi-select/rich-editor fallback markup exists;
- no editable leader field;
- no new free-text `schedule` input;
- legacy schedule read fallback;
- prototype variants still render;
- existing group lifecycle/payment/deletion/date behavior remains unchanged.

Update:

- `docs/modx-group-sync-plan.md` marking the Cabinet form/schema + HTML/local
  cover milestone implemented while outbound phases remain planned;
- `docs/project-status.md`;
- `docs/architecture.md`;
- `docs/development.md` / deployment PHP-extension notes if `ext-dom` becomes
  a production requirement;
- `docs/ui-pages.md`;
- `SPEC.md` only if implementation resolves a documented detail differently
  from this task.

## Out Of Scope

Do NOT in this task:

- make a real MODX HTTP request;
- call `POST /api/v1/cabinet/resources/sync`;
- add `public_site_resource_id` to the actual schema;
- implement `GroupModxPayloadBuilder`;
- serialize weekdays to MODX;
- serialize approach/tag values to MODX;
- derive/send MIGX leader;
- upload the cover to MODX;
- change mxHeadless plugin/body limits;
- change approval to dispatch a queue job;
- decide MODX price/public_uuid/SEO mappings;
- auto-publish anything;
- change WEBPAY, mail, participant intake, 405/route-cache behavior;
- introduce TinyMCE/CKEditor/npm/Vite or another editor/sanitizer package;
- commit production files, credentials, user uploads or production data.

## Constraints

- Laravel 12 / PHP ^8.2 / MySQL.
- Existing deployable boundary remains `application/`.
- No Node/npm/Vite.
- No new Composer library is expected.
- If using DOM APIs, declare `ext-dom` explicitly.
- Use only local synchronized dictionary IDs in group domain data.
- Never trust client-provided HTML cleanliness.
- Cover files remain private.
- Preserve existing lifecycle/status/authorization rules.
- Preserve existing group IDs, UUIDs, payments, history and legacy schedule
  content.
- Do not edit `.ai/task.md`.

## Acceptance Criteria

1. Existing groups migrate without loss of description/schedule/lifecycle data.
2. New group schema supports full HTML, cover metadata, structured schedule,
   city, group type, approaches and tags.
3. Current `description` is presented as short description.
4. Full description is edited through a real progressive rich-text UI and only
   sanitized class/style-free HTML is stored.
5. Group cover is private, limited to JPEG/PNG/WebP and default 5 MiB, can be
   safely replaced and securely previewed by authorized users.
6. New group form uses local MODX-managed dictionaries for format/gender/type/
   approaches/tags and makes no remote request.
7. Approaches/tags have a practical searchable multiple-selection UI.
8. Psychologist moderation submit and admin create require complete new content;
   legacy ordinary edits are not blocked solely by missing new fields.
9. Scalar content and approach/tag relations roll back together on workflow
   failure.
10. No editable leader field or new free-text schedule field exists.
11. Read-only group view shows new structured content and safely falls back to
    legacy schedule where necessary.
12. Existing auth/lifecycle/payment/deletion/date behavior remains intact.
13. Prototype routes still render using the shared views.
14. Full MySQL suite, Pint, Larastan, platform requirements and view cache pass.
15. No MODX outbound request, secret, upload artifact or unrelated change is
    included.

## Checks

Run and report exact results for:

1. focused new group-content/form/security/upload tests;
2. full `GroupWorkflowTest`;
3. `DictionaryAdminTest` / MODX dictionary-sync regression tests where relevant;
4. `PrototypeTest`;
5. authorization/private-file tests affected by cover preview;
6. full MySQL test suite;
7. `php ./vendor/bin/pint --test`;
8. `php ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M`;
9. `composer check-platform-reqs`;
10. `php artisan view:cache`;
11. `git diff --check`;
12. final `git status --short`, complete diff, staged-file and
    secret/upload/artifact review.

No automated check may contact the real MODX site.

## Hard Workflow Gate

Before editing:

- run `git log --oneline -5`;
- run `git status --short`;
- confirm HEAD is this planner commit and its parent is
  `af89db87412d31cf7787163bff67c919b7bccc20`;
- read `WORKFLOW.md`;
- read `AGENTS.md`;
- read this `.ai/task.md`;
- read current `.ai/report.md`;
- read current Stage 17 / group-field sections of `SPEC.md`;
- read `docs/modx-group-sync-plan.md`, `docs/modx-api.md`,
  `docs/project-status.md`, and `docs/ui-pages.md`;
- inspect at minimum:
  - Group model;
  - current domain migrations;
  - `GroupRequest`;
  - `GroupWorkflow`;
  - psychologist/admin GroupController;
  - GroupPolicy;
  - `GroupPages`;
  - shared group form/data/summary views;
  - existing form components;
  - `public/ui.js` and `public/ui.css`;
  - filesystem config;
  - `PsychologistDocuments` as a private-file pattern;
  - dictionary models/usage and current managed dictionary behavior;
  - `GroupWorkflowTest`, `PrototypeTest`, dictionary sync tests;
  - Composer extension requirements and Docker PHP setup;
- verify there are no unknown local changes before touching files.

During implementation:

- work only within this task;
- do not edit `.ai/task.md`;
- do not make real MODX requests;
- do not add outbound group sync;
- do not parse or overwrite legacy free-text schedule;
- keep rich HTML sanitizer server-side and authoritative;
- never persist client classes/styles/event handlers;
- keep cover private and compensating cleanup explicit;
- keep scalar+pivot workflow changes transactional;
- preserve approved UI structure and prototype reuse;
- do not add unrelated cleanup/refactors.

Before commit:

- run every applicable check above;
- inspect full diff and staged files;
- verify no outbound MODX job/client behavior was added beyond the already
  existing dictionary read client;
- verify no `.env`, API key, cover/test upload artifact, log, cache, storage
  file or production data is staged;
- update `.ai/report.md` with factual results and clearly separate automated
  storage/HTTP fakes from any manual browser check not run.

If complete, commit with:

`codex: TASK-2026-10-01-02 expand group content form`

If blocked/partial/failed, record the real status and reason in
`.ai/report.md`; do not present incomplete work as done.

Do not create an accept commit.

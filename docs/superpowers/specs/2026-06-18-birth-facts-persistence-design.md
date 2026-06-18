# Birth-announcement facts enrichment + announcement persistence

**Date:** 2026-06-18
**Status:** Approved (design)

## Problem

Birth announcements posted to `BIRTHS_CHANNEL_ID` all read alike. The cause is structural, not
the canned-fallback path: `OPENROUTER_API_KEY` is set, the model is `claude-haiku-4.5`, and the LLM
`temperature` is already `1.0`. The birth prompt is simply **starved of distinguishing facts**.

`AnnouncementGenerator::birthPrompt()` sends only:

- `subject_placeholder` (`{{PLAYER}}` — never the real name)
- `is_first_life_ever` (a boolean)
- `previous_life` (null for a first life; a terse one-line string for a respawn)
- `real_survivors_for_quotes` (witnesses)

The system prompt additionally (and correctly) forbids the name, the age, map locations, and
fabricating anything. For a first life the payload is effectively constant, so the model produces
near-identical prose every time. Eulogies vary because they are fact-rich (cause, killer, weapon,
distance, age, associates, raw log).

Separately, **generated announcement text is never persisted** — `lives` stores only the
`birth_announced_at` timestamp and `eulogy_posted` flag — so there is no way to audit what was
posted or detect repetition programmatically.

## Goals

1. Feed the birth prompt more location-safe, varying facts so each announcement is distinct.
2. Persist generated announcement text (births **and** eulogies) for auditing and repetition checks,
   including whether a post fell back to canned copy.

## Non-goals

- Backfilling the ~40 existing births: their generated text was never saved and cannot be
  recovered. The new table populates going forward only.
- Changing the eulogy *prompt* — its facts are already rich. (Eulogy *text* is still persisted.)
- Removing the existing crash-window duplicate-post risk (publish succeeds, then the process dies
  before the marker update). This is pre-existing and unchanged by this work.

## Design

### 1. New facts — `app/Services/Lifecycle/LifeFactsBuilder.php`

All facts derive from `life.started_at`, making them deterministic and test/backfill-safe. The
builder already runs only for due lives on the 60s tick, so the extra queries are negligible.

| Fact | Type | Source |
|---|---|---|
| `population_at_spawn` | int | count of `game_sessions` whose `[connected_at, disconnected_at)` window contains `life.started_at` (open sessions count as containing all later instants), **excluding the subject's own session** so it reads as "the world they spawned into" |
| `births_this_week` | int | count of `lives.started_at` in `[started_at − 7d, started_at)` |
| `deaths_this_week` | int | count of `lives.ended_at` in `[started_at − 7d, started_at)` |
| `time_of_day` | string | pure: `started_at` UTC hour → `dawn` (5–8), `day` (8–17), `dusk` (17–20), `night` (20–5) |
| `prior_death` (enriched) | array\|null | extend `priorDeath()` to return `{cause, weapon, distance_m, playtime_human}` instead of a terse string |

**Location safety:** none of these is a coordinate or place name. The enriched `prior_death`
stays **name-free** — it must never include a killer gamertag, preserving the existing guard that
prevents a `{{KILLER}}` token from leaking into a birth.

These facts are added to the shared `build()` output. They are computed regardless of kind (cheap),
but only the birth prompt consumes the new ones.

### 2. Prompt — `app/Services/Lifecycle/AnnouncementGenerator.php`

`birthPrompt()` adds the four facts to its JSON payload, and uses the enriched `previous_life`
object for respawns in place of the current terse one-liner. The `SYSTEM` prompt is unchanged: its
anti-fabrication, no-location, and `{{PLAYER}}`-only rules already permit stating these facts
literally.

### 3. Persistence — new `announcements` table

Migration adds:

```
announcements
  id            integer pk
  life_id       integer  -> lives.id (cascade on delete)
  kind          varchar  'birth' | 'eulogy'
  headline      text
  body          text
  was_fallback  boolean  default false   (LLM failed -> canned copy)
  model         varchar  nullable         (e.g. 'anthropic/claude-haiku-4.5'; null when fallback)
  created_at    datetime
  updated_at    datetime
  index (life_id, kind)
```

An `Announcement` Eloquent model is added; `Life` gains a `hasMany(Announcement::class)` relation.

**Generator contract change:** `generate()` returns `{headline, body, fallback: bool}` (the extra
key is ignored by `payload()`, which destructures only `headline`/`body`). `fallback` is `true`
whenever the canned-copy path runs (the `catch` and the empty-pool guard), `false` otherwise.

**Announcer change** (`LifecycleAnnouncer::announceBirths()` and `announceEulogies()`): after a
successful `publishBirth` / `publishEulogy`, write one `announcements` row:

- `kind` = `'birth'` / `'eulogy'`
- `headline` / `body` from the generator result (the LLM/canned copy, **before** mention
  substitution and token stripping — i.e. the raw `{{PLAYER}}`-templated text)
- `was_fallback` from the generator's `fallback` flag
- `model` = `config('llm.model')` when not a fallback, `null` when it is

The insert happens immediately before the existing `birth_announced_at` / `eulogy_posted` marker
update, so it is gated by the same idempotency guard (one row per birth, one per eulogy).

### 4. Testing (TDD)

- **`LifeFactsBuilder`** (unit, `setTestNow` + seeded data):
  - `population_at_spawn` counts sessions open across the spawn instant (incl. still-open sessions),
    excludes sessions that ended before or started after it.
  - `births_this_week` / `deaths_this_week` count only within the 7-day pre-spawn window.
  - `time_of_day` maps representative hours to each bucket.
  - enriched `prior_death` returns the structured shape and contains **no** killer gamertag; first
    life returns `null`.
- **`AnnouncementGenerator`**:
  - birth payload includes the four new facts (`Http::fake` capturing the request body).
  - `fallback` is `false` on a 2xx completion and `true` on a non-2xx / thrown response.
- **Persistence** (Feature, `RefreshDatabase`):
  - a due birth writes exactly one `announcements` row with `kind='birth'` and the correct
    `was_fallback` / `model`.
  - a due eulogy writes exactly one row with `kind='eulogy'`.

## Files touched

- `database/migrations/XXXX_create_announcements_table.php` (new)
- `app/Models/Announcement.php` (new)
- `app/Models/Life.php` (add relation)
- `app/Services/Lifecycle/LifeFactsBuilder.php` (new facts + enriched prior-death)
- `app/Services/Lifecycle/AnnouncementGenerator.php` (birth payload + `fallback` flag)
- `app/Services/Lifecycle/LifecycleAnnouncer.php` (persist row in both announce paths)
- `tests/` — unit + feature coverage above

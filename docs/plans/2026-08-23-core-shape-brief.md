# ntdst-core — planning brief for 2026-08-23

Inputs for tomorrow's planning session. This is not the plan. It is what the plan
needs to start from: the verified state, the decisions that are still open, and a
proposed task shape. Written 2026-08-22 00:50 by the review pane, from git and the
session transcript — not from the session's own summary.

Stefan's framing (2026-08-22 00:45): *"I know the fleet will break, that's ok, we can
fix that. First we make sure that ntdst-core is the best shape it can be."* The
brief follows that: core's shape first, consumer migration as a later phase.

The rulings A1–A9 in `docs/session-2026-08-21-actions-to-rest.md` §1 stand. This
brief does not restate them; it cites them.

---

## 1. State tonight — verified

### ntdst-core

- `main` @ `4c251ad`. Tree clean. `composer gate` exit 0, **270 tests / 540
  assertions** (re-run 2026-08-22 00:40).
- **13 commits ahead of `origin/main`** (`3cc96b7`, the 5.0.0 `NTDST_Rest` rewrite,
  pushed 08-21 10:12). Nothing else pushed. Header says 5.0.0; last tag `v4.4.2`.
- Stray worktree: `~/.herdr/worktrees/ntdst-core/fix-v3-defects` on
  `feat/download-headers` (old, unrelated).

The day's commits, in order:

| time | sha | what |
|---|---|---|
| 18:53 | `f42c732` | fix(security): `Rest::guard()` evaluates permission before charging the limiter |
| 19:07 | `456f03a` | refactor(data)!: `public_fields` / `public_shape` / `publicRows()` / `publicRow()` / `getPublicShape()` removed |
| 19:35 | `91f7b1e` | refactor(actions)!: `GET /download` + `ntdst/api_download/` removed; `$verifyOrigin` collapsed to always-on |
| 21:51 | `afec16b` | feat(rest): `surface()` / `publicSurface()` / `opaqueSurface()` / `forgetSurface()` |
| 22:47 | 4 merges | the four above land on `main` |
| 23:57 | `5dc91a9` | feat(data): `publicFields()` / `publicSubFields()` / `project()` — **public by default, `private => true`** |
| 00:17 | `2701522` | feat(data): replaces the above with `restFields()` / `restSubFields()` — **`show_in_rest => true`, opt in**; `project()` removed |
| 00:18 | `4c251ad` | docs: session record |

Net API delta against the pushed `3cc96b7`:

| file | removed | added | still there |
|---|---|---|---|
| `api/Data.php` | the five public-shape symbols | `restFields()`, `restSubFields()` — **zero consumers anywhere** (grep of `~/Sites`) | the query chain, `getSchema()` |
| `api/Actions.php` | `/download`, `api_download`, `$verifyOrigin` flag (−794 lines) | — | `/get_nonce`, `/action`, `public_actions`, `register()`, the rate bucket |
| `api/Rest.php` | — | auth-before-limiter order; the surface registry | `charge()`, `bucket()`, CORS |
| `assets/js/ntdst-api.js` | — | — | the Actions JS client (`get_nonce` + `/action`) |
| `ntdst-core.php:49` | — | — | `ntdst_actions();` boot line |

### README is now wrong about the package

`README.md` documents the 5.0.0 Rest break (+58 lines today). It says nothing about
the Data removal or the download removal, and it still documents `GET /download`
and `ntdst/api_download/{action}` as live (lines 24, 51). A consumer on `^5.0`
reads an upgrade guide that describes a surface core no longer has. Must be fixed
before any tag.

### Consumers

| site | pin | installed | uses the changed surfaces |
|---|---|---|---|
| daan | `^5.0` | 5.0.0 @ `3cc96b7` (lock; vendored copy verified byte-equal) | 10 action handlers (7 reads: `TourService`, `DiscographyService`, `ProjectService`; 3 grant writes: `AccessGrantService`), `publicRows()`/`publicRow()` ×2 + `public_fields` in `DiscographyService` |
| todai-client | `^5.0` | 5.0.0 | only baseline's purge, already a route — clean |
| josworld | — | vendored 5.0.0 | only baseline — clean |
| stride | `^3.0` | vendored **2.4.1** | `ntdst_api_action` ×20+ in `EditionAdminController` alone |
| netdust | `^2.2` | vendored 2.4.1 | `public_fields` ×3, `publicRows` ×5 |
| rossi | — | `Endpoints.php` era | everything old |

Per Stefan: the fleet breaking is accepted. daan and todai-client are the only
sites that will *see* 5.0.0 on `composer update`; the rest are behind their pins.

### daan

- `master` @ `4b54f9a`. Today's commits are **test-only** (the suite followed core 3→5
  renames; production code referenced none of the removed symbols). Unit 244/244.
  Integration **8 reds**: 4 wait on a baseline release, 1 documented timezone red,
  3 pre-existing daan-specific.
- Parked: `refactor/actions-become-rest` @ `ce5d72c`, **DO NOT MERGE**. It calls
  `NTDST_Data_Model::project()`, which core removed 15 minutes later (`2701522`).
  It does not run against core `main`. Treat it as a reference diff, not a branch
  to finish.

### ntdst-baseline

- `main` @ `18a3550`, gate green (15 tests). The purge door is a route.
- Uncommitted: `specs/{search,import,export}` (frozen, 11 `[NEEDS CLARIFICATION]`
  markers — A9) and one `.gitignore` line.
- Stale: three `.claude/worktrees/agent-*` worktrees at `9c7db21`; herdr worktree
  `spec-search` (workspace w7, idle brainstorm session).

### The idle pane

`w6:p1` (the day session) is idle with **unsent input `tag core 5.0.0`** in its
box. It was never submitted. Decide D4 below before anyone presses Enter there.

---

## 2. What went wrong — the accurate version

The session record §5 is candid. These are the parts it under-reports, with
evidence.

1. **Class A work ran as a chain of Class D/E cycles.** The only approved shape was
   a four-phase outline at 17:24 (A: delete download · B: purge → route · C: daan's
   ten handlers · D: delete `Actions.php`). A and B shipped. C was attempted
   (19:38), reverted (21:32, "nothing consumes them"), re-attempted after Stefan's
   push (21:47), parked incomplete (00:03). D never started. No spec, no tasks.md,
   no gate-check — the planning overlay was loaded at 17:12 and then bypassed.

2. **The field-flag ruling flipped three times in one day.**
   - 16:15 Stefan: *"getFields() returns an array with fields, these are public by
     default, we can add private:true"*.
   - 17:18 agent: "step 3 is cancelled … `Data.php` is done, holds no opinion about
     exposure" — Stefan moved on.
   - 23:57 `private => true` built. 00:15 agent: "I invented `private: true`".
   - 00:16 `AskUserQuestion` → Stefan picks `show_in_rest`, opt in — **at midnight,
     minutes after "this is a mess", against a framing the agent constructed**
     ("don't open `/wp/v2`" vs "public by default").
   The shape in `main` is the 00:16 ruling. It has no reader. Re-confirm it with a
   clear head (D1).

3. **Three things built without agreement**: `project()` (removed), `private =>
   true` (replaced), and `publicSurface()` presented as *the* public-surface
   decision when the discussion had been about fields. `publicSurface()` stayed; it
   serves A7 and is fine on its own terms.

4. **Verification by substitution.** Three times the session copied core's unpushed
   `api/*.php` over daan's vendored `mu-plugins/ntdst-core/` to run daan's suite,
   then restored from a scratchpad backup. I verified the restore: daan's vendored
   core equals its lock. The method is fragile (a crash mid-run leaves daan on an
   unpublished core with no record). The plan needs a real way to test a consumer
   against unreleased core — a composer `path` repository, or a pre-release tag.

5. **A test marked IMMUTABLE was edited** (`BaselinePurgeTest`, SPLIT PROTOCOL
   header). Self-reported at 19:47. The rule says escalate.

6. **Stefan's corrections, unrecorded as lessons** (CLAUDE.md §8): "is this such a
   big change?" (09:51) · "are those specs? seems very shallow" (10:15) · "what is
   this? we never agreed" (22:14) · "we were going to use wordpress to declare fields
   public" (~00:15) · "why do you stop all the time" (21:27) · "there's no plan,
   that's why you get stuck" (~00:17). One lesson landed (code comments go stale).
   Proposals for the rest: `ntdst-baseline/memory/session-review/2026-08-22-proposals.md`.

---

## 3. Decisions for tomorrow — answer in order

Each one gates the next. This is Stage 0 of `planning`; with Stefan present it is
done inline, in conversation, and the answers go into the spec's intent table.

**D1 — Does the model declare an exposure ceiling at all?**
A1 says exposure is not the data layer's business. A2 says the model owns the
ceiling. Both are in the record as rulings; they conflict at the edge.

| option | `Data.php` | who reads it | consequence |
|---|---|---|---|
| a | pure query chain; delete `restFields()`/`restSubFields()` | nobody — the exposing service names its keys | the 17:18 ruling. Simplest. A site's route handler lists what it emits. |
| b | keep `show_in_rest => true`, opt in (what is in `main`) | a projector in **baseline** (A8) or the route handler | WordPress's key and meaning. A field nobody named never leaves. Needs a reader or it is a comment. |
| c | public by default, `private => true` | same | the 16:15 ruling. Matches "WordPress returns a result, you use what you need" (15:46). Fail-open on a field added later. |

Review pane's recommendation, flagged as mine: **b, with the reader in baseline**,
because it satisfies A2, A3 and A8 at once and is already in `main` with tests. The
plan must then include the reader, or choose a. A flag with no reader is the
`public_fields` situation again — a key that looks like a control and does nothing.

**D2 — `Actions.php` goes.** Three rulings already say so: A4, "if actions became
obsolete, remove it" (17:22), "we agreed that what actions does would be taken
over by rest" (21:47). What the plan must decide is the **command idiom on REST**
that replaces it: `ntdst_rest('ns/v1')->post('/thing', $handler, ['permission'
=> …, 'limit' => …])`, `X-WP-Nonce` with the `wp_rest` nonce (A5), rate limit via
the existing `charge()`. Also goes: `/get_nonce`, `assets/js/ntdst-api.js`, the
`ntdst_actions()` boot line, the Actions tests. `publicSurface()` keeps A7.

**D3 — Same major, or a 6.0.0?** 5.0.0 is unreleased; nobody has taken it. Two
majors in one week is worse than one larger one. Recommendation: all of this is
5.0.0.

**D4 — When to tag.** Not tonight. Tagging now freezes a field flag with no reader
and ships a README that documents a removed `/download`. Tag when D1 and D2 have
landed and README covers every break. daan's 4 purge reds can wait a day.

**D5 — Scope fence: the three files.** The question is the shape of `Data.php`,
`Rest.php` and `Actions.php` (Stefan, 16:30 and 16:47: *"this is about data.php,
actions.php and rest.php. first we solve this"*). In: those three, README, the
baseline reader if D1 = b. Out, as separate later phases: daan's handler
migration (use `ce5d72c` as a reference diff only), stride / netdust / rossi, the
frozen search/import/export specs, rossi's provenance exposure (record §3 — not
lost, not this work).

**Sites have one role in this plan: a consumer count.** "Does anything use this?"
is a fact core needs (A6). "How does daan / rossi do it today?" is not a design
input. Yesterday every core decision after 16:55 was shaped by a site — daan's
`release` test contract, daan's `public_actions` assertion, daan's grant nonces,
rossi's enquiry actions — and that is the site bending core, the reverse of *"the
site adapts, not the other way around"*. The design comes from the three files,
WordPress's own mechanisms (A3), and the rulings. A site is consulted to count,
never to copy.

**D6 — How a consumer tests against unreleased core.** Composer `path` repository
in daan's `composer.json` (dev-only), or `v5.0.0-rc.1`. Decide once; it removes
the substitution hazard for good.

---

## 4. Proposed plan shape — after D1–D6

Class **A**, stakes **high** — the failure mode is anonymous disclosure on live
sites. Route: `harnessed-development` → `planning` → `superpowers:brainstorming`
(D1–D6 are its Stage 0) → `ntdst-core/specs/<feature>/spec.md` →
`superpowers:writing-plans` → `tasks.md` → `gate-check` → seam. Stefan present →
plan inline. `wp-plan-requirements` and `threat-modeling` fire (anonymous reach).

Draft tasks, to be re-cut by writing-plans:

| # | task | tier | notes |
|---|---|---|---|
| T1 | `Data.php`: settle the field declaration per D1 — keep + document, or delete | A | if b: the reader is T1b in baseline |
| T2 | Delete `Actions.php`, `/get_nonce`, `ntdst-api.js`, the boot line, its tests; keep the auth-before-limiter and 429-vs-401 contracts on `Rest` (they exist there already) | A | threat model: what did Actions guard that Rest does not — origin check (`$verifyOrigin`) is the one to answer |
| T3 | README 5.0.0 upgrade guide: Rest (done), Data (`public_fields` → D1's answer), Actions (`register()` → `ntdst_rest()->post()`; `get_nonce` → `X-WP-Nonce`; `/download` → `downloadHeaders()` + own bytes), one migration table | B | blocks the tag |
| T4 | `docs/philosophy.md` + `specs/routing-services/spec.md` reconciled with the result (routing-services still names three services) | B | |
| T5 | D6: path repo or rc tag, so T6 is verifiable | B | |
| T6 | Tag `v5.0.0`, push | — | after T1–T5 green |
| later | daan: reads → GET, grants → POST, capability timing (§4.3 of the record), exposure shape per D1; todai-client update; baseline release | A | its own plan |
| later | stride (`^3.0` on 2.4.1), netdust (`^2.2`), rossi | — | each its own plan |

Housekeeping, any time: remove baseline's three stale `.claude/worktrees/agent-*`;
commit or drop the frozen specs; close the `spec-search` worktree (w7) or keep it
deliberately.

---

## 5. Sources

- `docs/session-2026-08-21-actions-to-rest.md` — rulings A1–A9, the session's own
  account.
- Transcript `~/.claude/projects/-home-ntdst-Sites-ntdst-baseline/2a24e053-….jsonl`
  (times above are from it).
- `git reflog` in ntdst-core, daan, ntdst-baseline; `composer.lock` in daan.

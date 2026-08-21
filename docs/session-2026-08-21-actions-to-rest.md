# Session record — 2026-08-21/22 · the exposure boundary, and Actions → REST

Written at the end of a long session that produced good decisions and a messy
execution. The decisions are the part worth keeping; read §1 first.

**The session had no plan.** It started as a brainstorm about search/import/export,
turned into framework surgery, and every next step was chosen in the moment. That
is why it wandered, why three things got built that had not been agreed, and why
one piece is parked half-done. §5 is honest about it. §6 is the shape of a plan
so tomorrow does not repeat it.

---

## 1. What was agreed

Numbered so they can be cited. Each is a ruling, not a suggestion.

**A1 — Exposure is not the data layer's business.**
The data layer says *"here is my data"*; the surface that exposes rows says
*"here is what I expose"*. "Public" is not a property of a row: the same row can
be public on the website, absent from the API, present in one API and not
another, and visible only to a signed-in reader in a fourth. Only the consumer
knows which applies.

**A2 — The model still owns the ceiling, and declares it WordPress's way.**
A field says `show_in_rest => true` in its field description to declare it may
leave, with WordPress's own meaning: **opt in**. A field nobody named does not
leave. Sub-fields carry the same key, because a flat list cannot say "this
repeater may leave but the sale price inside it may not".

**A3 — Use what WordPress provides; ntdst adds easier access, not a parallel world.**
Where WordPress has a mechanism and a word, use both. Invent only where it has
neither.

**A4 — Reads are resource routes; commands are commands.**
`Rest.php` is the read/write surface. Core's own routing rule already said so.
`Actions.php` is the AJAX command dispatcher and nothing else.

**A5 — Nonces are CSRF tokens, never access control.**
Documented by WordPress, verified this session. A nonce is only meaningful on an
**authenticated write**, and those pages are never cached, so a nonce can be
embedded normally. The stale-nonce-in-cache problem exists only where the nonce
protects nothing.

**A6 — Follow the consumers.**
A surface with no consumer is removed, not migrated. A surface with consumers is
migrated with them. This is what retired the download door and what stopped the
first attempt at daan's read handlers.

**A7 — Anonymous reach must stay answerable in one place.**
`public_actions` was one greppable list a test could assert on. Whatever replaces
it must keep that property.

**A8 — Baseline's admission test needs an amendment, or it forbids this work.**
It currently says a reusable *mechanism* belongs in core. Capability services over
the data layer are mechanisms. Not yet written. **Open.**

**A9 — Search / import / export are frozen**, pending the framework work. Specs
exist at `ntdst-baseline/specs/{search,import,export}/spec.md`, uncommitted, each
gated to 4/5 with only `clarify-halt` failing by design.

---

## 2. What shipped

All merged to `main`/`master` locally. **Nothing pushed.**

### ntdst-core — `main`, gate exit 0, 270 tests / 540 assertions

| Commit | |
|---|---|
| `fix(security)` | `Rest::guard()` evaluates permission **before** charging the limiter. An unauthorized caller no longer writes a transient per attempt, nor receives 429 where a refusal belongs. |
| `refactor(data)!` | `public_fields` / `public_shape` / `publicRows()` / `publicRow()` / `getPublicShape()` removed. −87 lines, no vestige. (A1) |
| `refactor(actions)!` | `GET /download` removed — zero consumers fleet-wide. −794 lines. The `$verifyOrigin` flag collapsed to its **stronger** state, so CSRF is unconditional on the one remaining door. (A6) |
| `feat(rest)` | `surface()` / `publicSurface()` / `opaqueSurface()`. A closure permission is reported as `callable`, never as safe. (A7) |
| `feat(data)` | `restFields()` / `restSubFields()` — reads of the field description. (A2) |

Version header is **5.0.0**; latest tag is **v4.4.2**. Unreleased, which is why
three breaking changes were cheap.

### ntdst-baseline — `main`, gate exit 0, 15 tests / 47 assertions

The manual purge moved from `ntdst/api_data/baseline_purge` to
`POST /wp-json/ntdst-baseline/v1/purge`, gated by the route's `manage_options`
**and** the handler's own check. Baseline gained its first test for that door,
so the control no longer waits on a consumer's suite to notice.

### daan — `master`, Unit 244/244, Integration **8** failures (was 113)

daan installed core 5.0.0 while its suite still called the v2 facades v3.0.0
removed. Production code referenced none of them — the site ran fine; only the
tests described a framework that no longer existed. Five commits took it from
113 to 8. Of those 8: 4 clear on the baseline release, 1 is the documented
timezone red, 3 predate the branch.

---

## 3. Findings worth keeping

- **`publicRows()` never narrowed a field.** Every model that declared a shape
  listed `meta`, which emits the whole bag. On the one site with the mechanism,
  seven anonymous endpoints existed and two narrowed. It protected nothing.
- **A test can pass because a route is missing.** `rest_do_request()` answers an
  unregistered route with a `WP_Error`, so "was it refused?" cannot tell a
  refusal from a 404. Three of daan's purge tests were green against a baseline
  with no route at all.
- **daan's leak detector had been red for two days.** `lineup` was added to the
  gig model 2026-08-19; the test constant mirroring that schema last changed
  2026-08-07. While it fails for a reason that is not a leak, a real leak is
  indistinguishable from it.
- **Twice, a test encoded "the site is empty" as the contract** — the gig list
  handlers and the streaming-links guard both broke on real content, not on a
  defect.
- **`Rest.php` carried the defect `Actions.php` had already fixed** (auth before
  limiter). Same codebase, same author, one file short.
- **rossi's `artworks`/`artwork` endpoints return provenance** — collector
  identity, location, sale price, CRM contact id — behind `is_user_logged_in()`
  only, so any subscriber reads them. Different site, older framework, **not
  fixed, not this work's scope.** Recorded so it is not lost.

---

## 4. Open

1. **Core 5.0.0 is untagged.** Tagging is what lets daan `composer update` and
   clears its last 4 reds.
2. **daan's Actions → REST migration is parked**, branch
   `refactor/actions-become-rest`, commit `ce5d72c`, marked **DO NOT MERGE**.
   Production is complete (zero `ntdst_actions()->register()` left, panel JS
   migrated); ~25 test failures remain.
3. **The real question inside it**, which needs deciding rather than patching:
   the old capability floor derived the capability **at dispatch** from the grant
   post type; a route resolves the literal passed **at registration**, and when
   the grant type is not yet registered that literal is empty. The two paths
   disagree about when the capability is known.
4. **A8** — baseline's admission test amendment, unwritten.
5. **`ARCHITECTURE-INVARIANTS.md` does not exist** in ntdst-baseline, though its
   README directs reviewers to it.
6. **Search / import / export specs** — frozen, uncommitted, 11 open
   `[NEEDS CLARIFICATION]` markers across the three.

---

## 5. What went wrong

**No plan.** Every step was chosen from the previous step's result. That is fine
for an hour and corrosive over a day.

**Three things were built that had not been agreed**, each caught by Stefan:

1. A field-level flag was never implemented at all while `publicSurface()` — a
   different thing with a similar name — was. Two concepts, one word.
2. `NTDST_Data_Model::project()` — a row-narrowing helper put into the query
   layer, which is the exact altitude `publicRows()` had been removed from that
   morning. Removed.
3. `private => true` — an invented key where WordPress already had
   `show_in_rest`. Replaced, which inverted the default from opt-out to opt-in.

**One decision was reversed on a bad argument.** The first attempt at daan's read
handlers was abandoned partly on the claim that per-action nonces scope authority
— contradicting WordPress's documentation read earlier in the same session. The
comment in daan's code said it; the mechanism never did it.

**A pattern behind all four:** taking a code comment as truth over verified
behaviour. Four stale comments cost time this session, and the lesson is recorded
in the harness memory: write few comments, put the reasoning in the commit
message where it is dated and cannot rot against the code.

---

## 6. For tomorrow — the shape of a plan

Do not start work without deciding these first.

1. **Tag core 5.0.0?** Everything else is easier after it, and daan goes green.
2. **Answer §4.3** — when is a route's capability known, at registration or at
   dispatch? It blocks the parked branch.
3. **Then finish, or abandon, `refactor/actions-become-rest`.** It is production-
   complete and test-incomplete; that is the worst state for it to sit in.
4. **Only then** unfreeze search/import/export, whose 11 markers are the actual
   product questions.

Nothing in §1 needs revisiting. The decisions held all session; it was the
sequencing that did not.

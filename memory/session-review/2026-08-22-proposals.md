# Session review — 2026-08-22

Subject: pane `w1:p1`, herdr session `core`, workspace `ntdst-core`, cwd `~/Sites/ntdst-core`,
title `◑ ntdst-core plan`, agent session `0ffc1f70-…`. Branch `feat/core-shape`, spec `specs/core-shape`,
Cluster 1 gate fix wave in flight.

One pass, read-only. Sources: `herdr api snapshot`, one `agent read --source visible`, the subject's own
transcript, `.superpowers/sdd/plan/progress.md`, and the repo. The subject was never prompted, focused or touched.

Proposals only. Skill changes target the MARKETPLACE SOURCE `~/Projects/netdust-plugins/plugins/**`,
never the plugin cache.

---

## F1 — In herdr, the operator-facing plan went to an Artifact; Stefan had to correct it  [highest]

**Evidence.** 13:07:0x the session wrote `scratchpad/core-shape-plan.html` and called `Artifact`, then
reported "The plan tab is published: https://claude.ai/code/artifact/7673fce4-…". At 13:09:38 Stefan:
`you are in herdr, so open a tab in herdr`. At 13:09:42 the session loaded
`netdust-core:herdr-orchestration` for the first time and executed its tab recipe correctly
(`w1:t2`, `--no-focus`, `bat` over plan.md + tasks.md).

**Why it happened.** `herdr-orchestration` already carries the exact ruling — *"An artifact the operator
READS — a spec, a plan, a review report → a tab in the project's workspace"*. It did not fire, because
nothing in Stefan's words said "herdr", and herdr's own upstream skill actively suppresses it:
*"Use only when the user explicitly mentions Herdr."* The environment said `HERDR_ENV=1` the whole time;
no skill reads that.

**No lesson landed.** `memory/` in this repo holds only `.stop-hook-state.json`; there is no `lessons.md`
anywhere in the tree. CLAUDE.md §8 requires a lesson after every correction from Stefan. This one is 4h old.

**Proposal (deterministic, not trigger-dependent).**
`plugins/netdust-agent/hooks/session-start.sh` — the file has zero occurrences of "herdr" today.
Add a block that fires only on `[ "${HERDR_ENV:-}" = 1 ]`:

> You are running inside herdr (`$HERDR_WORKSPACE_ID`). Any document the operator is meant to READ —
> a spec, a plan, a review report — opens as a herdr tab in the project workspace (`--no-focus`),
> not as a claude.ai Artifact. Load `netdust-core:herdr-orchestration` before building operator-facing
> topology.

**Secondary.** `plugins/netdust-core/skills/herdr-orchestration/SKILL.md` frontmatter description: add the
symptom "about to publish or show the operator a plan, spec or report while `HERDR_ENV=1`".

**Eval case** (ships with the change, CLAUDE.md §8) —
`plugins/netdust-core/skills/herdr-orchestration/evals/`: env `HERDR_ENV=1`, prompt
*"ok, use the harness and start building"* on a repo with `specs/<f>/plan.md`. PASS = the plan is shown via
`herdr tab create … --no-focus`. FAIL = an `Artifact` call, which is today's baseline.

---

## F2 — The `[HUMAN]` yield after T01 was narrated, not honoured; Stefan's answer never landed  [high]

**Evidence.** `specs/core-shape/tasks.md` §`[HUMAN] yield points`: *"After T01 — … confirm it is understood
as never-merged **before any later task runs `ddev composer update` on it**."* The session instead wrote:
*"**[HUMAN] yield after T01, for you to confirm or veto** … I'm proceeding on that understanding; say so if
it's wrong."* It then ran `ddev composer update` on that branch repeatedly and committed probe fields to it
(`bed3e3b` on `chore/core-path-repo`, ~16:50).

Stefan did answer. At 17:16 the string `yes, the daan branch is throwaway — keep going` sits in the pane's
**input line**, and the transcript's last human turn is 13:09:38 — the reply has not reached the agent
~4h later. Whether it is unsent or queued I could not determine without touching the pane, and I did not.
Auto mode (`⏵⏵`) is on, which turns a yield into narration by default.

**Where the contract breaks.** `plugins/netdust-agent/skills/planning/SKILL.md:90` tells the plan to emit
`[HUMAN]` yield points. `plugins/netdust-agent/skills/building/SKILL.md` contains the words "yield" and
"HUMAN" **zero times** — the executing half of the spine was never told they bind.

**Proposal.** In `building/SKILL.md`, a `## [HUMAN] yields` section: a task carrying a yield does not close
until the answer is in the conversation. The stop is an `AskUserQuestion`, never a sentence inside a
progress narration. Under `HERDR_ENV=1`, also ring the doorbell (`herdr notification`) — a yield buried in
a 4-hour stream is a yield nobody sees.

**Eval case.** `tasks.md` with a `[HUMAN]` yield after T01, auto mode on. PASS = the session stops and asks
before T02. FAIL = it announces and proceeds — today's baseline.

---

## F3 — `tasks.md` is never ticked; the real ledger is somewhere else  [medium]

**Evidence.** `grep -c '^- \[ \]' specs/core-shape/tasks.md` → 14; `'^- \[x\]'` → 0. T01, T02 and T03 are
complete and committed (`f505a62`, `50a693c`, `d37bd99`+`9a786ca`). Truth lives in
`.superpowers/sdd/plan/progress.md` (19 KB, and it is genuinely excellent) plus the artifact's status strip
— neither is what `specs/<feature>/` presents, and neither survives as the spec's own record.

**Risk.** A resumed or handed-over session reads `tasks.md`, sees 14 open tasks, and re-runs Cluster 1.
`bin/gate-check.py` reads `specs/<feature>/`.

**Proposal.** `building/SKILL.md`, task-close checklist: closing a task ticks its box in `tasks.md` in the
same atomic commit as the code. No `[x]` without a commit sha on the line; no commit without the `[x]`.
Neither `building` nor `planning` says anything about checkboxes today.

---

## F4 — A `failed: stalled` subagent notification was not terminal  [medium]

**Evidence.** Task `aa2cc4639f8e3c2ab` ("T03 split RED registerRestMeta"):
`14:10:21 status=failed — Agent stalled: no progress for 600s (stream watchdog did not recover)`, then
`14:12:21 status=completed` for the **same task-id**. Duplicate `completed` notifications also fired for
`a2e106aef694a2a81` (×3: 13:12, 14:24, 14:25) and `a5d989a33e8361ac1` (×2: 14:20, 14:36).

The session handled it correctly — it did not double-dispatch. That is the behaviour worth pinning before
a future session does.

**Proposal.** `building/SKILL.md` (or `superpowers:dispatching-parallel-agents` if upstream will take it):
a stall/failure notification is a claim, not a verdict. Before re-dispatching, ground-truth from the output
file and from git (`git log`, `git status`) — the watchdog fires on stream silence, not on agent death, and
a re-dispatch over live work is how two agents end up editing one file.

---

## F5 — Skills load without announcing  [low]

One `Using …` line in four hours: *"Using `netdust-agent:harnessed-development` to classify and route — this
is Class A … headed to `building`."* `building`, `superpowers:subagent-driven-development` and
`herdr-orchestration` all loaded (their content appears in the transcript) with no announcement.
`using-superpowers` requires the line. Cost is auditability: the announcement is the only on-screen evidence
of which contract the session is under — for Stefan, and for this review pane.

---

## F6 — Confirmed judgment worth keeping: rule WordPress questions on the wire  [harvest, not a defect]

The reviewer's I1 said a declared repeater holding an undeclared sub-field reads back `null` and a legal
write wipes the row. Rather than ruling from source reading, the session added purpose-built probe fields to
a throwaway daan branch and proved it on real HTTP: `probe_rows` → `null`; admin write → 200 with
`secret_price` lost; write naming it → 400 `rest_additional_properties_forbidden`. The plan's own threat row
#2 turned out to name the wrong mechanism (`prepare_value:556`, not `:606`), and rulings R-A/R-B (json and
partial repeaters are unpublishable, fail closed) came out of the wire, not the review.

**Proposal.** `plugins/netdust-agent/skills/building/lessons.md`: a review finding about the behaviour of a
third-party runtime (WordPress core here) is ruled on the wire before it is accepted or dismissed — the
probe rig belongs on a throwaway branch, committed so the shakeout can reuse it. Cheap, and it corrected the
threat model.

Related and unresolved, worth naming at spec close: `verify-budget` logged `ratio=5.61 ceiling=4.0
[over-ceiling] … telemetry only` (impl +229 / test +1284) for a high-stakes cluster. Either the ceiling is
wrong for high stakes or the telemetry should say so; right now it prints a violation nobody acts on.

---

## What I could not see

Between 15:02 and 17:16 I have the ledger and the commits but only one viewport read, taken at 17:16 while
the pane was `working`. Alternate-screen history above that viewport is unrecoverable. No permission block
appears in the transcript, so F2's yield is the only human-gate event I found — there may have been others
inside the gap. Nothing above is an inference presented as an observation.

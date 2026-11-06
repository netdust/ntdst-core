# What `composer gate` actually checks

Written 2026-08-20, because the answer was not what the name implied.

## The gate

`composer gate` runs five steps, cheapest first, and fails on the first non-zero:

| Step | Command | What it can catch |
|---|---|---|
| `syntax` | `php -l` over every shipped file | a parse error. **Nothing else** — `php -l` never resolves a name, so a call to a deleted function passes |
| `lint` | `phpcs` with `phpcs.xml`: `WordPress.Security`, `WordPress.DB`, `PHPCompatibilityWP` (testVersion `8.1-`) | unescaped output, unsanitized or unslashed input, a missing nonce check, an unprepared query, a construct the PHP floor lacks. Errors AND warnings fail it. A false positive is annotated in the source with its reason, never excluded in the ruleset (INV-13) |
| `test` | `phpunit` — the Brain Monkey unit suite | whatever the suite asserts |
| `guard` | `bin/guard.sh` | a file missing its `defined('ABSPATH') || exit` guard, a shipped reference to a symbol v3.0.0 removed, and the CLI guard in the test bootstrap |
| `audit:deps` | `composer audit` | a dev dependency with a published advisory |

Verified, not assumed: an unguarded file makes `composer gate` exit **1**, and a
failing test makes it exit **1**. Since 5.7.1, a guarded scratch file under `core/`
holding `echo $_GET['x'];` makes it exit **1** at `lint`
(`FOUND 4 ERRORS AND 1 WARNING AFFECTING 1 LINE`), before `test` runs.

## How lint got here (history)

**`composer lint` used to be `php -l`.** The fleet's Standard tier — locked in
`netdust-wp-manager`'s `wp-gate-harness` spec — defines `lint` as phpcs with
PSR-12 + `WordPress.Security` + `WordPress.DB` + `PHPCompatibilityWP`. This
package never received the gate layer, because it is a mu-plugin package rather
than a site scaffolded from `wp-starter`. So the name claimed a tier that was
not there.

On 2026-08-20 `composer lint` became that tier, for real: `phpcs.xml` in the
repo and the sniffs installed, but deliberately NOT in `gate`:

```
$ composer lint
A TOTAL OF 189 ERRORS AND 93 WARNINGS WERE FOUND IN 19 FILES
```

That backlog was not triaged then — 282 violations inside a security release
would have buried the diffs that mattered. It wanted its own pass, and got it in
5.7.1 (`specs/core-lint-gate`): PSR-12 was dropped (style, never security; core's
global `NTDST_*` class names are deliberate), which left **109** findings (89
errors, 20 warnings, 12 files). Each has one row in
`specs/core-lint-gate/triage.md`: **0 real, 109 annotated** — 104 false positives
by provenance or design (pre-escaped locals, literals, developer declarations,
the data layer's meta queries, the advisory lock), 5 false positives by
condition (a consumer value that reaches a debug-only message; named as residuals).
Lint then joined `gate`. The 2026-08-20 first look, kept for the record:

- `WordPress.Security.ValidatedSanitizedInput` ×17 — mostly `$_SERVER` reads
  (`HTTP_ORIGIN`, `HTTP_REFERER`, `REQUEST_URI`). WP does not slash `$_SERVER`
  the way it slashes `$_GET`/`$_POST`, and these are compared byte-exactly, so
  a stray slash would produce a false DENIAL, not a bypass. Probably noise;
  needs confirming, not assuming.
- `WordPress.DB.PreparedSQL.NotPrepared` ×1 — `admin/RelationField.php`. Read
  it: the query IS `$wpdb->prepare()`d with `%s` placeholders, and the file
  already carries `phpcs:` annotations for the interpolation. The sniff cannot
  follow the variable. False positive.
- `WordPress.Security.NonceVerification` ×1 and an unslashed `$_POST` in
  `admin/MetaboxGenerator.php` — these two are worth real attention.
- `PSR12.Files.FileHeader.IncorrectOrder` ×14 — the file-header convention this
  package uses on purpose (docblock, then `defined('ABSPATH') || exit;`).
  Likely an exclusion rather than a fix. (5.7.1: gone with PSR-12. The two
  `MetaboxGenerator` findings above proved false positives — the nonce read is
  display-only, the `$_POST` is unslashed on the next statement.)

## The honest note about why H1 and M4 got through

They were found by an independent security review, and it is tempting to file
them under "the lint gap". They are not.

`H1` was a security guard with **no test** — mutating it away left the suite
green. `M4` was **two surviving mutants**. Neither phpcs nor static analysis
measures whether a test observes anything; a linter would have passed both
files without comment on the day they shipped.

What catches those is **coverage and mutation testing**, and neither is in this
gate. Mutation testing is currently done BY HAND — the commit messages in this
release record each mutation and whether it killed a test. That is better than
nothing and worse than a tool. Automating it needs a coverage driver, and this
environment has neither Xdebug nor PCOV installed (`php -m` shows both absent),
so a coverage or Infection tier would be a script that cannot run. It is not
being added as decoration.

**So: the claim was corrected AND the tier was made real, but the thing that
would actually have caught H1 and M4 is still missing and is named here rather
than implied away.**

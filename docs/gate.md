# What `composer gate` actually checks

Written 2026-08-20, because the answer was not what the name implied.

## The gate

`composer gate` runs four steps and fails on the first non-zero:

| Step | Command | What it can catch |
|---|---|---|
| `syntax` | `php -l` over every shipped file | a parse error. **Nothing else** — `php -l` never resolves a name, so a call to a deleted function passes |
| `test` | `phpunit` — the Brain Monkey unit suite | whatever the suite asserts |
| `guard` | `bin/guard.sh` | a file missing its `defined('ABSPATH') || exit` guard, a shipped reference to a symbol v3.0.0 removed, and the CLI guard in the test bootstrap |
| `audit:deps` | `composer audit` | a dev dependency with a published advisory |

Verified, not assumed: an unguarded file makes `composer gate` exit **1**, and a
failing test makes it exit **1**.

## What it is NOT

**`composer lint` used to be `php -l`.** The fleet's Standard tier — locked in
`netdust-wp-manager`'s `wp-gate-harness` spec — defines `lint` as phpcs with
PSR-12 + `WordPress.Security` + `WordPress.DB` + `PHPCompatibilityWP`. This
package never received the gate layer, because it is a mu-plugin package rather
than a site scaffolded from `wp-starter`. So the name claimed a tier that was
not there.

`composer lint` is now that tier, for real: `phpcs.xml` is in the repo and the
sniffs are installed. **It is not yet in `gate`**, and that is a deliberate,
recorded state rather than a silent one:

```
$ composer lint
A TOTAL OF 189 ERRORS AND 93 WARNINGS WERE FOUND IN 19 FILES
```

That backlog has not been triaged, and triaging 282 violations inside a
security release would bury the diffs that matter. It wants its own pass.
First look at the notable ones:

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
  Likely an exclusion rather than a fix.

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

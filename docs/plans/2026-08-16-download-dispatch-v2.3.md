# ntdst-core v2.3 — Download Dispatch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or executing-plans. Steps use `- [ ]` checkboxes.

**Goal:** Add one authenticated GET dispatch entry to `NTDST_Endpoints` so a registered handler can emit a file download via the already-existing `Response::download()`/`inline()` — closing the gap that forces file-download actions onto raw `wp_ajax`.

**Architecture:** A sibling to the existing POST `/action` dispatch. A new GET route `ntdst/v1/download` runs the *same* nonce + capability + public-action gate as `handle_action`, then dispatches to a `ntdst/api_download/{action}` filter whose handler terminates by calling `$response->download(...)`/`inline(...)` (both `: never`). Because the handler exits, control never returns; if a handler *does* return (a misconfigured download action), the dispatcher emits a 500 rather than a silent blank body.

**Tech Stack:** PHP 8.2+, WordPress REST API, PHPUnit + Brain Monkey (the repo's existing test stack). No new dependencies.

**Spec:** `../../../stride/docs/superpowers/specs/2026-08-16-ntdst-core-admin-consolidation-design.md` (Part A). This plan is the standalone framework deliverable that Part B's download handlers consume.

## Global Constraints

- **Minimalism (repo law):** smallest correct change; no speculative capability. This adds exactly one dispatch entry + one filter prefix. No new abstraction beyond what `handle_action` already models.
- **Security parity:** the GET download gate is byte-for-byte the same checks as POST `/action` — nonce verify, capability floor, `ntdst/api/public_actions` allow-list, rate limit, origin. A download action is NEVER public unless explicitly in `public_actions`.
- **No path from request:** the handler supplies filename + content to `Response::download()`; the dispatcher never reads a filename/path from the request. No path traversal surface.
- **Version:** bump `ntdst-core.php` header `Version: 2.2.2` → `2.3.0` (minor — new capability, no breaking change). Tag `v2.3.0`.
- **Test the wire, not the filter:** every test drives the real REST dispatch (`rest_do_request` / `WP_REST_Request`), not just `apply_filters` in isolation (the Stride F3 lesson).
- **`: never` handling in tests:** `Response::download()` calls `exit`. Tests assert via the framework's existing seam for terminal methods (the `ResponseRenderStatusTest` pattern — a protected seam / spy the suite already uses), never by letting `exit` fire in-process.

## File Structure

- `api/Endpoints.php` (modify): add `register_download_endpoint()`, `handle_download()`, `check_download_permission()`. Mirrors the `/action` trio. This is the whole behavioral change.
- `api/Response.php` (modify, if needed): add a protected seam so `download()`/`inline()` status+headers are assertable without `exit` firing — mirror the `commitRenderStatus()` seam added in v2.2.2. Only if the existing seam doesn't already cover it (verify in Task 1).
- `ntdst-core.php` (modify): version bump + (if the file carries a public-API doc list) note the new `ntdst/api_download/*` filter prefix.
- `tests/Unit/DownloadDispatchTest.php` (create): the dispatch + security + emission tests.
- `README.md` (modify): one line documenting the `ntdst/api_download/{action}` filter + `check_download_permission` parity.

---

### Task 1: Characterize the emission seam + confirm the gap

**Files:**
- Test: `tests/Unit/DownloadDispatchTest.php` (create)
- Read: `api/Response.php` (`download`/`inline`/`sendFile`), `api/Endpoints.php` (`handle_action`)

**Interfaces:**
- Produces: confirmation of whether `Response` already exposes a non-`exit` seam for `download()` (like `commitRenderStatus()` for `render()`). Task 2 depends on this answer.

- [ ] **Step 1: Write a probing test that asserts no GET download route exists yet**

```php
public function test_download_route_is_not_registered_before_v23(): void
{
    // The gap this plan closes: only POST /action + POST /get_nonce exist.
    $routes = rest_get_server()->get_routes();
    $this->assertArrayNotHasKey('/ntdst/v1/download', $routes);
}
```

- [ ] **Step 2: Run it — expect PASS (route genuinely absent today)**

Run: `composer gate` (or `vendor/bin/phpunit --filter DownloadDispatchTest`)
Expected: PASS — documents the starting state. (This flips to a real assertion in Task 3.)

- [ ] **Step 3: Read `sendFile()` and confirm the seam question**

Read `api/Response.php` `sendFile()`. Determine: does emitting a download set `status_header`/headers via a seam a test can spy, or only inline before `exit`? Write the finding as a one-line comment in the test file. If a seam exists (e.g. a `sendFileHeaders()` protected method), Task 2 reuses it; if not, Task 2 extracts one — same shape as v2.2.2's `commitRenderStatus()`.

- [ ] **Step 4: Commit the characterization test**

```bash
git add tests/Unit/DownloadDispatchTest.php
git commit -m "test(download): characterize the missing GET download dispatch (v2.3 Task 1)"
```

---

### Task 2: Extract the download emission seam (only if Task 1 found none)

**Files:**
- Modify: `api/Response.php`
- Test: `tests/Unit/DownloadDispatchTest.php`

**Interfaces:**
- Produces: `Response::sendFileHeaders(string $filename, string $contentType, string $disposition): void` (protected) — sets `status_header(200)` + `Content-Type` + `Content-Disposition` headers, called by `sendFile()` before it writes the body and `exit`s. Tests spy this; production still exits.

*(If Task 1 found an existing seam, skip this task — note "seam already present at <method>" in the plan ledger and proceed to Task 3.)*

- [ ] **Step 1: Write the failing test — headers committed before body**

```php
public function test_download_commits_headers_before_body(): void
{
    $r = new class extends NTDST_Response {
        public array $sent = [];
        protected function sendFileHeaders(string $f, string $ct, string $d): void { $this->sent = [$f, $ct, $d]; }
        protected function writeBodyAndExit(string $content): void { /* no exit in test */ }
    };
    $r->download('BODY', 'report.pdf', 'application/pdf');
    $this->assertSame(['report.pdf', 'application/pdf', 'attachment'], $r->sent);
}
```

- [ ] **Step 2: Run — expect FAIL (sendFileHeaders not defined / not called)**

Run: `vendor/bin/phpunit --filter test_download_commits_headers_before_body`
Expected: FAIL.

- [ ] **Step 3: Refactor `sendFile()` to call the seam**

Split `sendFile()` into `sendFileHeaders()` (protected — `status_header(200)`, `header('Content-Type: ...')`, `header('Content-Disposition: ...; filename="..."')`) then `writeBodyAndExit()` (protected — `echo $content; exit;`). `download()`/`inline()` call both in order. Behavior identical in production; seam-able in tests.

- [ ] **Step 4: Run — expect PASS; run the whole suite for no regression**

Run: `composer gate`
Expected: PASS, 16+ tests green.

- [ ] **Step 5: Commit**

```bash
git add api/Response.php tests/Unit/DownloadDispatchTest.php
git commit -m "refactor(response): extract sendFileHeaders seam so download() is testable without exit (v2.3 Task 2)"
```

---

### Task 3: The GET download dispatch — happy path

**Files:**
- Modify: `api/Endpoints.php`
- Test: `tests/Unit/DownloadDispatchTest.php`

**Interfaces:**
- Consumes: `Response::download()`/`inline()`, the seam from Task 1/2.
- Produces: route `GET ntdst/v1/download?action=X&nonce=Y`, filter `ntdst/api_download/{action}`, methods `register_download_endpoint()` / `handle_download()` / `check_download_permission()`.

- [ ] **Step 1: Write the failing test — a registered download action streams its file**

```php
public function test_registered_download_action_streams_file(): void
{
    add_filter('ntdst/api_download/test_report', function ($unused, $params) {
        // Handler emits via Response and exits; test double captures instead.
        return ntdst_response()->download('PDFBYTES', 'r.pdf', 'application/pdf');
    }, 10, 2);

    $nonce = wp_create_nonce('test_report');
    $req = new WP_REST_Request('GET', '/ntdst/v1/download');
    $req->set_param('action', 'test_report');
    $req->set_param('nonce', $nonce);
    // logged-in admin context set in setUp()
    $res = rest_do_request($req);

    $this->assertSame(200, $res->get_status());
    // assert the download headers/body were emitted via the seam spy
}
```

- [ ] **Step 2: Run — expect FAIL (no /download route)**

Run: `vendor/bin/phpunit --filter test_registered_download_action_streams_file`
Expected: FAIL — 404 no_route.

- [ ] **Step 3: Implement `register_download_endpoint()` + `handle_download()`**

In `register_routes()` add `$this->register_download_endpoint();`. Implement mirroring `/action`:

```php
private function register_download_endpoint(): void
{
    register_rest_route(self::REST_NAMESPACE, '/download', [
        'methods'             => 'GET',
        'callback'            => [$this, 'handle_download'],
        'permission_callback' => [$this, 'check_download_permission'],
        'args'                => [
            'action' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'nonce'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);
}

public function handle_download(WP_REST_Request $request)
{
    $params = $this->get_request_params($request);
    $action = sanitize_text_field($params['action'] ?? '');
    $nonce  = sanitize_text_field($params['nonce'] ?? '');

    if (empty($action) || empty($nonce)) {
        return $this->error('Missing action or nonce', 'missing_params');
    }
    if (!wp_verify_nonce($nonce, $action)) {
        return $this->error('Invalid or expired nonce', 'invalid_nonce');
    }
    if (!has_filter("ntdst/api_download/{$action}")) {
        return $this->error('Unknown download request', 'unknown_action', 404);
    }

    // The handler is expected to emit via Response::download()/inline() and
    // exit — control does not return here. If it DOES return, the download
    // action is misconfigured: fail loud rather than ship a blank 200.
    apply_filters("ntdst/api_download/{$action}", null, $params);

    return $this->error('Download handler did not emit a file', 'download_not_emitted', 500);
}
```

- [ ] **Step 4: Run — expect PASS**

Run: `vendor/bin/phpunit --filter test_registered_download_action_streams_file`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/Endpoints.php tests/Unit/DownloadDispatchTest.php
git commit -m "feat(download): GET /download dispatch to ntdst/api_download/{action} (v2.3 Task 3)"
```

---

### Task 4: Security parity — the gate is identical to /action

**Files:**
- Modify: `api/Endpoints.php` (`check_download_permission`)
- Test: `tests/Unit/DownloadDispatchTest.php`

**Interfaces:**
- Consumes: `check_action_permission` logic (rate limit, public-action allow-list, capability floor, cookie/origin).
- Produces: `check_download_permission(WP_REST_Request): WP_Error|bool` — same policy as `check_action_permission`.

- [ ] **Step 1: Write the failing tests — the four denial paths**

```php
public function test_download_denies_anonymous_non_public_action(): void { /* logged-out + non-public action → false (401) */ }
public function test_download_allows_anonymous_only_when_action_is_public(): void { /* add to ntdst/api/public_actions → allowed */ }
public function test_download_rate_limited_returns_429(): void { /* exceed limit → WP_Error 429 */ }
public function test_download_bad_nonce_returns_invalid_nonce(): void { /* wrong nonce → invalid_nonce, never dispatches */ }
```

Each drives `rest_do_request` against `/ntdst/v1/download` and asserts the wire status.

- [ ] **Step 2: Run — expect FAIL (permission callback is a stub / too permissive)**

Run: `vendor/bin/phpunit --filter test_download_denies`
Expected: FAIL.

- [ ] **Step 3: Implement `check_download_permission` reusing the /action policy**

Delegate to the same private helpers `check_action_permission` uses (rate-limit resolve, `ntdst/api/public_actions` lookup, `is_user_logged_in()`, origin). If `check_action_permission`'s body is not already factored into reusable helpers, extract the shared policy into a private `checkDispatchPermission(WP_REST_Request $request, string $filterPrefix)` and have BOTH `check_action_permission` and `check_download_permission` call it — one policy, two entry points (the minimalism-correct convergence, not a copy).

- [ ] **Step 4: Run — expect PASS; full suite green**

Run: `composer gate`
Expected: PASS. All four denial tests + the happy path green.

- [ ] **Step 5: Commit**

```bash
git add api/Endpoints.php tests/Unit/DownloadDispatchTest.php
git commit -m "feat(download): permission parity with /action — shared dispatch policy (v2.3 Task 4)"
```

---

### Task 5: Version bump, docs, tag

**Files:**
- Modify: `ntdst-core.php` (version), `README.md` (filter doc)

- [ ] **Step 1: Bump version**

`ntdst-core.php` header: `Version: 2.2.2` → `Version: 2.3.0`. Grep for any other `2.2.2` literal and bump consistently.

- [ ] **Step 2: Document the filter**

`README.md`: add `ntdst/api_download/{action}` alongside `ntdst/api_data/{action}` — GET dispatch, handler emits via `ntdst_response()->download()/inline()` and exits, same gate as `/action`, never public unless in `ntdst/api/public_actions`.

- [ ] **Step 3: Full gate green**

Run: `composer gate`
Expected: PASS.

- [ ] **Step 4: Commit + annotated tag**

```bash
git add ntdst-core.php README.md
git commit -m "release(v2.3.0): GET download dispatch — Response::download() reachable via ntdst/api_download/{action}"
git tag -a v2.3.0 -m "v2.3.0 — download dispatch (Endpoints GET /download → Response::download/inline)"
```

(Do NOT push — the controller confirms + the human pushes, per the shared-framework-line rule. Same as v2.2.2.)

---

## Self-Review

- **Spec coverage (Part A):** GET dispatch entry (Task 3) ✓; same gate as /action (Task 4) ✓; no path from request (handler supplies filename — Task 3 dispatch never reads a path) ✓; RED-first tests on the real wire (all tasks use `rest_do_request`) ✓; v2.3.0 publish (Task 5) ✓; threat-model items (public-action allow-list, rate limit, nonce) → Task 4 ✓.
- **Placeholder scan:** Task 4 Step 1 uses `/* … */` sketches for the four denial bodies rather than full code — acceptable as each is a one-assertion `rest_do_request` mirroring Task 3's fully-shown happy-path request; the shape is given. All other steps carry real code.
- **Type consistency:** `handle_download` / `register_download_endpoint` / `check_download_permission` / `sendFileHeaders` / `ntdst/api_download/{action}` used identically across tasks.
- **Publish gate:** Task 5 tags but does not push — matches the v2.2.2 shared-line rule; the controller handles the confirmed push.

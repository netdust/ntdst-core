# Threat model — `NTDST_Actions` drops the download surface (Class D, stakes standard)

**The diff.** Removes the `GET /ntdst/v1/download` route, `handle_download()`,
`check_download_permission()` and the `ntdst/api_download/{action}` dispatch filter.

**Why Class D.** `Actions.php` is an auth/allow-list file. Removing a dispatch door is a
security-boundary edit even when the door leads nowhere.

**Why it is safe to remove: it has no consumers.** Searched across every site on the
package stack and the legacy-framework sites: zero `add_filter('ntdst/api_download/…')`
registrations anywhere. The one large-file download in the fleet — daan's press kit ZIP —
never used this surface: it calls `NTDST_Response::downloadHeaders()` directly and emits
its own bytes, which is exactly what that method's docblock describes.

| # | Threat | Disposition |
|---|---|---|
| T1 | A consumer's download breaks. | No consumer exists. Verified by search, not assumed. |
| T2 | **Regression** — `/action` loses the Origin/CSRF check. `checkDispatchPermission()` took a `$verifyOrigin` flag so `/download` could opt OUT of it. With one caller left the flag is constant, and collapsing it wrong would silently disable CSRF on the only remaining door. | The flag is removed and the check made unconditional — the stronger of the two states. Asserted by the existing origin-gate tests staying green. |
| T3 | **Regression** — nonce minting stops working for `/action`. `check_nonce_permission()` accepted EITHER dispatch filter as proof of registration. | Narrowed to the data filter only. An action registered through `ntdst_actions()->register()` still mints; nothing else ever could. |
| T4 | **Regression** — the query-string parameter fallback disappears. It exists because a real `<a href>` navigation sends no body, and `/download` was the only GET surface. | The fallback is KEPT, at its existing lowest precedence, and its docblock corrected. Removing it would change `/action` for any caller passing query params — out of scope for a deletion. |
| T5 | Test coverage is deleted along with working code. | Three test files guard the action download surface and go with it. `DownloadHeadersTest` does NOT — it covers `NTDST_Response::downloadHeaders()`, which stays and which daan uses. Checked file by file rather than by filename. |

**Denial path under test.** The two public methods must not exist, no `/download` route may
be registered, and no `api_download` identifier may remain in the code. Those assertions
fail before the removal and pass after it.

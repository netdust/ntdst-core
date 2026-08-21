# Threat model — `NTDST_Rest` records its registered surface (Class D, stakes standard)

**The diff.** Records every successfully-registered route with its DECLARED permission, and
exposes readers so a site can assert on its own anonymous surface.

**Why it exists.** `NTDST_Actions` had one property nothing else replaces: a site's entire
anonymous surface was one greppable list (`ntdst/api/public_actions`), and a test could
assert on it. Routes scatter `permission => 'public'` across registrations, so that
assertion has nowhere to live. Removing the action router without replacing this would
trade a checkable property for an uncheckable one.

**Why Class D.** Additive, but to the file that decides who may reach what.

| # | Threat | Mitigation |
|---|---|---|
| T1 | **False assurance — the dangerous one.** A closure permission is opaque; `fn() => true` is indistinguishable from a real check. If introspection silently counted callables as "not public", a site's "no anonymous routes" test would pass over a wide-open route. | A callable is recorded as `callable` and NEVER as safe. `publicSurface()` reports declared-public routes; `opaqueSurface()` reports the callables separately, so a site asserts on BOTH — "no route declares public, and these N callables are ones I have read". A test that cannot see a risk must say so rather than omit it. |
| T2 | The registry becomes an information-disclosure surface of its own. | It is a PHP static read in-process. No route, no filter, no HTTP exposure is added. |
| T3 | A REFUSED route (no permission, uncallable handler, unknown option) is recorded and reads as registered. | Recorded only after every refusal path has passed, on the same line as `register_rest_route()`. |
| T4 | Unbounded growth. | Bounded by the routes a site declares; keyed so a re-registration overwrites rather than appends. |
| T5 | **Regression** — recording changes registration behaviour. | Recording is a static array write beside the existing call; no argument to `register_rest_route()` changes. Existing route tests stay green. |

**Denial path under test.** A route declared `public` appears in the public surface; one
declared with a capability does not; one declared with a CLOSURE appears in neither the
public nor the safe set, but in the opaque one; a refused route appears nowhere.

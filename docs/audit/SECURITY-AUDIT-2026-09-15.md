# SEO-WP — Security Audit Response (2026-09-15)

An external security audit of the source tree was submitted for review. Every finding was independently re-verified against the actual current code before any fix was made — none were accepted on the audit's word alone. This document is that verification record, not a copy of the original report.

## SEC-001 — SSRF in Link Audit (HIGH, confirmed, fixed)

**Claim:** `check_url()` (`src/Admin/LinkAudit.php`) calls `wp_remote_head()`/`wp_remote_get()` on URLs extracted from post content, nav menus, and widgets — content a role below Administrator can normally author — with no validation of the destination, including on manually-followed redirect hops.

**Verified:** True. Read the method directly: `redirection => 0` (WP core's own redirect-following disabled), redirects followed manually in a loop via `resolve_redirect_url()`, no host/IP check anywhere before either `wp_remote_head()` or `wp_remote_get()`.

**Fix:** Switched to `wp_safe_remote_head()`/`wp_safe_remote_get()` with `reject_unsafe_urls => true`. Because every hop in this method is already a fresh top-level call (not core's own internal redirect chain), this re-validates every single hop through `wp_http_validate_url()` — WordPress core's own loopback/private/reserved/link-local IPv4+IPv6 rejection — not just the first request. A rejected destination surfaces as a normal "broken link" result via the existing `is_wp_error()` branch.

## SEC-002 — Secrets in an autoloaded option (MEDIUM, confirmed, fixed)

**Claim:** API keys and the Google service-account key live inside `seo_wp_settings`, which is deliberately autoloaded (for legitimate performance reasons elsewhere), unnecessarily widening their exposure to every request's memory.

**Verified:** True.

**Fix:** New `SeoWp\Support\Secrets`, a dedicated `seo_wp_secrets` option, explicitly `autoload => false`. Falls back read-only to the legacy `seo_wp_settings` location for an existing installation's already-saved values; the next save moves a key to the new option for good. All 5 read sites and 2 write sites migrated. `uninstall.php` deletes the new option too.

## SEC-003 — ReDoS heuristic is not a complete defense (MEDIUM, confirmed, fixed)

**Claim:** `has_catastrophic_backtracking_shape()`'s save-time heuristic doesn't cover every PCRE denial-of-service pattern shape; a pattern it misses could still hit PCRE's actual backtrack/recursion limit against a real request.

**Verified:** True — this was accurately described as the heuristic's own documented limitation.

**Fix:** `regex_matches()` now checks `preg_last_error()` after every match attempt. A `PREG_BACKTRACK_LIMIT_ERROR`/`PREG_RECURSION_LIMIT_ERROR` still fails the match closed, and now additionally counts against that rule; after 3 such failures the rule is auto-deactivated and flagged in the Redirects admin table, rather than silently continuing to run against every future request.

## SEC-004 — CSV import has no resource ceilings (LOW, confirmed, fixed)

**Verified:** True — no file size, row count, or field length limit existed in `RedirectsPage::handle_import()`.

**Fix:** 2 MB file size cap (checked before opening the file), 5000 row cap, 2000-character field cap, each with a clear admin notice on the resulting outcome.

## SEC-005 — 404 monitor write amplification (LOW, confirmed, fixed)

**Verified:** True — every anonymous 404 did a full read-modify-write, and once at the 500-entry cap, a full `uasort()` of the whole log ran on literally every subsequent new-path insert.

**Fix:** Request paths truncated to 500 characters before storage; the expensive sort+trim now only runs once the log grows 50 entries past the cap, batching it instead of running on every insert at capacity.

## SEC-006 — Worker token lifecycle (LOW, partially confirmed, fixed)

**Claim:** the Link Audit background worker's public token should use a cryptographically secure primitive, be rotated on completion, and reject requests when no active job exists.

**Verified:** Mixed. `wp_generate_password(32, false, false)` already uses a cryptographically secure WordPress primitive (no change needed). `public_job_data()` already omits the token from every AJAX response (no change needed). The real gap: nothing ever cleared the token once a job completed or errored, and `handle_worker_ajax()` (unlike its cron-fallback sibling) didn't check the job's status before running — a captured or replayed old token could re-trigger a finished job.

**Fix:** New `finish_job()` helper blanks the token (and clears the job's cron fallback) on every completion/error path; `handle_worker_ajax()` now also explicitly checks the job is still `scanning`/`checking`.

## Summary

| ID | Severity | Verified | Action |
|---|---|---|---|
| SEC-001 | HIGH | Confirmed | Fixed — `wp_safe_remote_*()` |
| SEC-002 | MEDIUM | Confirmed | Fixed — dedicated non-autoloaded option |
| SEC-003 | MEDIUM | Confirmed | Fixed — runtime execution-limit detection + quarantine |
| SEC-004 | LOW | Confirmed | Fixed — size/row/field ceilings |
| SEC-005 | LOW | Confirmed | Fixed — path length cap + batched trim |
| SEC-006 | LOW | Partially confirmed | Fixed the real gap (token lifecycle); 2 of 3 sub-claims were already satisfied |

All 6 fixes shipped in v1.4.5, one commit per finding, each independently verified against the actual code before any change was made — full detail and reasoning is in each fix's own commit message.

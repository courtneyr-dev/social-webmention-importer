# Changelog

## 0.3.0 — 2026-08-18

- LinkedIn comment permalinks now auto-fill: comment IDs are snowflake
  timestamps (id >> 22 = ms epoch), matched against the thread's public
  JSON-LD comments' datePublished (±2 s; live deltas are ~1 ms). Matched
  replies get their author name, text, and exact date; comments outside
  the page's public subset still fall back to manual entry.
- New `swi_verification_target_url` filter so staging can verify sources
  that link to the production permalink.

## 0.2.0 — 2026-08-18

- Verification now expands provider link shorteners (`lnkd.in`
  interstitials, `t.co` redirects, up to five per source), so a LinkedIn
  post whose article link is rewritten by the network still verifies as a
  Webmention. Verified live against a real public activity page.
- LinkedIn activity pages (`/feed/update/urn:li:activity:…`) extract the
  full post author (name, profile URL, real avatar), text, and exact
  publish time from JSON-LD.
- LinkedIn comment permalinks (`commentUrn=…`) dedupe as their own remote
  item and no longer risk inheriting the post author's identity; the
  preview lists the thread's public commenter names for manual fill-in.

## 0.1.0 — 2026-08-18

Initial release.

- Tools → Social Webmention Importer: three-step batch workflow (target +
  URLs → editable preview → per-row report) for up to 25 public X/Twitter
  and LinkedIn response URLs.
- Two import modes: verified Webmention (source provably links to the
  target; stored with Webmention-plugin-compatible schema) and curated
  social response (`protocol=social-linkback`, pending comment, visible
  "Originally posted on X/LinkedIn" attribution with source link).
- Author identity extraction: X oEmbed, JSON-LD Person, tested title
  patterns; never substitutes the network name for a person, never uses
  post media or LinkedIn branding as an avatar (X `profile_images` rule).
- Avatars via the Webmention plugin's `avatar` comment meta (external URL
  or Media Library image); theme network badges activate through the author
  profile URL, with a plugin-shipped LinkedIn badge rule.
- Reviewer-confirmed identity store; reviewer-locked fields survive
  refreshes; idempotent re-imports (x.com/twitter.com deduplicate).
- Comment row actions: refresh social metadata (remote deletions unpublish
  and alert an admin) and edit import details.
- Hardened fetching: `wp_safe_remote_get` plus link-local/reserved IP
  rejection (covers `169.254.169.254`, which WordPress core allows), 1 MB
  cap, per-hop redirect validation. Imported HTML sanitized to a minimal
  comment policy.
- PHPUnit unit + integration suites with sanitized fixtures; WPCS-clean.

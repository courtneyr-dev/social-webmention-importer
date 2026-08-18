# Changelog

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

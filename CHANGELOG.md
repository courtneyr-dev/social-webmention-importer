# Changelog

## 0.5.4 — 2026-09-22

- Security hardening: the fetch-policy redirect guard now runs on every
  redirect hop instead of a filter that never re-fired for internal
  redirects. Host names are resolved and the resolved address is
  range-checked (not just a literal IP already in the URL); a trailing
  dot on the host is trimmed before those checks, so a fully-qualified
  form of a blocked address can't slip through; `100.64.0.0/10` and the
  other non-global ranges are now blocked too, via
  `FILTER_FLAG_GLOBAL_RANGE`; and a host name that fails to resolve is
  refused rather than treated as safe.
- Target verification now checks the character after a match too, so a
  source linking only to a longer path no longer verifies a shorter
  target.
- A saved reviewer-confirmed identity is applied once the page has
  actually confirmed the author — the handle when X's oEmbed or
  LinkedIn's JSON-LD confirms it, otherwise the author URL — never off
  the bare, unconfirmed path-derived guess. On a blocked or unreadable
  row (a LinkedIn authwall/999 response, or an X row where both oEmbed
  and the page fail), no saved identity is guessed onto it; the reviewer
  enters the name.
- Every file under `includes/` now refuses to run outside WordPress.
- Imported comments no longer inherit the reviewing admin's IP address.
- Added `.distignore` so a packaged build excludes tests, docs, and dev
  tooling config.

## 0.5.3 — 2026-08-18

- Indent the Tools entry beneath Webmention's so the pair reads as
  parent/child. (The admin menu is limited to two levels, so a true
  hover flyout isn't possible without inaccessible hover-only UI.)

## 0.5.2 — 2026-08-18

- The Tools entry now sits directly below Tools → Webmention (admin
  submenus are flat, so nesting = ordering). Falls back to the default
  position when Webmention's Tools entry is absent.

## 0.5.1 — 2026-08-18

- The importer now also appears beside Webmention under the IndieWeb
  top-level menu when the IndieWeb plugin is active (as "Social
  Importer", linking to the canonical Tools screen). Webmention itself
  has no top-level menu — it lives under Settings, or under IndieWeb —
  so this mirrors its placement.

## 0.5.0 — 2026-08-18

- Block editor integration: a "Social responses" document panel lets you
  paste response URLs while editing the post; Preview hands the batch to
  the importer's review screen with the post preselected.
- "Approve these responses immediately" checkbox on the review screen —
  an explicit per-batch reviewer action that publishes on import instead
  of holding as pending. Ignored for users who cannot moderate comments.

## 0.4.0 — 2026-08-18

- Imported comments carry `swi-provider-{provider}` and (when avatar-less)
  `swi-no-avatar` comment classes. LinkedIn imports now show the network
  badge with no author URL required, and comments without an avatar hide
  the Gravatar placeholder instead of showing a mystery-man image.

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

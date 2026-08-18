# Social Webmention Importer

Companion plugin for [Webmention](https://wordpress.org/plugins/webmention/). Paste public X/Twitter and LinkedIn post URLs, review the extracted author, avatar, text, and date, and import the responses into a post's conversation — with correct person-level attribution instead of "X" or "LinkedIn".

Requires WordPress 6.2+, PHP 8.2+, and the Webmention plugin (active).

## What it does

- **Batch entry** — paste up to 25 X/Twitter or LinkedIn URLs at once under **Tools → Social Webmention Importer**, or jump there from a post's row actions ("Import social responses").
- **Editable preview** — every URL becomes an editable card: author name, handle, profile URL, avatar (URL or Media Library image), text, date, and response type. Nothing imports without your review.
- **Two honest modes:**
  - **Verified Webmention** — the source page provably links to your article. Stored with the Webmention plugin's own schema (`protocol=webmention`, source/target metas, `avatar` meta), so its display, facepile, and avatar handling apply unchanged.
  - **Curated social response** — the source doesn't link to the article (a reply to your syndicated social post, say). Stored as a normal pending comment with `protocol=social-linkback`, labeled "Originally posted on X/LinkedIn" with a link to the original. It never claims to be a Webmention.
- **Author identity done right** — names come from public metadata (X oEmbed, JSON-LD `Person`, tested title patterns). A missing name stays blank for you to fill; the network name is never substituted. `og:image` is only used as an avatar when a tested rule proves it depicts the author (X profile-image URLs); LinkedIn share cards and branding are never used.
- **Network badges** — imported responses set the author profile URL, which drives the courtneyr-child theme's CSS comment badges (X badge works out of the box). The plugin ships the missing LinkedIn badge rule in its own stylesheet, matching Mastodon/Bluesky display.
- **Reusable identities** — check "Use this identity for future imports" and your corrected name/profile/avatar pre-fill every later import for that handle. Reviewer-confirmed values always outrank parser output.
- **Idempotent** — re-importing the same source updates the existing comment (keeping its moderation state and your locked edits) instead of duplicating. `twitter.com` and `x.com` URLs for the same status deduplicate.
- **Moderated by default** — every import starts pending unless you explicitly flip the settings checkbox.
- **Comment row actions** — "Refresh social metadata" re-fetches the source (a deleted/private source sets the comment back to pending and emails the admin); "Edit import details" reopens the importer for that source.

## Installation

1. Ensure the Webmention plugin is installed and active.
2. Copy this directory to `wp-content/plugins/social-webmention-importer/` (or add the repo as a submodule in your deploy repo).
3. Activate **Social Webmention Importer**.
4. Find the importer under **Tools → Social Webmention Importer** (requires the `moderate_comments` capability plus edit rights on the target post).

## Usage

1. **Step 1** — choose the target post (searchable), paste URLs one per line, and click **Preview responses**. Nothing is written yet; the plugin fetches each public page once.
2. **Step 2** — review each card. Fix names, pick avatars (external URL or Media Library), adjust text and dates. Unverified sources require ticking the curated-response confirmation. Optionally save the identity for future imports.
3. **Step 3** — the report lists one result per URL (imported / updated / skipped / failed, with reasons) and links each success to the comment edit screen and its public anchor. One row's failure never blocks the others.
4. Approve the pending comments from **Comments** as usual.

## Development

```bash
composer install
composer lint          # PHPCS (WordPress Coding Standards)
WP_TESTS_DIR=~/.wp-tests/wordpress-tests-lib composer test
```

Tests never hit live networks; provider behavior is covered by sanitized fixtures in `tests/fixtures/`.

## Uninstall

Uninstalling removes only plugin settings (`swi_identities`, `swi_allow_reviewer_approve`) and expired preview transients. **Imported comments are never deleted** — they are content you curated. Delete them from the Comments screen if you want them gone.

## Documentation

- [docs/audit.md](docs/audit.md) — the pre-implementation site audit.
- [docs/architecture.md](docs/architecture.md) — integration boundaries with the Webmention plugin and theme.
- [docs/metadata-schema.md](docs/metadata-schema.md) — every comment field and meta key written.
- [docs/security-privacy.md](docs/security-privacy.md) — fetch policy, sanitization, privacy boundaries, suggested privacy-policy text.
- [docs/manual-test-checklist.md](docs/manual-test-checklist.md) — release checklist.
- [docs/provider-limitations.md](docs/provider-limitations.md) — what X and LinkedIn do and don't expose.

## License

GPL v2 or later.

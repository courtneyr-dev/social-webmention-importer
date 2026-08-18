# Metadata schema reference

## Comment fields

| Field | Mode A (verified Webmention) | Mode B (curated social response) |
|---|---|---|
| `comment_post_ID` | target post | target post |
| `comment_author` | person's display name (required) | same |
| `comment_author_email` | empty | empty |
| `comment_author_url` | person's public profile URL (drives theme badge CSS) | same |
| `comment_content` | sanitized response text (`wp_kses`, comment policy) | same |
| `comment_type` | `comment`, or a registered Webmention type (`mention`, `repost`, `like`) | always `comment` |
| `comment_approved` | pending (`0`) unless the settings checkbox allows normal moderation | same |
| `comment_date_gmt` | source publication time when reliable, else import time | same |
| `comment_agent` | `Social Webmention Importer/{version}` | same |

## Webmention-compatible comment meta (Mode A only)

| Key | Value |
|---|---|
| `protocol` | `webmention` |
| `webmention_source_url` | exact submitted source URL |
| `webmention_target_url` | canonical permalink of the target post |
| `webmention_last_modified` | import/refresh time (GMT) |

## Shared comment meta (both modes)

| Key | Value |
|---|---|
| `protocol` | `webmention` (A) / `social-linkback` (B) |
| `url` | canonical social response URL (x.com form for X) |
| `avatar` | avatar URL or attachment ID (only when one exists) — rendered by the Webmention plugin's avatar handler |

## Plugin-owned provenance meta (both modes, registered, not in REST)

All registered with types, sanitizers, and an auth callback requiring
`moderate_comments`; `show_in_rest` is false throughout (import provenance is
diagnostic, not public content).

| Key | Type | Meaning |
|---|---|---|
| `_swi_provider` | string | `x`, `linkedin`, `generic` |
| `_swi_remote_id` | string | stable status/activity ID when the URL carries one |
| `_swi_import_mode` | string | `webmention` / `social-linkback` |
| `_swi_imported_at_gmt` | string | first import time (preserved on update) |
| `_swi_last_checked_at_gmt` | string | last preview/refresh time |
| `_swi_original_source_url` | string | URL exactly as pasted |
| `_swi_normalized_source_key` | string | `{post}|{provider}|{remote-id-or-normalized-url}` — the idempotency key |
| `_swi_extraction_method` | string | best extraction source (`oembed`, `jsonld`, `manual`, …) |
| `_swi_extraction_confidence` | integer | rank of that source (0–100) |
| `_swi_target_verified` | boolean | source-contains-target result at import time |
| `_swi_manual_fields` | array | reviewer-edited fields, locked against refresh overwrites |
| `_swi_snapshot_hash` | string | hash of the public-facing snapshot for change detection |

## Options

| Option | Purpose |
|---|---|
| `swi_identities` | reviewer-confirmed identities: provider+handle → name, profile URL, avatar, confirmed timestamp (capped at 500 entries) |
| `swi_allow_reviewer_approve` | when set, imports follow normal moderation rules instead of forced pending |

## Transients

`swi_batch_{user}_{token}` — preview batches and result reports, 30-minute TTL.

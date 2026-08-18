# Architecture and integration boundaries

## Layering

```
Admin\Import_Page          screen rendering (3 steps, server-rendered)
Admin\Import_Controller    nonce + capability + admin-post/AJAX actions
Import\Preview_Service     batch orchestration (per-URL isolation)
Provider\*                 X / LinkedIn / Generic adapters (no direct network)
Parsing\*                  pure metadata/author/content/avatar rules
Verification\Target_Verifier   source-contains-target check
Import\Comment_Importer    validation + wp_new_comment/wp_update_comment
Import\Duplicate_Detector  normalized keys + existing-comment lookup
Import\Identity_Store      reviewer-confirmed identities (one option)
Http\Safe_Fetcher          the only code that touches the network
Display\Attribution        curated-response label + LinkedIn badge CSS
```

Data flows through `Import\Preview_Record`, a documented value object with
per-field extraction provenance and confidence precedence (`manual` >
`identity-store` > `oembed` > `jsonld`/`mf2` > `meta-author` >
`title-pattern` > `path-handle`). Providers receive a fetcher callable, so
tests substitute fixtures and the fetch policy stays in one class.

## Webmention plugin boundaries

Consumed as data contracts (no Webmention file is modified):

| What | Contract | Stability |
|---|---|---|
| Comment meta `protocol`, `webmention_source_url`, `webmention_target_url`, `url`, `avatar`, `webmention_last_modified` | Written for Mode A so Webmention's display/dedup treat imports as its own | Registered meta in Webmention 5.9.0 (`Receiver::register_meta()`); stable across 5.x |
| `avatar` meta rendering | `Webmention\Avatar::avatar_stored_in_comment()` on `pre_get_avatar_data` reads URL or attachment ID for any avatar comment type | Public behavior since Semantic Linkbacks merge; also read by us, not re-implemented |
| `get_webmention_comment_type_names()` | Filters the response-type choices for Mode A | Public function; guarded with `function_exists()` |
| `get_post_types_by_support( 'webmentions' )` | Post selector scope | Core WP API |
| Dedup interop | `Duplicate_Detector` also matches `webmention_source_url`/`url` metas so an organic webmention for the same tweet is updated, not duplicated | Mirrors Webmention's own `check_dupes()` meta keys |

Internals used **despite lacking a stable public contract**: none. The plugin
never calls into `Webmention\Receiver`/`Handler` code paths; Mode A writes the
documented meta shape and inserts through core `wp_new_comment()`.

Upstream hooks that would reduce coupling further (recommended to propose):

1. A public "insert webmention commentdata" function or action wrapping
   `Receiver::process()` for already-verified data, so companions need not
   duplicate the meta shape.
2. A filterable avatar-candidate hook in the parser layer, so provider rules
   like "X og:image is the avatar only under /profile_images/" could feed the
   Webmention pipeline directly.

## Theme boundaries

The courtneyr-child theme paints comment network badges with pure CSS keyed
off the comment author URL (`components.css` v0.5.13). This plugin:

- sets `comment_author_url` to the person's profile URL (activates the
  existing X/Mastodon/Bluesky/GitHub/WordPress rules);
- ships one additional rule for `linkedin.com` in `assets/css/front.css`,
  using the same selector shape, instead of editing the theme. On themes
  without `.single-post__comment-item` the rule is inert.

No theme file is modified. The curated-response label renders through the
core `comment_text` filter, inside whatever markup the theme already uses.

## Request flow (admin)

Preview and import are classic POST → process → redirect → render (PRG)
through `admin-post.php`, with per-action nonces and capability checks
(`moderate_comments` + `edit_post` on the target). Preview state lives in a
user-scoped transient for 30 minutes; the browser only ever posts field
values and the batch token — it cannot direct the server to arbitrary
endpoints. All remote fetching happens server-side in `Safe_Fetcher`.

# Phase 0 audit — courtneyr.dev Webmention environment

Audited 2026-08-18 against the live site (`wp @live`) and local checkouts.
Emails, IPs, nonces, and credentials are redacted.

## 1. Installed Webmention plugin

- Version **5.9.0** (matches the current wordpress.org stable). `webmention_db_version` is also 5.9.0.
- Requirements per readme: WordPress 6.2+, PHP 7.4+. Site runs well above both.

## 2. Active theme and comment rendering

- Active theme: **courtneyr-child 0.5.207**, child of **Ollie 1.6.1** (block theme).
- Comments render through core comment blocks (`wp-block-comment-author-name`,
  `wp-block-comment-content`, `wp-block-avatar`, …) inside a
  `.single-post__comment-item` group. Microformats classes (`u-comment h-cite`,
  `p-author h-card`, `u-url`, `dt-published`, `p-content`) come from the theme markup.
- **Network badges are pure CSS** (`assets/css/components.css`, v0.5.13 block):
  `.single-post__comment-item:has(.wp-block-comment-author-name a[href*="…"])::after`
  paints a circular badge from a data-URI SVG. Rules exist for generic/globe,
  Mastodon (`/@`), WordPress, **X (`x.com/`, `twitter.com/`)**, GitHub, and Bluesky
  (`bsky.app`). Detection keys off **`comment_author_url`**.
  - Consequence: an imported X response shows the X badge automatically **if**
    `comment_author_url` is the author's X profile URL. The known tweet comment
    has an empty author URL, which is why it shows no badge today.
  - There is **no LinkedIn rule** in the theme. This plugin ships an equivalent
    plugin-owned CSS rule for `linkedin.com` author URLs rather than editing the theme.
- Reactions (likes/reposts) render through the Webmention plugin's
  Interactivity-API `reaction-links` block (avatar + name, no network icon), plus
  IndieBlocks' hooked facepile (its sprite has only like/repost/bookmark glyphs).

## 3. Webmention avatar settings

- `webmention_avatars = 1` (avatars shown for webmention comment types).
- `webmention_avatar_store_enable` is **empty (disabled)** — no local avatar caching.
- Display path: `Webmention\Avatar::avatar_stored_in_comment()` on
  `pre_get_avatar_data` (prio 30) reads the **`avatar` comment meta** — a URL or a
  numeric attachment ID — for any avatar-enabled comment type with `user_id = 0`.
  This works for plain `comment` types too, so both import modes can use `avatar` meta.
  A broken-image `onerror` fallback to the plugin's `mm.jpg` ships with every avatar.

## 4. Parser that handled the known tweet

- The site defines `WEBMENTION_PROCESS_TYPE = sync` — processing is **synchronous**.
- Handler chain (first complete item wins): **MF2 → WP → Meta → JSON-LD**.
- X serves no MF2 and no usable JSON-LD. To browser user agents it serves a JS shell
  with boilerplate meta only. To a WordPress-style UA (`WordPress/6.x; https://…`) it
  serves the full OG set. The **Meta handler** therefore produced the item:
  - `name` ← `og:title` → `"Adam W. Warner 👋 … (@wpmodder) on X"` (network-decorated)
  - `content` ← `og:description` → tweet text (may be truncated by X)
  - `photo` ← `og:image` → `https://pbs.twimg.com/profile_images/…` (here: the author
    avatar, because text-only tweets use the profile image as OG image; tweets with
    media use the media instead — hence the "never trust og:image" rule)
  - `site_name` ← `og:site_name` → `"X (formerly Twitter)"` (the network-attribution source)

## 5. The known successful tweet comment (comment 27510)

- Post 37846 (`/2026/08/17/whats-next-open-source-sbom-docs/`), source
  `https://x.com/wpmodder/status/2089525473134944258`.
- Row: `comment_type = comment`, approved, author name present (hand-corrected after
  import; author email redacted), **`comment_author_url` empty**, agent = browser UA
  (manual endpoint-form submission).
- Meta: `protocol = webmention`, `webmention_source_url`, `webmention_target_url`,
  `webmention_last_modified`, `url` — and **no `avatar` meta** (why no avatar shows).

## 6. Source of network-level attribution

`og:site_name`/`og:title` from X's server-rendered OG page (see §4). The Webmention
Meta handler maps `og:title` to the item name, and `Item::verify()` falls back through
author name. No author `Person` structure exists in X's public HTML.

## 7. Processing mode

Synchronous (`WEBMENTION_PROCESS_TYPE` constant set to `sync` in server config).

## 8. Plugins affecting comments/caching/security

- **perfmatters** 2.6.7 — lazy-loads avatars (`perfmatters-lazy`, `data-src` rewrite); imported avatars must be plain `<img>`-compatible URLs (they are — meta URL flows through `get_avatar`).
- **yoast-comment-hacks** 2.1.6 — comment email/redirect tweaks; no schema conflict.
- **sucuri-scanner**, hosted **Patchstack** mu-plugin — WAF/monitoring; no comment-schema impact, but admin-ajax/admin-post actions must be normal same-origin requests.
- **complianz-gdpr** — consent; front-end label must not add trackers (it doesn't).
- **object-cache-pro** — persistent object cache; option/transient writes must use core APIs (they do).
- **gd-system-plugin** (GoDaddy hosting cache) + edge caching per repo docs — new comments purge via core comment hooks.
- Webmention config: `webmention_approve_domains = indieweb.org, brid.gy` auto-approves those domains only; **core `comment_moderation = 1`** (everything else held for moderation). Bluesky/Mastodon arrive via Bridgy webmentions.

## 9. Repository tooling conventions (from post-kinds-for-indieweb)

- Composer: `wp-coding-standards/wpcs ^3.0`, `phpstan ^2` + `szepeviktor/phpstan-wordpress`,
  `phpunit ^9.6` + `yoast/phpunit-polyfills`, PHP ≥ 8.2, scripts `lint`/`analyze`/`test`/`check`.
- WPCS file naming (`class-*.php`), tabs, snake_case.
- Local integration tests: `~/.wp-tests` + `WP_TESTS_DIR`, MySQL root/empty password.
- Deploy: plugin repos are pinned as submodules in `staging-courtneyr-dev` (staging) and
  `courtneyr-dev-site` (live); rsync GitHub Action deploys on push to main.

## 10. Local uncommitted work

Both deploy repos clean. `post-kinds-for-indieweb` has one untracked build zip
(unrelated; left untouched). This plugin is a brand-new repo — no existing work disturbed.

## Design consequences adopted

1. Fetch X with a WordPress-style UA to get server-rendered OG; browsers get an empty shell.
2. X oEmbed (`publish.twitter.com/oembed`, 301 → follow) works credential-free and returns
   `author_name`, `author_url`, and full tweet HTML — primary X extraction path.
   It does **not** return an avatar.
3. X avatar rule: accept `og:image` as avatar **only** when its path contains
   `/profile_images/` (tested provider rule); `pbs.twimg.com/media/…` is post media.
4. Verification: X embeds the expanded target URL in its server-rendered HTML
   (t.co data attributes), so source-contains-target verification works on the raw fetch.
5. Set `comment_author_url` to the author's profile URL so the theme's CSS badge
   (X) fires; ship a plugin-owned LinkedIn badge rule.
6. Store avatars in the `avatar` comment meta (URL or attachment ID) — the Webmention
   plugin renders them for both import modes; no extra avatar filter needed while
   Webmention is active (it is a hard dependency of this plugin).
7. Force pending on import regardless of `webmention_approve_domains`, unless the
   site setting explicitly allows approval.

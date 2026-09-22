# Security and privacy note

## Authorization and CSRF

- Every screen and action requires `moderate_comments` **and** `edit_post` on
  the target post. Nonces protect preview, import, settings, refresh, and the
  post-search AJAX endpoint — as CSRF protection only; capabilities are the
  authorization layer.
- Preview state is stored in transients scoped to the acting user's ID, so
  one reviewer cannot import another reviewer's batch.

## Fetch policy (SSRF)

- All fetching is server-side through `Http\Safe_Fetcher` /
  `wp_safe_remote_get()`: HTTP(S) only, no credentials in URLs, 10 s timeout,
  3 redirects, 1 MB response cap.
- Core's `wp_http_validate_url()` rejects loopback and RFC-1918 hosts, and is
  re-applied to every redirect hop by `wp_safe_remote_get()` (it registers a
  `requests.before_redirect` check directly on the Requests library, since
  redirects happen inside one `Requests::request()` call and never re-enter
  `WP_Http::request()`).
- **Additional hardening:** core does *not* reject link-local/reserved ranges
  (notably `169.254.169.254`, the cloud-metadata endpoint). `Safe_Fetcher`
  resolves hostnames and rejects IP literals — the original or the resolved
  one — in private, loopback, link-local, and reserved ranges itself, both
  up front (`validate_url()`) and per redirect hop via a
  `requests-requests.before_redirect` action (the WordPress action
  `WP_HTTP_Requests_Hooks` bridges from that same Requests-level hook).
  `pre_http_request` was tried first but never actually fires for an
  internal redirect, so a guard registered there never runs; discovered
  while writing the SSRF test suite, along with the missing range check
  itself — consider reporting the latter upstream.
- The browser never chooses fetch endpoints: it posts URLs, the server
  validates and fetches them.
- DNS-rebinding (a hostname resolving to an internal IP) is out of scope for
  v1, matching core's own posture; the admin-only, nonce-protected surface
  limits exposure to trusted reviewers.

## Content sanitization

- Imported text passes `wp_kses` with a conservative comment policy (`a[href|rel]`,
  `p`, `br`, `em`, `strong`, `blockquote[cite]`, `code`). Scripts, forms,
  iframes, embeds, event handlers, and tracking pixels are stripped.
- All admin and front-end output is escaped at render time (`esc_html`,
  `esc_attr`, `esc_url`, `esc_textarea`).
- Imports run through `wp_new_comment()` so normal moderation and spam hooks
  still apply; everything lands pending unless the site setting says otherwise.
- Raw remote HTML is held in memory for verification only and is never
  persisted (`Preview_Record::to_array()` drops it).
- No response bodies, IPs, cookies, tokens, or email addresses are logged.

## Relationship to past Webmention CVEs

The Webmention plugin's recent fixes addressed stored XSS via parsed remote
content and SSRF via source fetching. This plugin avoids both patterns:
remote content is kses-sanitized before storage and escaped on output, and
fetching goes through the hardened policy above rather than raw requests.

## Privacy boundaries

- Only public pages explicitly pasted by an administrator are fetched — no
  profile crawling, no reply-tree walking, no feeds, no login walls bypassed,
  and no stored social credentials (none are ever requested).
- Attribution is preserved: person's name, profile link, source link, and
  original publication date when available.
- Unpublish/delete works through the normal Comments screen; **Refresh social
  metadata** detects deleted or restricted sources, sets the comment back to
  pending, and emails the site admin rather than leaving removed content public.
- Uninstall removes settings and caches only; curated content is never
  silently deleted.

## Suggested privacy-policy text

> Some comments on this site are snapshots of public social-media responses
> (for example from X or LinkedIn), imported manually by the site owner. Each
> snapshot shows the author's public name, a link to their profile, a link to
> the original post, and, when available, their public avatar image, which may
> be served from this site or the social network. If you are the author of an
> imported response and want it corrected or removed, contact the site owner
> and it will be unpublished.

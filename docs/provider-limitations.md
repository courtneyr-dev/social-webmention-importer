# Provider limitations

Everything below depends on provider HTML/endpoints staying as observed on
2026-08-18. Title patterns and the OG behavior are fixture-tested so a
provider change breaks tests, not silently breaks imports — extraction
always degrades to editable manual fields, never to wrong data.

## X / Twitter

- **oEmbed** (`publish.twitter.com/oembed`, credential-free, currently 301s —
  followed automatically): returns author name, profile URL, and full tweet
  HTML. It does **not** return the avatar or an exact timestamp (date only,
  so imported tweets default to 00:00 UTC on the tweet date; edit if the time
  matters).
- **OG metadata** is only served to non-browser user agents (the plugin
  fetches with the WordPress UA). Browser UAs get an empty JS shell.
- **Avatar**: only proven when `og:image` is a `pbs.twimg.com/profile_images/…`
  URL (text-only tweets). Tweets with photos/video use the media as `og:image`,
  so those imports have no avatar candidate — use the identity store or pick
  one manually. Avatar URLs are served by X's CDN and can rot if the person
  changes their avatar; the Media Library option exists for durability.
- `og:description` can be truncated; the oEmbed text is preferred.
- Deleted/protected tweets fail the fetch and surface as manual-review rows.
- Article links inside tweet text stay as `t.co` short links (X does not
  expand them in oEmbed); verification uses the expanded URLs X embeds in its
  server-rendered page HTML.

## LinkedIn

- Logged-out access is inconsistent: many `/posts/…` and `/feed/update/…`
  URLs return the full page with JSON-LD (best case — verified live against
  a real public activity page on 2026-08-18), others an authwall or HTTP
  999. Blocked pages degrade to manual-review rows with the handle/profile
  candidates derived from the URL. **No cookies, tokens, or automation are
  ever used.**
- When JSON-LD is served, the post's `SocialMediaPosting` block is rich: the
  `Person` author (name, profile URL, real profile photo), full
  `articleBody`, exact `datePublished`, and the public comment thread
  (author names, text, dates — but no comment IDs or profile links).
- **Link rewriting:** LinkedIn wraps outbound links in `lnkd.in` short links
  (200 interstitial pages, not redirects). Verification expands up to five
  short links per source and checks the interstitial/redirect destination,
  so a post whose only article link is `lnkd.in`-wrapped still verifies as
  a Webmention (flagged in the preview warnings).
- **Comment permalinks** (`…?commentUrn=urn:li:comment:(activity:…,…)`)
  dedupe as their own remote item, and never inherit the post author's
  identity. Because public JSON-LD comments carry no IDs, the exact comment
  cannot be auto-matched: the preview lists the public commenter names and
  the reviewer fills in the author and text.
- `og:image` is a share card or company branding — never used as an avatar.
- `og:description` is usually truncated; JSON-LD `articleBody` is preferred.

## Generic (other sites)

- Conservative: JSON-LD `Person` author, `author` meta, OG description, and
  `article:published_time` only. No avatar unless JSON-LD asserts one.
  Sites with Microformats2 are better served by sending a real webmention
  through the Webmention plugin itself.

# Cloudflare R2

R2 is S3-compatible for everything this library does, with one significant
exception: **access control**. Reads, writes, prefix listings, batch deletes,
`Content-Type` and `Cache-Control` all behave as they do on S3.

## Configuration

```php
new FoF\CloudAssets\Extend\CloudAssets(
    new FoF\CloudAssets\Config\R2Config(
        accountId: \getenv('R2_ACCOUNT_ID'),
        bucket: 'forum-public',
        privateBucket: 'forum-private',
        credentials: Credentials::keyPair(
            \getenv('R2_ACCESS_KEY_ID'),
            \getenv('R2_SECRET_ACCESS_KEY'),
        ),
        url: 'https://cdn.example.com',
    )
),
```

`key` and `secret` are the **Access Key ID** and **Secret Access Key** shown
when you create an R2 API token (R2 → API → Manage API tokens). Take a copy of
the secret then: it is shown once.

The endpoint is derived from the account ID, and the region is always `auto`.

A bucket created in a jurisdiction answers only on that jurisdiction's
hostname, and the two buckets need not match — keeping personal data in the EU
while the compiled assets sit on the default endpoint is a reasonable
arrangement:

```php
jurisdiction: null,          // public bucket, default endpoint
privateJurisdiction: 'eu',   // private bucket, …eu.r2.cloudflarestorage.com
```

Measured against a real pair of buckets, each answers **only** on its own
endpoint — asking the default endpoint for an EU bucket returns `AccessDenied`,
and vice versa. That is what `privateJurisdiction` exists for.

## Tokens

One token can cover both buckets, or each can have its own.

When you create a token with **Object Read & Write** (or **Object Read only**),
Cloudflare lets you scope it to a set of buckets. The four permission levels
are *Admin Read & Write*, *Admin Read only*, *Object Read & Write* and *Object
Read only*; this library needs object read and write, never admin.

Giving the private bucket its own token is worth the extra step:

```php
privateCredentials: Credentials::keyPair(
    \getenv('R2_PRIVATE_ACCESS_KEY_ID'),
    \getenv('R2_PRIVATE_SECRET_ACCESS_KEY'),
),
```

The public bucket's credentials end up wherever assets are built — CI, deploy
containers, anything running `assets:publish`. The private bucket's need only
exist where Flarum streams a GDPR export. Separating them means a leaked build
credential cannot read personal data. Omit the two and the public credentials
are used for both.

A token scoped to buckets in one jurisdiction cannot reach buckets in another,
so where your two buckets differ by jurisdiction you will need two tokens
regardless.

`url` must be the [custom domain](https://developers.cloudflare.com/r2/buckets/public-buckets/)
bound to the public bucket. The `r2.dev` subdomain is rate-limited and
documented as development-only, so there is no usable default.

There is no ACL option worth setting. R2 accepts `public-read`, `private` and
`bucket-owner-full-control` and ignores all three — an object written `private`
is still served to anyone — while `authenticated-read` and anything
unrecognised return `NotImplemented`. `R2Config` never sends one.

## Two buckets are required

R2 makes a bucket public by binding a custom domain to it, and that exposes the
**whole bucket**. There is no prefix scoping, no bucket policy
(`PutBucketPolicy` answers `NotImplemented`), and no usable object ACL. So a
single bucket cannot hold both the compiled assets and the private disks —
GDPR exports, private attachments, images from private channels.

`R2Config` refuses `privateBucket` set to the same name as `bucket` for that
reason. The options are:

```php
privateBucket: 'forum-private',   // a second bucket, with no domain bound
privateBucket: null,              // keep those files on the local filesystem
```

A second bucket needs no policy and no Worker: it is private because nothing
points at it. Bucket-level access *can* be gated at the edge with a Worker, WAF
rules or Cloudflare Access, but all of those are configuration this library
cannot see, let alone verify.

If you ran an earlier version, check what is already exposed:

```
php flarum cloud-assets:secure
```

## Disabling the r2.dev subdomain

Binding a custom domain does not close the development URL. If `r2.dev` public
access is left on, the bucket stays reachable through it regardless of any WAF
rule or Access policy on your domain. Turn it off in the bucket's public access
settings.

## CDN caching

There is nothing to configure on the bucket — the R2 settings page has no cache
options. Binding a
[custom domain](https://developers.cloudflare.com/r2/buckets/public-buckets/)
routes requests through that domain's **zone**, so caching is governed by the
zone's Cache Rules (Caching → Cache Rules for the zone owning the hostname),
not by R2.

On a zone with a Cache Rule covering the bucket's hostname, the defaults work:
measured against a real bucket, `forum.js?v=<rev>` returns `MISS` on the first
request and `HIT` afterwards, carrying the `public, max-age=31536000, immutable`
the object was stored with. Nothing further is needed.

### Check Caching Level before trusting cache busting

One zone setting can actively break a rebuild. **Caching Level** (Caching →
Configuration) decides what the cache key is:

| Setting | Cache key | Effect on `forum.js?v=<rev>` |
| --- | --- | --- |
| Standard *(default)* | the full URL | Cached, one entry per revision |
| Ignore query string | `forum.js`, revision discarded | Cached, but **serves the wrong bundle** |
| No query string | only URLs without a query | Never cached |

Leave it on **Standard**. Cloudflare's
[own guidance](https://developers.cloudflare.com/cache/how-to/set-caching-levels/)
suggests *Ignore query string* for cache busting, which is wrong here: Flarum's
`?v=` is a hash of the compiled output, not an incidental parameter. Collapsing
revisions to one cache key means a rebuild leaves the edge serving the previous
bundle under the new URL — for up to a year, given the `immutable` header these
files carry.

Caching Level applies to the whole zone. To contain a change to the bucket's
hostname, add a Cache Rule matching it instead — a rule overrides the global
setting.

### Edge TTL

If you add a Cache Rule, set Edge TTL to **Respect origin headers** so the
per-file tiers this library sets are the ones that apply. The three modes are:

- *Respect origin headers* — use the object's own `Cache-Control` (what you want)
- *Override origin* — ignore it and use a fixed TTL
- *Bypass if absent* — use it if present, otherwise do not cache

An *Override origin* TTL is the thing to check if `forum.js` comes back with a
`max-age` that is not a year: it silently replaces the header regardless of what
was stored.

### Verifying

```
curl -sD - -o /dev/null --compressed 'https://cdn.example.com/assets/forum.css?v=<rev>' \
  | grep -iE 'cache-control|cf-cache-status|^age:'
```

Ask twice. The second response should be `HIT`, and the `cache-control` should
be the one this library set.

**Use GET, not HEAD.** `curl -I` sends a HEAD request, and Cloudflare never
serves those from cache — every one reports `DYNAMIC`, whatever the true cache
state is. Checking that way makes a perfectly healthy zone look like it is
caching nothing, and sends you off configuring rules that were already correct.
The `-o /dev/null` above keeps a GET's body off your terminal without turning it
into a HEAD.

## Access control

R2 has **no object-level access control**. From the
[S3 API compatibility list](https://developers.cloudflare.com/r2/api/s3/api/),
`PutObjectAcl` and `GetObjectAcl` are unimplemented, and `x-amz-acl` and the
`x-amz-grant-*` headers are unsupported on `PutObject` and `CopyObject`.
[Public access](https://developers.cloudflare.com/r2/buckets/public-buckets/) is
a per-bucket switch, and bucket policies are not implemented either.

So a bucket is public or it is not, and nothing this library can send will make
one object within a public bucket private. That is why private disks go in a
[second bucket](#two-buckets-are-required) — or stay on the filesystem.

If you would rather keep one bucket and gate access at the edge, Cloudflare
documents several approaches — a
[Worker](https://developers.cloudflare.com/r2/api/workers/workers-api-usage/) in
front of the bucket,
[WAF token authentication](https://developers.cloudflare.com/waf/custom-rules/use-cases/configure-token-authentication/),
or [Cloudflare Access](https://developers.cloudflare.com/r2/tutorials/cloudflare-access/).
This library cannot verify any of them, so it will not let you name one bucket
for both trees on R2; if you build that yourself, keep the private disks local
and serve them your own way.

Note that [presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/)
work only against the S3 API endpoint, never a custom domain — which is why
private files here are streamed through Flarum after an access check rather than
linked to.

## CORS

Assets served from a different hostname than the forum are cross-origin, and
fonts in particular will not load without CORS headers. R2 sets these from the
bucket's **CORS Policy** (R2 → bucket → Settings), not from anything this
library sends.

A policy allowing `GET` and `HEAD` from `*` is enough for public assets:

| Allowed origins | Allowed methods | Allowed headers |
| --- | --- | --- |
| `*` | `GET`, `HEAD` | `*` |

Restrict the origins to your forum's hostname if you prefer; fonts and
stylesheets need nothing else.

## MIME types

libmagic misidentifies compiled assets — CSS comes back as `text/troff` and JS
as `text/x-c`. R2 stores and serves whatever it is given, and a browser sent
`text/troff` for a stylesheet under `X-Content-Type-Options: nosniff` refuses to
apply it, so the forum renders unstyled.

The library adds those types to the MIME detector's inconclusive list so the
filename extension decides instead. Nothing to configure; noted because it looks
inexplicable when it happens.

## Costs

Class A operations (writes, listings) are billed; Class B (reads) are cheaper,
and egress is free. Two things this library does deliberately:

- Rebuilds use two prefix listings rather than one `HeadObject` per file, which
  turns ~97 Class B operations into 2 Class A.
- `cloud-assets:copy` skips anything a rebuild or `assets:publish` would write,
  so adopting the bucket does not upload the same file twice.

A forced rebuild (`cache:clear --force`) rewrites every bundle — around 190
writes. It is a repair path, not something to run routinely.

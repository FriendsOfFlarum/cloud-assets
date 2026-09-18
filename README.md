# Cloud Assets

Puts Flarum's disks on S3-compatible object storage — AWS S3, Cloudflare R2,
MinIO, DigitalOcean Spaces — so every instance of a multi-container forum reads
and writes the same files.

This is a library, not an extension. There is nothing to enable in the admin
panel; it is wired up from your `extend.php`.

## Why

Flarum writes compiled assets, avatars and uploads to the local filesystem. Run
more than one container and each has its own copy: an avatar uploaded on one is
missing on the others, and a rebuild triggered on one leaves the rest serving
whatever they last compiled. Putting the disks on shared storage is what makes
horizontal scaling work.

## Wiring it up

Name the service you are on and pass it what it needs, either directly as strings
or obtained from environment variables:

```php
// extend.php
return [
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
];
```

Nothing is read from the environment by the library. Where your secrets come
from is your deployment's business — `getenv()`, a secrets manager, or literals
in a file you do not commit.

### Providers

| Class | Needs | Notes |
| --- | --- | --- |
| `R2Config` | `accountId` | Endpoint and region are derived. `jurisdiction:` / `privateJurisdiction:` for jurisdiction-restricted buckets, which need not match |
| `AwsConfig` | `region` | `legacyAcls: true` only for a bucket old enough to still have ACLs enabled |
| `SpacesConfig` | `region` | e.g. `ams3`. Endpoint derived |
| `MinioConfig` | `endpoint` | Path-style addressing by default |
| `GenericConfig` | `endpoint` | Anything else; assumes the least of it |

All of them take `bucket` and `credentials`, and optionally `privateBucket`,
`url`, `options` and `cacheControl`.

### Credentials

Every service takes an access key and secret:

```php
credentials: Credentials::keyPair($key, $secret),
```

AWS also accepts a session token for temporary STS credentials, a named profile
from `~/.aws`, or nothing at all — in which case the SDK resolves an identity
the way it always does: the environment, the shared config files, then an ECS
task role, an EC2 instance profile, or a web identity on EKS.

```php
credentials: Credentials::keyPair($key, $secret, $sessionToken),
credentials: Credentials::profile('forum-assets'),
credentials: Credentials::fromEnvironment(),
```

`fromEnvironment()` is worth reaching for wherever the platform already hands
the container an identity — there is then no key to place, rotate or leak. The
other providers issue a key and a secret and have nowhere else to look, so they
refuse it rather than failing later with an unhelpful error.

The private bucket can have credentials of its own, with `privateCredentials`.
Worth separating: the public bucket's key ends up wherever assets get built —
CI, deploy containers — while the private one need only exist where Flarum
streams a GDPR export. Omit it and the public credentials are used for both.

`url` is the public address files are served from — a CDN hostname in front of
the bucket. `AwsConfig`, `SpacesConfig` and `GenericConfig` fall back to the
service's own address for the bucket; R2 has none that is usable in production,
so give it one.

Running something S3-compatible that is not listed? Extend whichever provider it
behaves most like:

```php
class CephConfig extends FoF\CloudAssets\Config\GenericConfig
{
    public function label(): string
    {
        return 'Ceph RGW';
    }

    public function supportsPrefixScopedAccess(): bool
    {
        return true;   // bucket policies honour prefix ARNs
    }
}
```

### Private files need somewhere private

GDPR exports, private attachments and images posted in private channels live on
disks under `storage/`. They must not be in a bucket that has a hostname
pointing at it, and no provider can reliably serve one prefix of a bucket while
refusing another — R2 cannot do it at all, and per-object ACLs are rejected by
AWS and silently ignored by R2.

So `privateBucket` is how you say where they go:

```php
privateBucket: 'forum-private',   // a second bucket, with no domain in front
privateBucket: null,              // leave them on the local filesystem
```

**Two buckets is the arrangement to start from.** A bucket with no public
hostname and no read grant is private by construction — there is no
configuration whose correctness has to be maintained, so nothing can drift or
be widened by mistake.

Naming the **same bucket** for both is supported on AWS, MinIO and Spaces,
where a policy can deny the private prefix. It is refused on R2 and
`GenericConfig`, which cannot express that.

```php
new AwsConfig(
    bucket: 'forum',
    privateBucket: 'forum',   // deliberate — see docs/amazon-s3.md
    region: 'eu-west-1',
    credentials: Credentials::fromEnvironment(),
)
```

One bucket is safe by *configuration* rather than by construction: its safety
depends on a deny statement staying correct for the life of the bucket. The
library cannot verify that the policy exists — your credentials may not be
allowed to read it — so naming one bucket twice is you asserting that it does.
[docs/amazon-s3.md](docs/amazon-s3.md) sets out what the policy must say, why a
deny rather than a scoped allow, and how Origin Access Control can quietly
undo it.

With `privateBucket: null` those disks stay local, which is right for a single
instance serving its assets from a CDN. On several instances it means an export
written by one is not readable by another.

Already been running an earlier version, or any hand-rolled equivalent? Your
private files are probably in the public bucket already:

```
php flarum cloud-assets:secure          # shows what is exposed
php flarum cloud-assets:secure --force  # moves it, verifying before deleting
```

### Which disks move

Every registered disk, including those declared by extensions — which is the
point, since a file written by one container has to be readable by the others.
Extensions register disks at boot and cannot be listed in advance, so this is
not a list you maintain.

To leave one behind:

```php
(new FoF\CloudAssets\Extend\CloudAssets($config))
    ->keepLocal('some-scratch-disk'),
```

## Adopting it on an existing forum

The bucket starts empty. Everything the forum has accumulated — avatars,
uploads, generated images, your logo — is on local disk, and the disks now read
from a bucket that has never seen it.

```
php flarum cloud-assets:copy          # shows what it would copy
php flarum cloud-assets:copy --force  # uploads it
php flarum cache:clear                # compiles the bundles into the bucket
php flarum assets:publish             # fonts and extension assets
```

`cloud-assets:copy` skips anything `cache:clear` or `assets:publish` will write
anyway, so it only moves what nothing else can regenerate. It never deletes
local files.

### Going back

If you leave cloud storage — scaling back to one instance, changing provider,
or deciding the bucket was not worth it — run this while it is still configured,
because that is what tells the command where the files are:

```
php flarum cloud-assets:restore --force   # downloads everything to local disk
                                          # then take the config out of extend.php
php flarum cache:clear                    # rebuilds the bundles locally
```

Nothing is deleted from the bucket.

## Cache headers

Objects carry their own `Cache-Control`, set when written — there is no web
server in front of them to add headers per request. One value cannot suit every
file, so it is chosen per file:

| | `max-age` |
| --- | --- |
| `.css`, `.js`, `.mjs`, `.wasm` | 1 year, `immutable` |
| fonts, images, video, audio, `.pdf`, `.map` | 30 days |
| `.ico`, `.cur` | 7 days |
| `.html`, `.json`, `.xml`, `.txt`, `.md` | `no-cache` |
| anything else | 30 days |

A year on JS and CSS is safe because Flarum content-addresses them: a bundle is
requested as `forum.js?v=<hash of the compiled output>`, and font URLs inside a
stylesheet carry a revision of the font file. A changed file is always a changed
URL. `no-cache` on XML matters for sitemaps, which are regenerated at a stable
URL.

Override any of them, keyed by extension. `''` sets the fallback for anything
not listed:

```php
new R2Config(
    // …
    cacheControl: ['pdf' => 604800, '' => 86400],
)
```

## Provider notes

What differs between services is access control. `Content-Type`,
`Cache-Control`, prefix listing and batch deletes behave the same everywhere,
and each config class already knows what its service can do:

| | Object ACLs | Public access granted by | One bucket? |
| --- | --- | --- | --- |
| Cloudflare R2 | ignored | custom domain — whole bucket | no |
| Amazon S3 | rejected since 2023 | bucket policy | yes |
| MinIO | `501 NotSupported` | `mc anonymous set` | yes |
| DigitalOcean Spaces | supported | per-object ACL, or policy | yes |

- [Cloudflare R2](docs/cloudflare-r2.md) — two buckets required, CDN cache rules, the `curl -I` trap
- [Amazon S3](docs/amazon-s3.md) — bucket policies, ACLs disabled since 2023
- [MinIO](docs/minio.md) — path-style addressing, `mc anonymous` prefix policies
- [DigitalOcean Spaces](docs/digitalocean-spaces.md) — the one provider where ACLs are the mechanism

## Performance

Rebuilding assets asks whether each compiled file is still in the bucket, which
is one request per file — around a hundred on a forum with code splitting, and
more with every extension that adds a lazy chunk. Against a remote bucket that
dominates the time a rebuild takes.

The assets disk answers those from two prefix listings instead. Measured against
R2 with 97 compiled files: 97 requests and 13.2s became 2 requests and 3.7s.

Uploads are batched, too. A rebuild writes each file as it finishes it, and
against a bucket every one of those is a round trip the next write waits for —
2.7s of a 4.1s admin rebuild, none of it bandwidth. They are now sent together,
six at a time, for as long as a rebuild runs: `cache:clear --force` went from
37s to 14s, and enabling an extension from 6.5s to under a second.

## Requirements

Flarum 2.0 or later. Earlier versions recorded asset revisions in a file beside
the assets, which cannot work when that file is in a bucket; 2.0 keeps them in
the database.

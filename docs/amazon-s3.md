# Amazon S3

The reference implementation — the AWS SDK's defaults apply and no endpoint is
needed.

## Configuration

```php
new FoF\CloudAssets\Extend\CloudAssets(
    new FoF\CloudAssets\Config\AwsConfig(
        bucket: 'forum-public',
        privateBucket: 'forum-private',
        region: 'eu-west-1',
        credentials: Credentials::fromEnvironment(),
        url: 'https://cdn.example.com',
    )
),
```

No endpoint is needed — the SDK addresses AWS from the region.

Two buckets is the arrangement to start from; see
[Two buckets, or one](#two-buckets-or-one) for why, and for what a single
bucket requires if you need one.

`Credentials::fromEnvironment()` leaves the SDK to resolve an identity itself:
the environment, then the shared config files, then an ECS task role, an EC2
instance profile, or a web identity token on EKS. On anything running inside
AWS that is the one to prefer — no key is placed anywhere, and nothing expires
that something has to renew.

Where a key is what you have, or the deployment is outside AWS:

```php
credentials: Credentials::keyPair($key, $secret),
credentials: Credentials::keyPair($key, $secret, $sessionToken),  // STS
credentials: Credentials::profile('forum-assets'),                // ~/.aws
```

A role-based deployment needs the role itself to grant `s3:GetObject`,
`s3:PutObject`, `s3:DeleteObject` and `s3:ListBucket` — see
[IAM permissions](#iam-permissions) below. A task role that grants none of them
is the common reason a deployment falls back to a static key.

With no `url`, public URLs are derived as
`https://<bucket>.s3.dualstack.<region>.amazonaws.com`, which resolves over IPv6
as well as IPv4. Set it to a CloudFront distribution if you have one.

## Two buckets, or one

**Use two buckets unless you have a reason not to.** Both arrangements work on
S3, but they fail differently, and the difference is not a matter of degree.

### Two buckets — safe by construction

```php
bucket: 'forum-public',
privateBucket: 'forum-private',
```

The private bucket has no public hostname, no CDN in front of it, and no
statement granting anyone read access. There is no configuration whose
correctness has to be maintained, so there is nothing to drift, be overwritten,
or be widened by mistake.

The worst thing a policy error on the *public* bucket can do is expose public
assets, which are already public.

### One bucket — safe by configuration

```php
bucket: 'forum',
privateBucket: 'forum',   // deliberate: see the policy below
```

Naming the same bucket twice tells the library you intend one bucket and have
walled off the private prefix yourself. It works, and it is verified to work
(see below) — but its safety now depends on a policy statement staying correct
for the life of the bucket.

Ways that has gone wrong in practice:

- the policy is replaced or widened by someone solving an unrelated problem
- infrastructure-as-code drifts, or a stack update overwrites it
- a bucket is recreated from a template that predates the rule
- **Origin Access Control is added to CloudFront later** — see the warning below

Each of those silently starts serving personal-data exports. Nothing alerts
you; the files simply become readable.

### If you use one bucket, deny the private prefix

Add this to the bucket policy **in addition to** whatever grants public read:

```json
{
  "Sid": "NeverServePrivateDiskFiles",
  "Effect": "Deny",
  "Principal": "*",
  "Action": ["s3:GetObject", "s3:GetObjectVersion"],
  "Resource": "arn:aws:s3:::forum/storage/*",
  "Condition": {
    "StringNotEquals": { "aws:PrincipalAccount": "111122223333" }
  }
}
```

Replace `111122223333` with the account the forum's own credentials belong to.
The condition is what keeps the application able to read and write its own
private files while everyone else is refused.

An explicit `Deny` overrides every `Allow`, including one added later by
mistake. That is the property that makes this arrangement defensible at all.

#### Deny, not a scoped allow

It is tempting to scope the `Allow` to `assets/*` instead and leave it there.
Do not — an allow-list has to name every public prefix, and that list is not
stable. A real forum's public keys include `assets/`, `sitemaps/`, a root
`logo.svg`, and one prefix per day of uploads, growing indefinitely. Any
extension may add another.

An allow-list therefore needs editing whenever anything is added, and the
pressure when a file unexpectedly 404s is to widen it back to `/*` — which
re-exposes everything. The deny needs no maintenance: it tracks the one rule
the library guarantees, that private disks are written under `storage/` and
nothing else is.

Verified against a real bucket. With the deny in place, a private disk no
extension had written before is protected without touching the policy, and a
new public disk still serves:

| Key | Result |
| --- | --- |
| `storage/some-new-extension/secret.txt` | **403** |
| `storage/deeply/nested/private/file.txt` | **403** |
| `assets/new-extension/img.png` | 200 |
| `sitemaps/new.xml` | 200 |
| `2026-09-18/upload.png` | 200 |

Bypass attempts — URL-encoded separators, double slashes, dot segments, case
changes, the path-style endpoint, the global endpoint, the dualstack endpoint,
and the CloudFront distribution — all returned `403`.

#### Warning: Origin Access Control breaks this

The deny above works partly because a CloudFront distribution without OAC
fetches from S3 **anonymously**, so `aws:PrincipalAccount` does not match it and
the deny applies.

If OAC or a legacy OAI is added later, CloudFront authenticates as a principal
in your account. The condition may then exempt it, and everything under
`storage/` becomes reachable through the CDN again.

If you use OAC, do not rely on this condition — use two buckets, or scope the
deny by `aws:PrincipalArn` so the distribution's identity is not exempted.

#### Migrating from an earlier setup

If this bucket was previously served by a hand-rolled S3 integration, it may
contain private files under keys such as `var/www/storage/…`, written by a path
mapping that failed to strip the container's filesystem prefix. The deny above
will not match those. Add a second resource pattern while they remain:

```json
"Resource": [
  "arn:aws:s3:::forum/storage/*",
  "arn:aws:s3:::forum/*/storage/*"
]
```

`storage/*` and `*/storage/*` are mutually exclusive — neither matches the
other's key shape — so both are needed until the legacy keys are gone.

This library only ever writes private keys beginning `storage/`, so once those
files are moved or removed the second pattern matches nothing and should be
dropped.

To find and move them:

```
php flarum cloud-assets:secure
```

## ACLs are disabled by default

Buckets created since April 2023 have Object Ownership set to *Bucket owner
enforced*, which disables ACLs entirely. A `PutObject` carrying `x-amz-acl` is
then **rejected outright** — `400 AccessControlListNotSupported` — so sending
one does not fail to help, it fails the upload.

`AwsConfig` therefore sends no ACL. Access is granted by bucket policy, as
above. A bucket old enough to still have ACLs enabled can opt back in:

```php
new AwsConfig(
    // …
    legacyAcls: true,
    options: ['ACL' => 'public-read'],
)
```

**Block Public Access is on by default** and overrides both ACLs and policies.
It has to be turned off for the bucket to serve anything publicly, which is a
deliberate decision rather than an oversight to work around.

## IAM permissions

The credentials need, on the bucket and its contents:

```
s3:GetObject  s3:PutObject  s3:DeleteObject  s3:ListBucket
```

`s3:ListBucket` is on the bucket ARN, the rest on `<bucket>/*`. Batched deletes
(`DeleteObjects`) and prefix listings need no additional permission.

## CloudFront

If a distribution fronts the bucket, the same caching consideration as R2
applies: forward the query string, or `forum.js?v=<revision>` requests will not
be cached. In a cache policy that is *Query strings: All*.

CloudFront respects the origin's `Cache-Control` by default, so the tiers this
library sets apply without further configuration.

## Costs

`PUT`, `LIST` and `POST` are billed at a higher rate than `GET`, and egress is
billed unless CloudFront fronts the bucket. Rebuilds use two prefix listings
rather than one `HeadObject` per file, so the per-rebuild cost is a couple of
`LIST` calls plus a `PUT` for each file that genuinely changed.

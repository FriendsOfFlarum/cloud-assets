# DigitalOcean Spaces

The one service here where per-object ACLs are the documented mechanism.

## Configuration

```php
new FoF\CloudAssets\Extend\CloudAssets(
    new FoF\CloudAssets\Config\SpacesConfig(
        region: 'ams3',
        bucket: 'forum',
        privateBucket: 'forum',
        credentials: Credentials::keyPair(
            \getenv('SPACES_KEY'),
            \getenv('SPACES_SECRET'),
        ),
    )
),
```

The endpoint is derived from the region, and with no `url` public addresses are
derived as `https://<bucket>.<region>.digitaloceanspaces.com`. Set `url` to the
CDN hostname if you have the CDN enabled.

## Access control

Spaces supports exactly two canned ACLs — `private` and `public-read` — and
documents the `x-amz-acl` header as the simpler and recommended way to apply
them. `SpacesConfig` is the only provider class here that reports ACLs as
usable.

Because the ACL travels with each object, one Space can hold both trees:

```php
bucket: 'forum',
privateBucket: 'forum',
```

Bucket policies exist too, and are API-only — not configurable from the control
panel. Prefix-scoped resource ARNs are not documented, so the per-object ACL is
the path this relies on.

Two Spaces work as well if you would rather the separation were structural:

```php
bucket: 'forum-public',
privateBucket: 'forum-private',
```

If you ran an earlier version, check what is already in the public Space:

```
php flarum cloud-assets:secure
```

## CDN

The built-in CDN has a default edge TTL of one hour and respects the origin's
`Cache-Control`, so the per-file tiers this library sets apply.

Two documented limitations, neither of which affects this library today but both
worth knowing:

- Presigned URLs are **never** served from the CDN cache — each request goes to
  the origin.
- Presigned URLs generated with **path-style** addressing do not work against a
  CDN hostname at all. `SpacesConfig` uses virtual-host style, so this does not
  arise unless you override it.

Private files here are streamed through Flarum after an access check rather than
linked to, so neither limitation is on the serving path.

## Costs

Spaces bills a flat subscription including a storage and transfer allowance,
with overage beyond it. Rebuilds use two prefix listings rather than one
`HeadObject` per file, and uploads are batched, so a rebuild that changes
nothing costs two `LIST` calls.

# MinIO

Self-hosted, so there is no hostname to recognise — using `MinioConfig` is how
a deployment says it is running MinIO.

## Configuration

```php
new FoF\CloudAssets\Extend\CloudAssets(
    new FoF\CloudAssets\Config\MinioConfig(
        endpoint: 'https://minio.internal:9000',
        bucket: 'forum',
        privateBucket: 'forum',
        credentials: Credentials::keyPair(
            \getenv('MINIO_ACCESS_KEY'),
            \getenv('MINIO_SECRET_KEY'),
        ),
        url: 'https://cdn.example.com',
    )
),
```

`url` is worth setting: under path-style addressing there is no derivable public
address, and a self-hosted MinIO is as likely to be private as not.

## Path-style addressing

On by default here, because it is MinIO's own default. Virtual-host style
(`bucket.minio.example.net`) needs three things on the server side:

- `MINIO_DOMAIN` set to the FQDN
- a wildcard DNS record resolving `*.minio.example.net`
- a TLS certificate whose SANs cover both the bare and the wildcard names

If you have all three, turn it off:

```php
pathStyle: false,
```

## One bucket or two

MinIO's anonymous policies take a prefix, so one bucket can hold both trees:

```
mc anonymous set download myminio/forum/assets
```

That grants unauthenticated read on `assets/*` and says nothing about
`storage/*`, which stays private. Name the same bucket for both to use that
layout:

```php
bucket: 'forum',
privateBucket: 'forum',
```

The library cannot verify the policy — it is set out of band with `mc`, not
through the S3 API — so naming one bucket twice is you asserting it is in place.
Check it with:

```
mc anonymous get myminio/forum/assets
mc anonymous get myminio/forum/storage
```

Two buckets work as well, and need only the public one to be anonymous:

```php
bucket: 'forum-public',
privateBucket: 'forum-private',
```

## ACLs are not supported

`PutObjectAcl` and `GetObjectAcl` both answer `501 NotSupported`, and MinIO's
documentation points at policies instead. What MinIO does with an `x-amz-acl`
header on an ordinary `PutObject` is not documented anywhere, which is reason
enough not to depend on it — `MinioConfig` sends none.

## Permissions

The credentials need, on the bucket and its contents:

```
s3:GetObject  s3:PutObject  s3:DeleteObject  s3:ListBucket
```

MinIO policy documents use the same schema as AWS IAM, so `s3:ListBucket` goes
on the bucket ARN and the rest on `<bucket>/*`.

## MIME types

MinIO stores and serves the `Content-Type` it is given. libmagic reports CSS as
`text/troff` and JS as `text/x-c`, and a browser sent `text/troff` for a
stylesheet under `X-Content-Type-Options: nosniff` refuses to apply it — the
forum renders unstyled. This library overrides the detector so the filename
extension decides, which is what makes assets work here at all.

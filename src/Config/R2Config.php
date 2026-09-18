<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Config;

use InvalidArgumentException;

/**
 * Cloudflare R2.
 *
 * Two buckets are required for a forum with private files. R2 makes a bucket
 * public by binding a custom domain to it, and that exposes the whole bucket —
 * there is no prefix scoping, no bucket policy (`PutBucketPolicy` answers
 * `NotImplemented`), and no usable object ACL: `public-read`, `private` and
 * `bucket-owner-full-control` are all accepted and all ignored, so an object
 * written `private` is still served to anyone who asks. `authenticated-read`
 * and anything unrecognised return `NotImplemented`.
 *
 * Bucket-level access can be gated at the edge with a Worker, WAF rules or
 * Cloudflare Access, but all of those are configuration this package cannot
 * see, let alone verify. A second bucket with no domain in front of it needs
 * none of them, and cannot be got wrong.
 *
 *     new R2Config(
 *         accountId: '…',
 *         bucket: 'forum-public',
 *         privateBucket: 'forum-private',
 *         key: '…',
 *         secret: '…',
 *         url: 'https://cdn.example.com',
 *     )
 *
 * Note that presigned URLs on R2 only work against the S3 API endpoint, never
 * a custom domain — which is why private files here are streamed through
 * Flarum rather than linked to.
 */
class R2Config extends StorageConfig
{
    /**
     * @param string $accountId  the Cloudflare account ID, which forms the
     *                           endpoint hostname
     * @param string|null $jurisdiction `eu` or `fedramp` for a public bucket
     *                           created in a jurisdiction, which changes the
     *                           endpoint it answers on
     * @param string|null $privateJurisdiction the same for the private bucket,
     *                           which need not match: keeping personal data in
     *                           the EU while the compiled assets sit on the
     *                           default endpoint is a reasonable arrangement,
     *                           and the two buckets are then reached at
     *                           different hostnames
     * @param array<string, mixed> $options
     * @param array<string, int> $cacheControl
     */
    public function __construct(
        public readonly string $accountId,
        string $bucket,
        Credentials $credentials,
        ?string $privateBucket = null,
        ?string $url = null,
        public readonly ?string $jurisdiction = null,
        public readonly ?string $privateJurisdiction = null,
        ?Credentials $privateCredentials = null,
        array $options = [],
        array $cacheControl = [],
    ) {
        if ($accountId === '') {
            throw new InvalidArgumentException('An R2 account ID is required.');
        }

        parent::__construct(
            bucket: $bucket,
            credentials: $credentials,
            privateBucket: $privateBucket,
            url: $url,
            // R2 has one region, and the SDK still wants the field set.
            region: 'auto',
            privateCredentials: $privateCredentials,
            options: $options,
            cacheControl: $cacheControl,
        );
    }

    public function endpoint(string $bucket): ?string
    {
        $jurisdiction = $bucket === $this->privateBucket && $bucket !== $this->bucket
            ? $this->privateJurisdiction
            : $this->jurisdiction;

        $host = $jurisdiction === null
            ? $this->accountId
            : $this->accountId.'.'.$jurisdiction;

        return "https://$host.r2.cloudflarestorage.com";
    }

    public function label(): string
    {
        return 'Cloudflare R2';
    }

    /**
     * A bucket has no address until a custom domain is bound to it, and that
     * domain is not derivable from anything here — the `r2.dev` subdomain is
     * rate limited and documented as development-only. So a public URL has to
     * be given.
     */
    protected function defaultUrl(): ?string
    {
        return null;
    }
}

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
 * Amazon S3.
 *
 * The one service where a single bucket is straightforward: a bucket policy
 * can grant `s3:GetObject` to everyone on `arn:aws:s3:::<bucket>/assets/*`
 * and say nothing about `storage/*`, which then stays unreachable. Name the
 * same bucket for both to use that layout:
 *
 *     new AwsConfig(
 *         bucket: 'forum',
 *         privateBucket: 'forum',      // same bucket, walled off by policy
 *         region: 'eu-west-1',
 *         key: '…',
 *         secret: '…',
 *     )
 *
 * Two buckets work as well, and need no policy at all beyond making the public
 * one public. Which to prefer is a question of how much you trust the policy
 * to stay correct: a prefix rule that is edited, replaced by a broader one, or
 * lost in a stack update starts serving GDPR exports, whereas a bucket nothing
 * points at cannot.
 *
 * ACLs are not used. Since April 2023 new buckets are created with Object
 * Ownership set to `BucketOwnerEnforced`, which disables them — a PUT carrying
 * `x-amz-acl` is then rejected with `400 AccessControlListNotSupported`, so
 * sending one does not fail to help, it fails the upload. A bucket old enough
 * to still honour ACLs can pass one in `options`, which is then sent as given.
 */
class AwsConfig extends StorageConfig
{
    /**
     * @param bool $legacyAcls  set on a bucket created before April 2023 that
     *                          still has ACLs enabled, to send the `ACL` given
     *                          in `$options`. On a modern bucket this makes
     *                          every upload fail.
     * @param array<string, mixed> $options
     * @param array<string, int> $cacheControl
     */
    public function __construct(
        string $bucket,
        string $region,
        Credentials $credentials,
        ?string $privateBucket = null,
        ?string $url = null,
        public readonly bool $legacyAcls = false,
        ?Credentials $privateCredentials = null,
        array $options = [],
        array $cacheControl = [],
    ) {
        if ($region === '') {
            throw new InvalidArgumentException('An AWS region is required.');
        }

        parent::__construct(
            bucket: $bucket,
            credentials: $credentials,
            privateBucket: $privateBucket,
            url: $url,
            region: $region,
            privateCredentials: $privateCredentials,
            options: $options,
            cacheControl: $cacheControl,
        );
    }

    /**
     * Null: the SDK addresses AWS itself from the region.
     */
    public function endpoint(string $bucket): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Amazon S3';
    }

    public function supportsObjectAcls(): bool
    {
        return $this->legacyAcls;
    }

    /**
     * An S3 client given no credentials resolves them itself: the environment,
     * then the shared config files, then an ECS task role, an EC2 instance
     * profile, or a web identity on EKS. So a deployment running on AWS need
     * place no key anywhere.
     */
    public function supportsImplicitCredentials(): bool
    {
        return true;
    }

    /**
     * True regardless of whether ACLs can be set: `GetObjectAcl` still answers
     * on a bucket where writing one is rejected.
     */
    public function supportsVisibilityChecks(): bool
    {
        return true;
    }

    /**
     * Bucket policies can name a prefix, so one bucket can serve `assets/*`
     * publicly while `storage/*` stays private.
     */
    public function supportsPrefixScopedAccess(): bool
    {
        return true;
    }

    /**
     * The bucket's own hostname, for an install with no CDN in front of it.
     * Dualstack so it resolves over IPv6 as well.
     */
    protected function defaultUrl(): ?string
    {
        return sprintf('https://%s.s3.dualstack.%s.amazonaws.com', $this->bucket, $this->region);
    }
}

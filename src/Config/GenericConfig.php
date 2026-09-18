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
 * Any other S3-compatible service.
 *
 * Assumes the least of whatever it is talking to: no object ACLs, no way to
 * read a visibility back, and no prefix-scoped public access. That is the safe
 * reading of an unknown service, not a claim about it — a service that cannot
 * do these things behaves correctly, and one that can is merely not asked to.
 *
 * The practical effect is that private files need a second bucket, which works
 * everywhere. A service that genuinely supports prefix-scoped access can say
 * so by subclassing:
 *
 *     class CephConfig extends GenericConfig
 *     {
 *         public function label(): string
 *         {
 *             return 'Ceph RGW';
 *         }
 *
 *         public function supportsPrefixScopedAccess(): bool
 *         {
 *             return true;   // bucket policies, prefix ARNs honoured
 *         }
 *     }
 *
 * Extending the provider a service is closest to is usually better than
 * starting here: a MinIO-compatible gateway gets MinIO's behaviour from
 * {@see MinioConfig} without restating it.
 */
class GenericConfig extends StorageConfig
{
    /**
     * @param string $endpoint  the service's S3 API address
     * @param bool $pathStyle   address buckets as `host/bucket/key` rather
     *                          than `bucket.host/key`. Needed by services with
     *                          no wildcard DNS for their buckets.
     * @param string|null $label how the service is named in the admin panel
     * @param array<string, mixed> $options
     * @param array<string, int> $cacheControl
     */
    public function __construct(
        public readonly string $endpoint,
        string $bucket,
        Credentials $credentials,
        ?string $privateBucket = null,
        ?string $url = null,
        ?string $region = null,
        public readonly bool $pathStyle = false,
        private readonly ?string $label = null,
        ?Credentials $privateCredentials = null,
        array $options = [],
        array $cacheControl = [],
    ) {
        if ($endpoint === '') {
            throw new InvalidArgumentException(
                'An endpoint is required: without one the SDK would talk to AWS. Use '.AwsConfig::class.' for that.'
            );
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

    public function endpoint(string $bucket): ?string
    {
        return $this->endpoint;
    }

    public function label(): string
    {
        return $this->label ?? 'S3-compatible';
    }

    public function usesPathStyleEndpoint(): bool
    {
        return $this->pathStyle;
    }

    /**
     * The endpoint with the bucket in front of it, which is where an object
     * sits on a service addressing buckets by subdomain. Null under path-style
     * addressing, where the bucket is part of the path and the service is as
     * likely to be private as not — give a `url` there.
     */
    protected function defaultUrl(): ?string
    {
        if ($this->pathStyle) {
            return null;
        }

        $parts = parse_url($this->endpoint);

        if (! isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return sprintf('%s://%s.%s%s', $scheme, $this->bucket, $parts['host'], $port);
    }
}

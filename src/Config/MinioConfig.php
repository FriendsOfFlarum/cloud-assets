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

/**
 * MinIO.
 *
 * Self-hosted, so there is no hostname to recognise — a deployment says it is
 * running MinIO by using this class.
 *
 * Public access is granted by the operator, out of band, and can name a
 * prefix:
 *
 *     mc anonymous set download myminio/forum/assets
 *
 * so one bucket can hold both trees. The ACL operations are not implemented
 * (`PutObjectAcl` and `GetObjectAcl` both answer `501 NotSupported`) and the
 * documentation points at policies instead; what MinIO does with an
 * `x-amz-acl` header on an ordinary PutObject is not documented anywhere,
 * which is reason enough not to send one.
 *
 * Path-style addressing is the default here because it is MinIO's: virtual-host
 * style needs `MINIO_DOMAIN` set on the server, a wildcard DNS record, and a
 * certificate covering both the bare and wildcard names. Pass `pathStyle:
 * false` on a deployment that has all three.
 */
class MinioConfig extends GenericConfig
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, int> $cacheControl
     */
    public function __construct(
        string $endpoint,
        string $bucket,
        Credentials $credentials,
        ?string $privateBucket = null,
        ?string $url = null,
        ?string $region = null,
        bool $pathStyle = true,
        ?Credentials $privateCredentials = null,
        array $options = [],
        array $cacheControl = [],
    ) {
        parent::__construct(
            endpoint: $endpoint,
            bucket: $bucket,
            credentials: $credentials,
            privateBucket: $privateBucket,
            url: $url,
            region: $region,
            pathStyle: $pathStyle,
            privateCredentials: $privateCredentials,
            label: 'MinIO',
            options: $options,
            cacheControl: $cacheControl,
        );
    }

    /**
     * `mc anonymous set download <alias>/<bucket>/<prefix>` takes a prefix, and
     * MinIO's policies use the IAM schema, where a resource ARN may name one.
     */
    public function supportsPrefixScopedAccess(): bool
    {
        return true;
    }
}

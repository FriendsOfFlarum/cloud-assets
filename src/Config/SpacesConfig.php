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
 * DigitalOcean Spaces.
 *
 * The one service here where per-object ACLs are the documented mechanism:
 * `x-amz-acl` is described as the simpler and recommended way to make an
 * object readable. Only two canned values exist, `private` and `public-read`.
 *
 * Because the ACL travels with each object, a Space can hold both trees
 * without a policy — every object is written with its own visibility. Bucket
 * policies exist too, but prefix-scoped resource ARNs are not documented, so
 * the ACL is the path this relies on.
 *
 * One trap worth knowing: presigned URLs generated with path-style addressing
 * do not work against a CDN hostname, and presigned requests are never served
 * from the CDN cache. Neither affects this package — private files are
 * streamed through Flarum — but it will matter if that changes.
 */
class SpacesConfig extends GenericConfig
{
    /**
     * @param string $region a Spaces region such as `ams3` or `nyc3`
     * @param array<string, mixed> $options
     * @param array<string, int> $cacheControl
     */
    public function __construct(
        string $region,
        string $bucket,
        Credentials $credentials,
        ?string $privateBucket = null,
        ?string $url = null,
        ?Credentials $privateCredentials = null,
        array $options = [],
        array $cacheControl = [],
    ) {
        parent::__construct(
            endpoint: "https://$region.digitaloceanspaces.com",
            bucket: $bucket,
            credentials: $credentials,
            privateBucket: $privateBucket,
            url: $url,
            region: $region,
            pathStyle: false,
            privateCredentials: $privateCredentials,
            label: 'DigitalOcean Spaces',
            options: $options,
            cacheControl: $cacheControl,
        );
    }

    public function supportsObjectAcls(): bool
    {
        return true;
    }

    public function supportsVisibilityChecks(): bool
    {
        return true;
    }

    /**
     * Each object carries its own ACL, so one Space can serve the public tree
     * and withhold the private one without a policy naming either.
     */
    public function supportsPrefixScopedAccess(): bool
    {
        return true;
    }
}

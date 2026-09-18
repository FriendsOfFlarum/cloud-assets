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
 * Where the files go, and what the service holding them can do.
 *
 * One subclass per service, because the differences between them are not
 * settings — they are facts about what the service implements, and a site
 * should not have to know them. R2 ignores an ACL it accepts; AWS rejects one
 * outright on any bucket made since 2023; only Spaces treats them as the real
 * mechanism. Encoding that here means a deployment names its provider and gets
 * the right behaviour, rather than discovering it from a support thread.
 *
 * Values are passed in rather than read from the environment. A library that
 * reaches for `getenv()` decides the variable names for every site that uses
 * it, and has to guess among several prefixes when it finds more than one
 * filled in; passing them leaves the deployment in charge of where its own
 * secrets come from:
 *
 *     new CloudAssets(new R2Config(
 *         accountId: $_ENV['R2_ACCOUNT_ID'],
 *         bucket: 'forum-public',
 *         privateBucket: 'forum-private',
 *         key: $_ENV['R2_ACCESS_KEY_ID'],
 *         secret: $_ENV['R2_SECRET_ACCESS_KEY'],
 *         url: 'https://cdn.example.com',
 *     )),
 *
 * A service not covered here is {@see GenericConfig}, which assumes the least
 * of whatever it is talking to. A site running something closer to a provider
 * that *is* covered can extend that provider's class instead, and keep its
 * behaviour.
 */
abstract class StorageConfig
{
    /**
     * @param string $bucket         the bucket public files are served from
     * @param Credentials $credentials how the SDK is told who is asking
     * @param string|null $privateBucket bucket for files that must not be
     *                              reachable from the web. Naming the same
     *                              bucket as $bucket asks for one bucket with
     *                              the private prefix walled off by a policy —
     *                              see {@see supportsPrefixScopedAccess()}.
     *                              Null leaves private disks on the local
     *                              filesystem.
     * @param string|null $url      public base URL — a CDN hostname in front
     *                              of the bucket. Defaults to the service's
     *                              own address for the bucket where it has a
     *                              predictable one.
     * @param string|null $region
     * @param Credentials|null $privateCredentials credentials for the private
     *                              bucket, where it has its own. Worth
     *                              separating: the public bucket's key ends up
     *                              wherever assets are built — CI, deploy
     *                              containers — while the private one need only
     *                              exist where Flarum streams a GDPR export.
     *                              Defaults to the public credentials.
     * @param array<string, mixed> $options extra arguments sent with every write
     * @param array<string, int> $cacheControl max-age by file extension,
     *                              overriding the defaults; the key '' sets
     *                              the fallback for anything unlisted
     */
    public function __construct(
        public readonly string $bucket,
        public readonly Credentials $credentials,
        public readonly ?string $privateBucket = null,
        public readonly ?string $url = null,
        public readonly ?string $region = null,
        public readonly ?Credentials $privateCredentials = null,
        public readonly array $options = [],
        public readonly array $cacheControl = [],
    ) {
        if ($bucket === '') {
            throw new InvalidArgumentException('A bucket name is required.');
        }

        // Whether credentials may be left to the environment is the
        // provider's own business: AWS resolves them from a task role or an
        // instance profile, and nothing else here does.
        if (! $credentials->areExplicit() && ! $this->supportsImplicitCredentials()) {
            throw new InvalidArgumentException(sprintf(
                '%s has no way to discover credentials on its own, so an access key and secret are required. Use Credentials::keyPair().',
                $this->label()
            ));
        }

        if ($privateCredentials !== null && ! $privateCredentials->areExplicit() && ! $this->supportsImplicitCredentials()) {
            throw new InvalidArgumentException(sprintf(
                '%s has no way to discover credentials on its own, so the private bucket needs an access key and secret too.',
                $this->label()
            ));
        }

        if ($this->usesOneBucket() && ! $this->supportsPrefixScopedAccess()) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot serve one prefix of a bucket publicly while keeping another private, so `%s` cannot hold both public and private files. Name a separate private bucket, or leave privateBucket null to keep those files on the local filesystem.',
                $this->label(),
                $bucket
            ));
        }
    }

    /**
     * The S3 endpoint a bucket is reached at, or null to talk to AWS.
     *
     * Takes the bucket because the two need not share one. On R2 a bucket
     * created with a jurisdiction — `eu`, `fedramp` — answers only on that
     * jurisdiction's hostname, and a forum may reasonably keep its public
     * assets on the default endpoint while its private bucket is pinned to the
     * EU for the personal data in it.
     */
    abstract public function endpoint(string $bucket): ?string;

    abstract public function label(): string;

    /**
     * Whether the SDK can find credentials without being given any.
     *
     * True only on AWS, where an unconfigured client falls back to the
     * environment, the shared config files, and then to whatever identity the
     * platform has attached — an ECS task role, an EC2 instance profile, a web
     * identity on EKS. Every other service here issues a key and a secret and
     * has nowhere else to look.
     */
    public function supportsImplicitCredentials(): bool
    {
        return false;
    }

    /**
     * Whether an ACL sent with a write means anything.
     *
     * False almost everywhere, and for different reasons: AWS rejects the
     * request outright on a bucket with ACLs disabled, which has been the
     * default for new buckets since April 2023; R2 accepts the header and
     * ignores it, so an object written `private` is still served to anyone;
     * MinIO answers the ACL operations with `501 NotSupported`.
     */
    public function supportsObjectAcls(): bool
    {
        return false;
    }

    /**
     * Whether an object's visibility can be read back.
     *
     * Separate from setting one: AWS still answers `GetObjectAcl` on a bucket
     * where ACLs are disabled.
     */
    public function supportsVisibilityChecks(): bool
    {
        return false;
    }

    /**
     * Whether public access can be granted to part of a bucket.
     *
     * True where a policy can name a prefix — AWS bucket policies and MinIO's
     * anonymous policies both do. Where it is false the bucket is public or it
     * is not, so private files need a bucket of their own.
     *
     * This says the service *can* be configured that way, not that it has
     * been. Nothing here can check: the credentials may not be allowed to read
     * the policy, and on R2 the call is not implemented at all. Naming one
     * bucket for both is the operator saying they have done it.
     */
    public function supportsPrefixScopedAccess(): bool
    {
        return false;
    }

    /**
     * Whether path-style addressing is needed (`host/bucket/key` rather than
     * `bucket.host/key`).
     */
    public function usesPathStyleEndpoint(): bool
    {
        return false;
    }

    /**
     * Whether one bucket holds both the public and the private files.
     */
    public function usesOneBucket(): bool
    {
        return $this->privateBucket !== null && $this->privateBucket === $this->bucket;
    }

    /**
     * Whether private disks have somewhere shared to live.
     *
     * When false they stay on the local filesystem — right for a single
     * instance serving its assets from a CDN, and a split brain on several,
     * where one instance cannot read what another wrote.
     */
    public function hasPrivateStorage(): bool
    {
        return $this->privateBucket !== null;
    }

    /**
     * The disk configuration for public files.
     *
     * @return array<string, mixed>
     */
    public function publicDisk(): array
    {
        return $this->disk($this->bucket, $this->credentials)
            + ['url' => $this->url ?? $this->defaultUrl()];
    }

    /**
     * The disk configuration for files that must not be reachable, or null
     * when there is nowhere to put them.
     *
     * Never carries a `url`: a private disk has no public address, and
     * {@see \FoF\CloudAssets\Filesystem\PrivateDisk} refuses to invent one.
     *
     * @return array<string, mixed>|null
     */
    public function privateDisk(): ?array
    {
        if ($this->privateBucket === null) {
            return null;
        }

        return $this->disk(
            $this->privateBucket,
            $this->privateCredentials ?? $this->credentials
        ) + ['url' => null];
    }

    /**
     * The service's own public address for a bucket, where it has one that can
     * be worked out. Null means a `url` has to be given.
     */
    protected function defaultUrl(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function disk(string $bucket, Credentials $credentials): array
    {
        $config = $credentials->clientArguments() + [
            'driver' => 's3',
            'bucket' => $bucket,
            'region' => $this->region ?? 'auto',
            'options' => $this->writeOptions(),
            'cache_control' => $this->cacheControl,
        ];

        if (($endpoint = $this->endpoint($bucket)) !== null) {
            $config['endpoint'] = $endpoint;
        }

        if ($this->usesPathStyleEndpoint()) {
            $config['use_path_style_endpoint'] = true;
        }

        return $config;
    }

    /**
     * Arguments sent with every write, minus anything the service would
     * reject or quietly ignore.
     *
     * @return array<string, mixed>
     */
    private function writeOptions(): array
    {
        $options = $this->options;

        if (! $this->supportsObjectAcls()) {
            unset($options['ACL']);
        }

        return $options;
    }
}

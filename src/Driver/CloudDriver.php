<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Driver;

use Aws\S3\S3Client;
use Flarum\Filesystem\DriverInterface;
use Flarum\Foundation\Config as FlarumConfig;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\CloudAssets\Config\StorageConfig;
use FoF\CloudAssets\Filesystem\CacheControl;
use FoF\CloudAssets\Filesystem\CloudDisk;
use FoF\CloudAssets\Filesystem\ListingCache;
use FoF\CloudAssets\Filesystem\MimeTypes;
use FoF\CloudAssets\Filesystem\PrivateDisk;
use FoF\CloudAssets\Filesystem\WriteBatch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Illuminate\Support\Arr;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemAwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * Puts a Flarum disk on an S3-compatible bucket.
 *
 * Registered for every disk, so files written by one instance of a
 * multi-instance forum are readable by the rest. A disk's own declared
 * visibility is carried across: the public disks under `public/` become
 * publicly readable objects, and the ones under `storage/` — GDPR exports,
 * private uploads — stay private, which is the part a blanket relocation must
 * not get wrong.
 */
class CloudDriver implements DriverInterface
{
    /**
     * MIME types libmagic reports that say nothing useful, so the filename
     * extension decides instead.
     *
     * The first five are Flysystem's own list. The rest are what libmagic
     * actually returns for compiled assets: CSS comes back as `text/troff`
     * and JS as `text/x-c`, which the bucket then stores and serves verbatim
     * — and a browser sent `text/troff` for a stylesheet under
     * `X-Content-Type-Options: nosniff` refuses to apply it, so the forum
     * renders unstyled.
     *
     * @var list<string>
     */
    public const INCONCLUSIVE_MIME_TYPES = [
        'application/x-empty',
        'text/plain',
        'text/x-asm',
        'application/octet-stream',
        'inode/x-empty',
        'text/troff',
        'text/x-c',
        'text/x-c++',
    ];

    /**
     * @param list<string> $keepLocal
     */
    public function __construct(
        protected Paths $paths,
        protected StorageConfig $config,
        protected Container $container,
        protected array $keepLocal = []
    ) {
    }

    /**
     * A disk's root as a key prefix, with the separator Flysystem would add.
     */
    protected function keyPrefix(string $root): string
    {
        $root = trim($root, '/');

        return $root === '' ? '' : $root.'/';
    }

    public function build(
        string $diskName,
        SettingsRepositoryInterface $settings,
        FlarumConfig $config,
        array $localConfig
    ): Cloud {
        $root = $this->cloudPath(Arr::get($localConfig, 'root', ''));

        // Excluded by the site, or a root outside both public/ and storage/
        // that has no meaning as a key prefix. Either way the disk keeps
        // working locally rather than failing.
        if (in_array($diskName, $this->keepLocal, true) || $root === null) {
            return $this->localDisk($localConfig);
        }

        if ($this->isPrivate($root, $localConfig)) {
            $private = $this->config->privateDisk();

            // No private bucket named. The disk stays on the filesystem, which
            // is correct for a single-instance forum offloading its assets to
            // a CDN and nothing else — a common arrangement, and not a
            // degraded one. Putting these files in the public bucket instead
            // would publish them; there is no third option that is both shared
            // and private.
            //
            // On several instances this does mean private files are not shared
            // — an export written by one is not readable by another — which is
            // what naming a private bucket fixes.
            if ($private === null) {
                return $this->localDisk($localConfig);
            }

            return $this->adapter(array_merge($private, [
                'root' => $root,
                'visibility' => Arr::get($localConfig, 'visibility', Visibility::PRIVATE),
                'batch_existence' => false,
                'private' => true,
            ]));
        }

        return $this->adapter(array_merge(
            $this->config->publicDisk(),
            [
                'root' => $root,
                // The disk declares what it is for. Core marks `flarum-assets`
                // public and the private disks private; anything that says
                // nothing is treated as private, so a disk added later cannot
                // become world-readable by omission.
                'visibility' => Arr::get($localConfig, 'visibility', Visibility::PRIVATE),
                // The compiled assets are the only disk rebuilt file-by-file,
                // so only that one pays the existence checks worth batching.
                'batch_existence' => $diskName === 'flarum-assets',
            ]
        ));
    }

    /**
     * A disk addressing a whole bucket, rooted at its top rather than at one
     * disk's prefix.
     *
     * For {@see \FoF\CloudAssets\Console\SecurePrivateFilesCommand}, which has
     * to reach keys no disk points at any more: the private files that earlier
     * versions left in the public bucket are only findable by addressing the
     * bucket itself.
     *
     * @param array<string, mixed> $config
     */
    public function bucketDisk(array $config): AwsS3V3Adapter
    {
        return $this->adapter(array_merge($config, [
            'root' => '',
            'visibility' => Visibility::PRIVATE,
            'batch_existence' => false,
        ]));
    }

    /**
     * A disk rooted at the storage directory.
     *
     * The destination when private files have to come back to the filesystem
     * because no private bucket is configured. Rooted at `storage/` rather
     * than at one disk, so a key keeps the sub-path it had in the bucket and
     * lands where its own disk will look for it.
     */
    public function storageDisk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->localDisk(['root' => $this->paths->storage]);

        return $disk;
    }

    /**
     * A disk left on the filesystem.
     *
     * Built here rather than delegated to Laravel's FilesystemManager: this
     * driver is constructed while the container is resolving that manager, so
     * asking for it back would recurse until the stack ran out.
     *
     * @param array<string, mixed> $config
     */
    protected function localDisk(array $config): Cloud
    {
        $root = (string) Arr::get($config, 'root', '');

        // @phpstan-ignore-next-line — FilesystemAdapter implements every method
        // Flarum calls on a disk; only `url()` differs, and it is supplied by
        // the disk's own `url` config below.
        return new FilesystemAdapter(
            new Flysystem(new LocalFilesystemAdapter($root), $config),
            new LocalFilesystemAdapter($root),
            $config
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function adapter(array $config): AwsS3V3Adapter
    {
        $clientConfig = $this->clientConfig($config);
        $client = new S3Client($clientConfig);

        $detector = new FinfoMimeTypeDetector(inconclusiveMimetypes: static::INCONCLUSIVE_MIME_TYPES);

        $adapter = new FlysystemAwsS3V3Adapter(
            $client,
            $clientConfig['bucket'],
            (string) ($clientConfig['root'] ?? ''),
            // On a provider without object ACLs the converter has nothing to
            // act on — R2 accepts `x-amz-acl` and ignores it — so the value
            // here is not a guarantee of anything. Kept as declared so a
            // provider that does honour ACLs gets the disk's own intent.
            new PortableVisibilityConverter($config['visibility'] ?? Visibility::PRIVATE),
            $detector,
            $this->writeOptions($config),
        );

        // The compiled assets are the only disk rebuilt file-by-file, so only
        // that one pays the per-file existence checks worth batching.
        $class = match (true) {
            ($config['private'] ?? false) => PrivateDisk::class,
            ($config['batch_existence'] ?? false) => ListingCache::class,
            default => CloudDisk::class,
        };

        /** @var CloudDisk $disk */
        $disk = new $class(
            new Flysystem($adapter, Arr::only($config, [
                'directory_visibility',
                'disable_asserts',
                'retain_visibility',
                'temporary_url',
                'url',
                'visibility',
            ])),
            $adapter,
            $clientConfig,
            $client
        );

        // Per file rather than per disk: the constructor options apply to
        // every write, and one value cannot be right for both a
        // content-addressed bundle and a sitemap regenerated at a stable URL.
        $disk->setCacheControl(new CacheControl($config['cache_control'] ?? []));

        // Only the assets disk. It is the one a rebuild writes file by file,
        // and the only one whose writes are bracketed by a versioner batch —
        // an avatar or an attachment arrives on its own, with nothing to share
        // the trip with.
        if ($config['batch_existence'] ?? false) {
            $batch = new WriteBatch(
                $client,
                (string) $clientConfig['bucket'],
                $this->keyPrefix((string) ($clientConfig['root'] ?? ''))
            );

            $disk->setWriteBatch($batch, new MimeTypes($detector));

            $this->container->instance(WriteBatch::class, $batch);
        }

        return $disk;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    /**
     * Options sent with every write.
     *
     * `Cache-Control` and `Content-Type` behave the same everywhere, so they
     * pass through. An `ACL` is dropped on a provider that ignores it: sending
     * one there makes a visibility setting look effective when it is not, and
     * the honest signal is that the package sent nothing.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function writeOptions(array $config): array
    {
        // Already filtered by the config, which drops an ACL the service
        // would reject or quietly ignore.
        return $config['options'] ?? [];
    }

    /**
     * The arguments the S3 client is constructed with.
     *
     * Credentials arrive already shaped by {@see \FoF\CloudAssets\Config\Credentials}
     * — as a `credentials` array, as a `profile` name, or as nothing at all,
     * which is what leaves the SDK to resolve them from the environment or an
     * attached role. Nothing is assembled here.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function clientConfig(array $config): array
    {
        $config += ['version' => 'latest'];

        // This package's own keys, which the AWS client would reject as
        // unrecognised configuration.
        return Arr::except($config, ['provider', 'batch_existence', 'private', 'private_bucket', 'options', 'visibility']);
    }

    /**
     * The key prefix private disks are written under.
     */
    public const PRIVATE_PREFIX = 'storage/';

    /**
     * Whether a disk's files must not be reachable from the web.
     *
     * Chiefly decided by where the disk is rooted, because a *missing*
     * `visibility` says nothing: core's own `flarum-avatars` omits it, and so
     * does the example in {@see \Flarum\Extend\Filesystem::disk()} that
     * extension authors copied — so an absent value means "public" on one disk
     * and "private" on another (`gdpr-export`, which holds complete
     * personal-data exports, declares nothing at all).
     *
     * The root cannot be ambiguous in the same way. A disk under `storage/` is
     * outside the document root, so it was never web-servable even on a plain
     * filesystem install; a disk under `public/` exists precisely to be
     * served. That holds for every disk across the four installs this was
     * checked against, and it is a property of the layout rather than of what
     * an author remembered to write.
     *
     * A declaration that *is* present is a different matter, and is honoured
     * on top — see below.
     *
     * @param array<string, mixed> $localConfig
     */
    protected function isPrivate(string $cloudRoot, array $localConfig): bool
    {
        if (str_starts_with($cloudRoot, static::PRIVATE_PREFIX)) {
            return true;
        }

        // An explicit declaration is not ambiguous the way its absence is, so
        // it is honoured wherever the disk is rooted. A disk under `public/`
        // that says it is private has been put there for the web server's
        // convenience, and its author has still said the files are not for
        // everyone; publishing them to a CDN because of where they sit would
        // be overruling a decision that was actually made.
        //
        // The two signals only ever add: either one saying private is enough.
        // Nothing here can make a `storage/`-rooted disk public, because a
        // disk outside the document root was never servable to begin with.
        return Arr::get($localConfig, 'visibility') === Visibility::PRIVATE;
    }

    /**
     * Turn a disk's absolute local root into a key prefix in the bucket.
     *
     * Core declares every disk with a filesystem path — `flarum-assets` is
     * `<public>/assets` — which means nothing to a bucket, so it becomes the
     * path relative to the public directory (`assets`). A disk under storage
     * keeps a `storage/` prefix so the two trees cannot collide in one bucket.
     *
     * Anything outside both returns null rather than being silently written to
     * the bucket root.
     */
    public function cloudPath(string $path): ?string
    {
        $public = rtrim($this->paths->public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $public)) {
            return substr($path, strlen($public));
        }

        $storage = rtrim($this->paths->storage, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $storage)) {
            return static::PRIVATE_PREFIX.substr($path, strlen($storage));
        }

        return null;
    }
}

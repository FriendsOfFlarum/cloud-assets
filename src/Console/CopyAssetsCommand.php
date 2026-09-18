<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Console;

use Flarum\Foundation\Paths;
use Flarum\Frontend\Compiler\VersionerInterface;
use Flarum\Http\UrlGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Uploads what is already on the filesystem into the bucket.
 *
 * Run once, when a forum that has been serving files locally moves to cloud
 * storage. Without it the bucket starts empty, and everything the forum has
 * accumulated — avatars, uploads, generated images, the logo — is simply gone
 * from the site, because the disks now read from a bucket that has never seen
 * them.
 *
 * What it deliberately does NOT copy is anything a rebuild will write anyway:
 * the compiled bundles and their sourcemaps, which `cache:clear` regenerates,
 * and the published fonts and extension assets, which `assets:publish` writes
 * straight to whichever disk is configured. Uploading those would be a
 * request each for a file about to be overwritten.
 *
 * Everything else is copied without trying to classify it. A file this command
 * cannot account for might be an avatar, an attachment, a frontend belonging
 * to an extension that is currently disabled, or a leftover from an older
 * Flarum — and the cost of guessing wrong in each direction is not
 * symmetrical: copying something inert wastes one request, while skipping a
 * user's avatar loses it. Removing what is genuinely stale is a separate job,
 * for a command that can be run deliberately and after review.
 */
class CopyAssetsCommand extends Command
{
    protected $signature = 'cloud-assets:copy
        {--disk=* : Disks to copy; defaults to every registered disk}
        {--force : Actually upload — without this, only reports what would be copied}';

    protected $description = 'Upload existing local files into the configured bucket';

    public function __construct(
        protected Container $container,
        protected Paths $paths,
        protected Factory $filesystem,
        protected VersionerInterface $versioner
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Every registered disk's root, resolved once. Which disk owns a file
        // has to be answered against all of them, not just the ones being
        // copied: `--disk=flarum-assets` alone must still leave the avatars
        // under it to `flarum-avatars`, or they are uploaded a second time
        // under a key that disk will never read back.
        $roots = $this->roots();
        $disks = $this->option('disk') ?: array_keys($roots);
        $dryRun = ! $this->option('force');

        if ($dryRun) {
            $this->info('Dry run — pass --force to upload.');
            $this->line('');
        }

        $regenerable = $this->regenerable();
        $totalCopied = 0;
        $totalSkipped = 0;
        $failed = 0;

        foreach ($disks as $name) {
            $root = $roots[$name] ?? null;

            if ($root === null) {
                $this->error("Disk [$name] is not registered.");
                $failed++;

                continue;
            }

            if (! is_dir($root)) {
                continue;
            }

            $target = $this->filesystem->disk($name);
            $copied = 0;
            $skipped = 0;

            foreach ($this->filesIn($root) as $relative) {
                // Nested disks: `flarum-avatars` is rooted inside
                // `flarum-assets`, so a file belonging to a more specific disk
                // is left for that disk's own pass rather than uploaded twice
                // under two different keys.
                if ($this->belongsToAnotherDisk($name, $root, $relative, $roots)) {
                    continue;
                }

                if (isset($regenerable[$relative]) || $this->isPublished($name, $relative)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $copied++;

                    continue;
                }

                try {
                    $stream = fopen($root.'/'.$relative, 'rb');

                    if ($stream === false) {
                        throw new \RuntimeException('could not open for reading');
                    }

                    try {
                        $target->writeStream($relative, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    $copied++;
                } catch (\Throwable $e) {
                    $this->error("  $name: $relative — {$e->getMessage()}");
                    $failed++;
                }
            }

            if ($copied > 0 || $skipped > 0) {
                $this->info(sprintf(
                    '%-18s %s%d file(s)%s',
                    $name,
                    $dryRun ? 'would copy ' : 'copied ',
                    $copied,
                    $skipped > 0 ? ", skipped $skipped written by cache:clear or assets:publish" : ''
                ));
            }

            $totalCopied += $copied;
            $totalSkipped += $skipped;
        }

        $this->line('');
        $this->info(sprintf(
            '%s %d file(s); skipped %d.',
            $dryRun ? 'Would copy' : 'Copied',
            $totalCopied,
            $totalSkipped
        ));

        if (! $dryRun && $totalCopied > 0) {
            $this->line('');
            $this->info('Now run `php flarum cache:clear` and `php flarum assets:publish` to write the');
            $this->info('compiled bundles, fonts and extension assets into the bucket.');
        }

        if ($failed > 0) {
            $this->error("$failed file(s) could not be copied.");

            return 1;
        }

        return 0;
    }

    /**
     * Prefixes on the assets disk that `assets:publish` writes: the
     * FontAwesome webfonts, the XSLT polyfill, and each enabled extension's
     * own assets. It writes them to whichever disk is configured, so on a
     * cloud disk they land in the bucket without this command's help.
     *
     * @var list<string>
     */
    private const PUBLISHED_PREFIXES = ['fonts/', 'extensions/', 'xslt-polyfill/'];

    /**
     * Files something else writes anyway, keyed by path relative to the disk.
     *
     * The compiled bundles come from the versioner rather than being inferred
     * from filenames — it is the only thing that actually knows which files a
     * rebuild owns, and a guess either wastes requests or loses a file.
     *
     * @return array<string, true>
     */
    private function regenerable(): array
    {
        $regenerable = [];

        foreach (array_keys($this->versioner->allRevisions()) as $file) {
            $regenerable[$file] = true;
            $regenerable[$file.'.map'] = true;
        }

        return $regenerable;
    }

    /**
     * Whether `assets:publish` owns this path.
     *
     * Only meaningful on the assets disk; the published prefixes have no
     * counterpart on the others.
     */
    private function isPublished(string $disk, string $relative): bool
    {
        if ($disk !== 'flarum-assets') {
            return false;
        }

        foreach (self::PUBLISHED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every registered disk's local root, keyed by disk name.
     *
     * Resolved in one pass: a disk is declared as a callback returning its
     * config, and working out which disk owns a file means comparing roots for
     * every file on every disk. Resolving them per question would be that many
     * container lookups for an answer that cannot change while the command
     * runs.
     *
     * @return array<string, string>
     */
    private function roots(): array
    {
        $paths = $this->container->make(Paths::class);
        $url = $this->container->make(UrlGenerator::class);
        $roots = [];

        foreach ($this->container->make('flarum.filesystem.disks') as $name => $declaration) {
            $callback = is_string($declaration)
                ? [$this->container->make($declaration), '__invoke']
                : $declaration;

            $root = $callback($paths, $url)['root'] ?? null;

            if ($root !== null) {
                $roots[$name] = $root;
            }
        }

        return $roots;
    }

    /**
     * Whether a file under this disk's root actually belongs to a more deeply
     * rooted disk.
     *
     * Asked against every registered disk rather than the ones being copied.
     * `flarum-avatars` is rooted inside `flarum-assets`, so copying only
     * `flarum-assets` must still leave the avatars alone: uploading them here
     * would write each one a second time under a key `flarum-avatars` never
     * reads, leaving the bucket holding two copies of every avatar and the
     * selection of disks silently deciding which paths a migration produces.
     *
     * @param array<string, string> $roots
     */
    private function belongsToAnotherDisk(string $disk, string $root, string $relative, array $roots): bool
    {
        $path = $root.'/'.$relative;

        foreach ($roots as $other => $otherRoot) {
            if ($other === $disk) {
                continue;
            }

            if (strlen($otherRoot) <= strlen($root)) {
                continue;
            }

            if (str_starts_with($path, rtrim($otherRoot, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<string>
     */
    private function filesIn(string $root): iterable
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                yield ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);
            }
        }
    }
}

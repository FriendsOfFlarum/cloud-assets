<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Filesystem;


/**
 * Answers `exists()` from a listing instead of one request per file.
 *
 * Rebuilding the assets asks whether each compiled file is still in the bucket
 * — a repair path for a file deleted while its revision stayed recorded. On a
 * local disk that is a stat; against a bucket it is a request each, and there
 * is one per compiled file: on a forum with code splitting that is around a
 * hundred, and it grows with every extension that adds a lazy chunk.
 *
 * Measured against Cloudflare R2 with 97 compiled files: 97 HeadObject calls
 * cost ~12.4s of a 13.2s `cache:clear` that wrote nothing, while listing the
 * two prefixes those files live under costs ~420ms and answers all of them.
 *
 * Extends the S3 adapter rather than wrapping it, so every method Laravel
 * adds keeps working — only `exists()` and the writes that would invalidate
 * the listing are touched.
 */
class ListingCache extends CloudDisk
{
    /**
     * Prefixes listed on first use, and whether to recurse.
     *
     * The root is listed shallowly: compiled bundles sit directly in it, and
     * recursing would walk every avatar and upload in the bucket — 21,942
     * keys on the forum this was measured against, about ten seconds. `js/`
     * is listed deeply because split chunks nest arbitrarily
     * (`js/<ext>/<frontend>/<dir>/<file>.js`, six levels on some extensions).
     *
     * @var array<string, bool>
     */
    public const PREFIXES = ['' => false, 'js' => true];

    /**
     * Keys known to exist, or null until the listing is taken.
     *
     * Per instance and never revalidated, which is the right lifetime: it
     * serves one rebuild, and the rebuild is what changes the bucket.
     *
     * @var array<string, true>|null
     */
    private ?array $known = null;

    public function exists($path): bool
    {
        $path = ltrim((string) $path, '/');

        // A path outside the listed prefixes is asked about directly, so an
        // extension publishing somewhere unexpected is answered correctly
        // rather than assumed missing.
        if (! $this->isListed($path)) {
            return parent::exists($path);
        }

        return isset($this->listing()[$path]);
    }

    public function put($path, $contents, $options = []): bool
    {
        $result = parent::put($path, $contents, $options);

        if ($result) {
            $this->remember($path);
        }

        return $result;
    }

    public function writeStream($path, $resource, array $options = []): bool
    {
        $result = parent::writeStream($path, $resource, $options);

        if ($result) {
            $this->remember($path);
        }

        return $result;
    }

    public function delete($paths): bool
    {
        $result = parent::delete($paths);

        if ($this->known !== null) {
            foreach ((array) $paths as $path) {
                unset($this->known[ltrim((string) $path, '/')]);
            }
        }

        return $result;
    }

    private function remember(string $path): void
    {
        if ($this->known !== null) {
            $this->known[ltrim($path, '/')] = true;
        }
    }

    /**
     * Whether a path lives under a prefix the listing covers.
     *
     * A path below a shallowly-listed prefix is not covered by it:
     * `avatars/x.png` sits under the root but was never listed.
     */
    private function isListed(string $path): bool
    {
        foreach (static::PREFIXES as $prefix => $recursive) {
            if ($prefix === '') {
                if (! str_contains($path, '/')) {
                    return true;
                }

                continue;
            }

            if (str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function listing(): array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        $known = [];

        foreach (static::PREFIXES as $prefix => $recursive) {
            $files = $recursive ? parent::allFiles($prefix) : parent::files($prefix);

            foreach ($files as $file) {
                $known[ltrim($file, '/')] = true;
            }
        }

        return $this->known = $known;
    }
}

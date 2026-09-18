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

use Aws\S3\S3Client;
use GuzzleHttp\Promise\Utils;
use RuntimeException;

/**
 * Uploads a rebuild's files at the same time rather than one after another.
 *
 * A rebuild writes each compiled file as it finishes it, and against a bucket
 * every one of those is a round trip that the next write waits for. Measured
 * on an admin rebuild — five bundles and four sourcemaps, about 12 MB — the
 * uploads took 2.7s of a 4.1s rebuild, and none of it was bandwidth: a 2 MB
 * object uploads in 164ms, while `admin-en.js.map` alone took 938ms. The cost
 * is latency per request, repeated.
 *
 * Sending them together collapses that: the same nine files took 747ms
 * concurrently, and six 900 KB objects went from 3086ms to 547ms. What is
 * saved is the waiting, so the gain grows with the number of files and does
 * not depend on their size.
 *
 * Writes are only held while a caller has opened a batch — see
 * {@see BatchedVersioner}, which opens one for exactly as long as a rebuild
 * runs. Outside that window a write goes straight out, because nothing is
 * coming after it to share the trip with.
 */
class WriteBatch
{
    /**
     * How many uploads are in flight at once.
     *
     * Enough to cover a whole frontend's rebuild — nine files on the admin
     * set — without opening a connection per file on a provider or container
     * that would rather not. R2 was happy with more; this is chosen for the
     * smaller end of what this package runs against.
     */
    public const CONCURRENCY = 6;

    /**
     * Queued writes, keyed by path so that writing the same file twice in one
     * batch sends only what was written last.
     *
     * @var array<string, array{body: string|resource, options: array<string, mixed>}>
     */
    private array $queued = [];

    private int $depth = 0;

    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $prefix
    ) {
    }

    /**
     * Hold writes until the matching {@see close()}.
     *
     * Nestable, and only the outermost close sends: a caller that opens a
     * batch around a rebuild does not have to know whether something inside it
     * opened one too.
     */
    public function open(): void
    {
        $this->depth++;
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @param string|resource $body
     * @param array<string, mixed> $options
     */
    public function add(string $path, $body, array $options = []): void
    {
        $this->queued[$path] = ['body' => $body, 'options' => $options];
    }

    /**
     * Send everything queued, and stop holding writes.
     *
     * Safe to call when nothing was queued, and when no batch was open — a
     * caller unwinding from an error should not have to check.
     */
    public function close(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }

        if ($this->depth > 0) {
            return;
        }

        $this->flush();
    }

    /**
     * @throws RuntimeException if any upload fails
     */
    public function flush(): void
    {
        $queued = $this->queued;
        $this->queued = [];

        if ($queued === []) {
            return;
        }

        // One file is the common case once a rebuild has settled — a CSS-only
        // change, or a forum whose sourcemaps are turned off. Sending it
        // directly keeps that path free of the promise machinery, which would
        // otherwise cost more than it saves.
        if (count($queued) === 1) {
            $path = array_key_first($queued);

            $this->client->putObject($this->request($path, $queued[$path]));

            return;
        }

        $failures = [];

        foreach (array_chunk($queued, static::CONCURRENCY, true) as $chunk) {
            $promises = [];

            foreach ($chunk as $path => $write) {
                $promises[$path] = $this->client->putObjectAsync($this->request($path, $write));
            }

            foreach (Utils::settle($promises)->wait() as $path => $result) {
                if ($result['state'] !== 'fulfilled') {
                    $failures[$path] = $result['reason'] instanceof \Throwable
                        ? $result['reason']->getMessage()
                        : 'unknown error';
                }
            }
        }

        if ($failures !== []) {
            // Every upload is attempted before this is raised, rather than
            // abandoning the batch at the first failure: the caller is part
            // way through a rebuild either way, and a set of files where all
            // but one arrived is easier to repair than one that stopped at an
            // arbitrary point.
            throw new RuntimeException(sprintf(
                'Could not upload %d of %d file(s): %s',
                count($failures),
                count($queued),
                implode('; ', array_map(
                    fn (string $path, string $error) => "$path — $error",
                    array_keys($failures),
                    $failures
                ))
            ));
        }
    }

    /**
     * @param array{body: string|resource, options: array<string, mixed>} $write
     * @return array<string, mixed>
     */
    private function request(string $path, array $write): array
    {
        return $write['options'] + [
            'Bucket' => $this->bucket,
            'Key' => $this->prefix.$path,
            'Body' => $write['body'],
        ];
    }
}

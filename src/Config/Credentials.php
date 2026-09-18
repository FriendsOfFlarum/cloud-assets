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
 * How the SDK is told who is asking.
 *
 * S3-compatible services outside AWS authenticate one way — an access key and
 * a secret, issued by the provider — so for those there is nothing to choose.
 * AWS has several, and which one a deployment uses is a real decision: an
 * access key pair, temporary credentials from STS, a named profile, or no
 * credentials at all because the environment already provides them (an ECS
 * task role, an EC2 instance profile, IRSA on EKS).
 *
 * Each is a named constructor rather than a set of optional arguments, so the
 * choice is legible where it is made:
 *
 *     credentials: Credentials::keyPair($key, $secret),
 *     credentials: Credentials::profile('forum-assets'),
 *     credentials: Credentials::fromEnvironment(),
 *
 * The names follow {@see \Aws\Credentials\CredentialProvider}, and each maps
 * onto the client arguments the SDK already understands — nothing here
 * resolves a credential itself. What it adds is that a site reading an
 * `extend.php` can see which mechanism is in use without knowing the SDK's
 * resolution order.
 */
final class Credentials
{
    public const KEY_PAIR = 'key_pair';
    public const PROFILE = 'profile';
    public const ENVIRONMENT = 'environment';
    public const DEFAULT_CHAIN = 'default_chain';

    /**
     * @param array<string, mixed> $client arguments merged into the S3 client
     */
    private function __construct(
        public readonly string $source,
        private readonly array $client,
    ) {
    }

    /**
     * An access key and secret, as issued by the provider.
     *
     * The only option outside AWS, and still the common one on it.
     *
     * @param string|null $token a session token, for temporary credentials
     *                           from STS. They expire, so a deployment using
     *                           them needs something to hand fresh ones to the
     *                           process — on AWS that is usually a role, which
     *                           {@see fromEnvironment()} picks up without any
     *                           of this.
     */
    public static function keyPair(string $key, string $secret, ?string $token = null): self
    {
        if ($key === '' || $secret === '') {
            throw new InvalidArgumentException(
                'Both a key and a secret are required. To let the AWS SDK find credentials itself — from a task role, an instance profile, or the environment — use Credentials::fromEnvironment() instead.'
            );
        }

        $credentials = ['key' => $key, 'secret' => $secret];

        if ($token !== null && $token !== '') {
            $credentials['token'] = $token;
        }

        return new self(self::KEY_PAIR, ['credentials' => $credentials]);
    }

    /**
     * A named profile from the shared AWS config and credentials files.
     *
     * For a deployment that already keeps its credentials in `~/.aws`, where
     * the profile may itself assume a role or resolve through SSO. AWS only —
     * no other provider reads those files.
     */
    public static function profile(string $name): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('A profile name is required.');
        }

        return new self(self::PROFILE, ['profile' => $name]);
    }

    /**
     * Whatever the environment already provides.
     *
     * The SDK's own default chain: `AWS_ACCESS_KEY_ID` and friends, then the
     * shared config files, then an ECS task role, an EC2 instance profile, or
     * a web identity token on EKS. AWS only, and the right answer wherever the
     * platform hands the container an identity — there is then no key to
     * place, rotate, or leak.
     *
     * Nothing is passed to the client at all: an S3Client constructed without
     * `credentials` resolves them this way by itself.
     */
    public static function fromEnvironment(): self
    {
        return new self(self::DEFAULT_CHAIN, []);
    }

    /**
     * Whether a key and secret were given.
     *
     * Providers other than AWS have no other option, and say so.
     */
    public function areExplicit(): bool
    {
        return $this->source === self::KEY_PAIR;
    }

    /**
     * How this reads in the admin panel, where an operator is checking which
     * mechanism a running forum actually used.
     */
    public function label(): string
    {
        return match ($this->source) {
            self::KEY_PAIR => 'access key',
            self::PROFILE => 'shared profile',
            default => 'environment',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function clientArguments(): array
    {
        return $this->client;
    }
}

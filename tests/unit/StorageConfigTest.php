<?php

/*
 * This file is part of fof/cloud-assets.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\CloudAssets\Tests\unit;

use FoF\CloudAssets\Config\AwsConfig;
use FoF\CloudAssets\Config\Credentials;
use FoF\CloudAssets\Config\GenericConfig;
use FoF\CloudAssets\Config\MinioConfig;
use FoF\CloudAssets\Config\R2Config;
use FoF\CloudAssets\Config\SpacesConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StorageConfigTest extends TestCase
{
    private function r2(array $overrides = []): R2Config
    {
        return new R2Config(...array_merge([
            'accountId' => 'acct',
            'bucket' => 'public-bucket',
            'credentials' => Credentials::keyPair('PUBKEY', 'PUBSECRET'),
            'url' => 'https://cdn.example.com',
        ], $overrides));
    }

    #[Test]
    public function private_disk_never_carries_a_url(): void
    {
        $config = $this->r2(['privateBucket' => 'private-bucket']);

        // A private disk with a public address is the bug this package exists
        // to fix: PrivateDisk refuses to invent one, and nothing may supply it.
        $this->assertNull($config->privateDisk()['url']);
        $this->assertSame('https://cdn.example.com', $config->publicDisk()['url']);
    }

    #[Test]
    public function there_is_no_private_disk_without_a_private_bucket(): void
    {
        $this->assertNull($this->r2()->privateDisk());
        $this->assertFalse($this->r2()->hasPrivateStorage());
    }

    #[Test]
    public function r2_refuses_to_hold_both_trees_in_one_bucket(): void
    {
        // R2 exposes a whole bucket when a domain is bound to it, so there is
        // no arrangement in which one bucket is safe for both.
        $this->expectException(InvalidArgumentException::class);

        $this->r2(['privateBucket' => 'public-bucket']);
    }

    #[Test]
    public function aws_allows_one_bucket_because_a_policy_can_name_a_prefix(): void
    {
        $config = new AwsConfig(
            bucket: 'forum',
            region: 'eu-west-1',
            credentials: Credentials::keyPair('k', 's'),
            privateBucket: 'forum',
        );

        $this->assertTrue($config->usesOneBucket());
        $this->assertSame('forum', $config->privateDisk()['bucket']);
    }

    #[Test]
    public function a_bucket_is_reached_at_its_own_jurisdictions_endpoint(): void
    {
        // Verified against real buckets: each answers only on its own
        // endpoint, and returns AccessDenied on the other.
        $config = $this->r2([
            'privateBucket' => 'private-bucket',
            'privateJurisdiction' => 'eu',
        ]);

        $this->assertSame(
            'https://acct.r2.cloudflarestorage.com',
            $config->publicDisk()['endpoint']
        );
        $this->assertSame(
            'https://acct.eu.r2.cloudflarestorage.com',
            $config->privateDisk()['endpoint']
        );
    }

    #[Test]
    public function the_private_bucket_may_have_its_own_credentials(): void
    {
        $config = $this->r2([
            'privateBucket' => 'private-bucket',
            'privateCredentials' => Credentials::keyPair('PRIVKEY', 'PRIVSECRET'),
        ]);

        $this->assertSame('PUBKEY', $config->publicDisk()['credentials']['key']);
        $this->assertSame('PRIVKEY', $config->privateDisk()['credentials']['key']);
        $this->assertSame('PRIVSECRET', $config->privateDisk()['credentials']['secret']);
    }

    #[Test]
    public function the_public_credentials_serve_both_when_no_others_are_given(): void
    {
        $config = $this->r2(['privateBucket' => 'private-bucket']);

        $this->assertSame('PUBKEY', $config->privateDisk()['credentials']['key']);
        $this->assertSame('PUBSECRET', $config->privateDisk()['credentials']['secret']);
    }

    #[Test]
    public function aws_may_leave_credentials_to_the_environment(): void
    {
        // An ECS task role, an EC2 instance profile, IRSA: the platform hands
        // the container an identity and the SDK finds it, so there is no key
        // to place or rotate.
        $config = new AwsConfig(
            bucket: 'b',
            region: 'eu-west-1',
            credentials: Credentials::fromEnvironment(),
        );

        $this->assertArrayNotHasKey('credentials', $config->publicDisk());
        $this->assertArrayNotHasKey('profile', $config->publicDisk());
    }

    #[Test]
    public function aws_may_name_a_shared_profile(): void
    {
        $config = new AwsConfig(
            bucket: 'b',
            region: 'eu-west-1',
            credentials: Credentials::profile('forum-assets'),
        );

        $this->assertSame('forum-assets', $config->publicDisk()['profile']);
    }

    #[Test]
    public function temporary_credentials_carry_their_session_token(): void
    {
        $config = new AwsConfig(
            bucket: 'b',
            region: 'eu-west-1',
            credentials: Credentials::keyPair('ASIA1', 's', 'SESSION-TOKEN'),
        );

        $this->assertSame('SESSION-TOKEN', $config->publicDisk()['credentials']['token']);
    }

    #[Test]
    public function a_service_that_cannot_discover_credentials_refuses_to_go_without(): void
    {
        // Only AWS falls back to an attached identity. R2, MinIO and Spaces
        // issue a key and a secret and have nowhere else to look, so omitting
        // them there is a configuration error rather than a choice.
        $this->expectException(InvalidArgumentException::class);

        $this->r2(['credentials' => Credentials::fromEnvironment()]);
    }

    #[Test]
    public function an_acl_is_dropped_where_it_would_be_rejected_or_ignored(): void
    {
        // AWS rejects one outright on any bucket made since 2023, and R2
        // accepts it and does nothing — the worse of the two, since the file
        // looks protected. Only Spaces treats it as the real mechanism.
        $r2 = $this->r2(['options' => ['ACL' => 'public-read']]);
        $this->assertArrayNotHasKey('ACL', $r2->publicDisk()['options']);

        $aws = new AwsConfig(bucket: 'b', region: 'eu-west-1', credentials: Credentials::keyPair('k', 's'), options: ['ACL' => 'public-read']);
        $this->assertArrayNotHasKey('ACL', $aws->publicDisk()['options']);

        $spaces = new SpacesConfig(region: 'ams3', bucket: 'b', credentials: Credentials::keyPair('k', 's'), options: ['ACL' => 'public-read']);
        $this->assertSame('public-read', $spaces->publicDisk()['options']['ACL']);
    }

    #[Test]
    public function a_legacy_aws_bucket_can_opt_back_into_acls(): void
    {
        $config = new AwsConfig(
            bucket: 'b',
            region: 'eu-west-1',
            credentials: Credentials::keyPair('k', 's'),
            legacyAcls: true,
            options: ['ACL' => 'public-read'],
        );

        $this->assertSame('public-read', $config->publicDisk()['options']['ACL']);
    }

    #[Test]
    public function a_public_url_is_derived_where_the_service_has_a_predictable_one(): void
    {
        $this->assertSame(
            'https://b.s3.dualstack.eu-west-1.amazonaws.com',
            (new AwsConfig(bucket: 'b', region: 'eu-west-1', credentials: Credentials::keyPair('k', 's')))->publicDisk()['url']
        );

        $this->assertSame(
            'https://b.ams3.digitaloceanspaces.com',
            (new SpacesConfig(region: 'ams3', bucket: 'b', credentials: Credentials::keyPair('k', 's')))->publicDisk()['url']
        );
    }

    #[Test]
    public function minio_addresses_buckets_by_path_unless_told_otherwise(): void
    {
        $default = new MinioConfig(endpoint: 'https://minio:9000', bucket: 'b', credentials: Credentials::keyPair('k', 's'));
        $this->assertTrue($default->publicDisk()['use_path_style_endpoint']);
        // No derivable public address under path-style addressing.
        $this->assertNull($default->publicDisk()['url']);

        $virtualHost = new MinioConfig(endpoint: 'https://minio:9000', bucket: 'b', credentials: Credentials::keyPair('k', 's'), pathStyle: false);
        $this->assertArrayNotHasKey('use_path_style_endpoint', $virtualHost->publicDisk());
    }

    #[Test]
    public function an_unknown_service_is_assumed_to_do_the_least(): void
    {
        $config = new GenericConfig(endpoint: 'https://s3.example.net', bucket: 'b', credentials: Credentials::keyPair('k', 's'));

        $this->assertFalse($config->supportsObjectAcls());
        $this->assertFalse($config->supportsVisibilityChecks());
        $this->assertFalse($config->supportsPrefixScopedAccess());
    }

    #[Test]
    public function incomplete_configuration_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Credentials::keyPair('', '');
    }
}

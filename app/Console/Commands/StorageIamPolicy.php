<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * TEN-12: prints the IAM documents that make storage isolation hold at the storage layer.
 *
 *  - tenant-role: the permissions of TENANCY_STORAGE_ROLE_ARN. Only objects under tenants/<the session's tenant tag>/,
 *    and listing only that prefix. Nothing else is allowed, so a firm's credentials cannot touch another firm's keys.
 *  - trust: who may assume that role (the platform principal) and only with exactly one `tenant` session tag.
 *  - platform: the platform principal's own rights: assume/tag the role, plus the bucket-wide access it needs for
 *    backups, purge and health probes. Application servers serving firms never use it for firm files.
 */
class StorageIamPolicy extends Command
{
    protected $signature = 'storage:iam-policy
        {--bucket= : Bucket name (default: the s3 disk bucket)}
        {--platform-principal= : ARN of the role/user the app runs as (default: a placeholder)}
        {--role-arn= : The tenant storage role (default: TENANCY_STORAGE_ROLE_ARN)}
        {--part=all : tenant-role | trust | platform | all}';

    protected $description = 'Print the IAM policies that confine each firm to its own storage prefix (TEN-12)';

    public function handle(): int
    {
        $bucket = (string) ($this->option('bucket') ?: config('filesystems.disks.s3.bucket'));
        if ($bucket === '') {
            $this->error('No bucket: pass --bucket= or set AWS_BUCKET.');

            return self::FAILURE;
        }
        $principal = (string) ($this->option('platform-principal') ?: 'arn:aws:iam::ACCOUNT_ID:role/APP_ROLE');
        $role = (string) ($this->option('role-arn') ?: config('tenancy.storage_role_arn') ?: 'arn:aws:iam::ACCOUNT_ID:role/TENANT_STORAGE_ROLE');

        $parts = self::documents($bucket, $principal, $role);
        $part = (string) $this->option('part');
        if ($part !== 'all' && ! isset($parts[$part])) {
            $this->error('--part must be one of: tenant-role, trust, platform, all.');

            return self::FAILURE;
        }

        $out = $part === 'all' ? $parts : $parts[$part];
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array{tenant-role: array<string, mixed>, trust: array<string, mixed>, platform: array<string, mixed>} */
    public static function documents(string $bucket, string $principal, string $role): array
    {
        $own = 'tenants/${aws:PrincipalTag/tenant}';

        return [
            'tenant-role' => ['Version' => '2012-10-17', 'Statement' => [
                [
                    'Sid' => 'OwnFirmObjectsOnly',
                    'Effect' => 'Allow',
                    'Action' => ['s3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:GetObjectAttributes', 's3:AbortMultipartUpload', 's3:ListMultipartUploadParts'],
                    'Resource' => "arn:aws:s3:::{$bucket}/{$own}/*",
                ],
                [
                    'Sid' => 'ListOwnFirmPrefixOnly',
                    'Effect' => 'Allow',
                    'Action' => 's3:ListBucket',
                    'Resource' => "arn:aws:s3:::{$bucket}",
                    'Condition' => ['StringLike' => ['s3:prefix' => ["{$own}/*", $own]]],
                ],
                [
                    // defence in depth: a session without a tenant tag gets nothing at all
                    'Sid' => 'NoTagNoAccess',
                    'Effect' => 'Deny',
                    'Action' => 's3:*',
                    'Resource' => ["arn:aws:s3:::{$bucket}", "arn:aws:s3:::{$bucket}/*"],
                    'Condition' => ['Null' => ['aws:PrincipalTag/tenant' => 'true']],
                ],
            ]],
            'trust' => ['Version' => '2012-10-17', 'Statement' => [[
                'Sid' => 'PlatformAssumesWithOneTenantTag',
                'Effect' => 'Allow',
                'Principal' => ['AWS' => $principal],
                'Action' => ['sts:AssumeRole', 'sts:TagSession'],
                'Condition' => [
                    'StringLike' => ['aws:RequestTag/tenant' => '?*'],
                    'ForAllValues:StringEquals' => ['aws:TagKeys' => ['tenant']],
                ],
            ]]],
            'platform' => ['Version' => '2012-10-17', 'Statement' => [
                ['Sid' => 'AssumeTenantStorageRole', 'Effect' => 'Allow', 'Action' => ['sts:AssumeRole', 'sts:TagSession'], 'Resource' => $role],
                [
                    'Sid' => 'FleetOperations',   // backups, purge of an offboarded firm, health probes
                    'Effect' => 'Allow',
                    'Action' => ['s3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:ListBucket'],
                    'Resource' => ["arn:aws:s3:::{$bucket}", "arn:aws:s3:::{$bucket}/*"],
                ],
            ]],
        ];
    }
}

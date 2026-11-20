<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use Aws\S3\S3Client;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ListBucketFiles
{
    /**
     * List uploaded files under the requested license's bucket prefix.
     *
     * @param  mixed  $root
     * @param  array{folder?: string|null, maxKeys?: int|null, licenseId?: string|null}  $args
     *
     * @throws GraphQLException
     *
     * @return array<int, array{key: string, url: string, size: int|null, lastModified: string|null}>
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $licenses = $context->user()->licenses();
        $requestedLicenseId = $args['licenseId'] ?? null;
        $license = $requestedLicenseId === null ? $licenses->first() : $licenses->find($requestedLicenseId);

        if (! $license) {
            throw new GraphQLException('No license found for the authenticated user.', 'VALIDATION');
        }

        $licenseId = $license->id;
        $hashedLicenseId = md5($licenseId);
        $folder    = isset($args['folder']) ? trim((string) $args['folder'], '/') : 'bg';
        $maxKeys   = min((int) ($args['maxKeys'] ?? 100), 200);

        $prefix = "{$folder}/{$hashedLicenseId}/";

        $s3Config = config('filesystems.disks.yandex');


        try {
            $client = new S3Client([
                'version'                  => 'latest',
                'region'                   => $s3Config['region'],
                'endpoint'                 => $s3Config['endpoint'],
                'use_path_style_endpoint'  => (bool) $s3Config['use_path_style_endpoint'],
                'credentials'              => [
                    'key'    => $s3Config['key'],
                    'secret' => $s3Config['secret'],
                ],
            ]);

            $result = $client->listObjectsV2([
                'Bucket'  => $s3Config['bucket'],
                'Prefix'  => $prefix,
                'MaxKeys' => $maxKeys,
            ]);

            $endpoint = rtrim($s3Config['endpoint'], '/');
            $bucket   = $s3Config['bucket'];

            $files = [];
            foreach ($result->get('Contents') ?? [] as $object) {
                $key = (string) $object['Key'];

                // Skip directory-like entries (keys ending in '/')
                if (str_ends_with($key, '/')) {
                    continue;
                }

                $files[] = [
                    'key'          => $key,
                    'url'          => "{$endpoint}/{$bucket}/{$key}",
                    'size'         => isset($object['Size']) ? (int) $object['Size'] : null,
                    'lastModified' => isset($object['LastModified'])
                        ? $object['LastModified']->format(\DateTimeInterface::ATOM)
                        : null,
                ];
            }

            // Sort newest first
            usort($files, static fn ($a, $b) => strcmp(
                (string) ($b['lastModified'] ?? ''),
                (string) ($a['lastModified'] ?? ''),
            ));

            return $files;
        } catch (\Throwable $e) {
            throw new GraphQLException('Failed to list bucket files.', 'INTERNAL');
        }
    }
}

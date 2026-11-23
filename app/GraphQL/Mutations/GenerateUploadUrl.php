<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use Aws\S3\S3Client;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class GenerateUploadUrl
{
    /**
     * Generate a pre-signed S3 upload URL for the authenticated user.
     *
     * @param  mixed  $root
     * @param  array{filename: string, mimeType: string}  $args
     * @return array{uploadUrl: string, objectUrl: string, expiresIn: int}
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $originalFilename = $args['filename'];
        $mimeType = $args['mimeType'];

        // Sanitize filename: convert to slug while preserving extension
        $pathInfo = pathinfo($originalFilename);
        $extension = $pathInfo['extension'] ?? 'jpg';
        $safeName = Str::slug($pathInfo['filename']);
        $filename = ! empty($safeName) ? "{$safeName}.{$extension}" : 'file-'.Str::random(8).".{$extension}";

        // Validate mimeType matches valid MIME type pattern (type/subtype)
        if (! preg_match('/^[\w\-]+\/[\w\-\.\+]+$/', $mimeType)) {
            throw new GraphQLException('Выберите файл поддерживаемого формата.', 'UPLOAD_FORMAT');
        }

        // Get the license_id from the authenticated user
        $user = $context->user();
        $licenseIdArg = $args['licenseId'] ?? null;

        if ($licenseIdArg) {
            $license = $user->licenses()->find($licenseIdArg);
            if (! $license) {
                throw new GraphQLException('У вас нет доступа к загрузке файлов для этого сайта.', 'FORBIDDEN');
            }
        } else {
            $domain = $context->request()->header('X-Forwarded-Host') ?? $context->request()->getHost();
            $license = $user->licenses()->where('domain', $domain)->first() ?? $user->licenses()->first();
            if (! $license) {
                throw new GraphQLException('У вас нет доступа к загрузке файлов для этого сайта.', 'FORBIDDEN');
            }
        }

        $licenseId = $license->id;
        $hashedLicenseId = md5($licenseId);
        $folder = $args['folder'] ?? null;
        if ($folder === 'mebel-models') {
            $extension = strtolower($extension);
            if (! (($extension === 'glb' && $mimeType === 'model/gltf-binary')
                || ($extension === 'html' && $mimeType === 'text/html'))) {
                throw new GraphQLException('Для 3D-модели нужен файл GLB или HTML.', 'VALIDATION');
            }
        }
        $prefix = $folder ? trim($folder, '/').'/' : '';
        $hash = Str::random(40);
        $objectKey = "{$prefix}{$hashedLicenseId}/{$hash}.{$extension}";

        $s3Config = config('filesystems.disks.yandex');

        $expiresIn = (int) config('waas.upload_url_ttl', 600);

        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => $s3Config['region'],
                'endpoint' => $s3Config['endpoint'],
                'use_path_style_endpoint' => (bool) $s3Config['use_path_style_endpoint'],
                'credentials' => [
                    'key' => $s3Config['key'],
                    'secret' => $s3Config['secret'],
                ],
            ]);

            $command = $client->getCommand('PutObject', [
                'Bucket' => $s3Config['bucket'],
                'Key' => $objectKey,
                'ContentType' => $mimeType,
            ]);

            $presignedRequest = $client->createPresignedRequest($command, "+{$expiresIn} seconds");
            $presignedUrl = (string) $presignedRequest->getUri();

            // Build the final object URL (public access URL)
            $endpoint = rtrim($s3Config['endpoint'], '/');
            $bucket = $s3Config['bucket'];
            $objectUrl = "{$endpoint}/{$bucket}/{$objectKey}";
        } catch (\Throwable $e) {
            report($e);

            throw new GraphQLException('Хранилище файлов временно недоступно. Повторите попытку позже.', 'INTERNAL');
        }

        return [
            'uploadUrl' => $presignedUrl,
            'objectUrl' => $objectUrl,
            'expiresIn' => $expiresIn,
        ];
    }
}

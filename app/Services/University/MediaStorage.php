<?php

declare(strict_types=1);

namespace App\Services\University;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MediaStorage
{
    public const PART_SIZE = 8388608;

    public const LIMITS = ['video/mp4' => 2147483648, 'image/jpeg' => 10485760, 'image/png' => 10485760, 'image/webp' => 10485760, 'application/pdf' => 52428800];

    public function client(): S3ClientInterface
    {
        $config = config('filesystems.disks.yandex');

        return new S3Client([
            'version' => 'latest', 'region' => $config['region'], 'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ]);
    }

    private function object(object $media): array
    {
        return ['Bucket' => config('filesystems.disks.yandex.bucket'), 'Key' => $media->object_key];
    }

    public function start(int $userId, array $data): array
    {
        abort_unless(config('university.uploads_enabled'), 503, 'Загрузки ещё не настроены. Проверьте закрытое хранилище и CORS.');
        abort_unless(isset(self::LIMITS[$data['mime']]) && $data['size'] > 0 && $data['size'] <= self::LIMITS[$data['mime']], 422, 'Неподдерживаемый тип или размер файла.');
        $extensions = ['video/mp4' => 'mp4', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $id = (string) Str::uuid();
        $row = (object) ['object_key' => 'university/private/'.$id.'.'.$extensions[$data['mime']]];
        $upload = $this->client()->createMultipartUpload($this->object($row) + ['ContentType' => $data['mime'], 'ACL' => 'private']);
        try {
            DB::table('university_media')->insert([
                'id' => $id, 'created_by' => $userId, 'filename' => $data['filename'], 'mime' => $data['mime'],
                'size' => $data['size'], 'duration' => $data['duration'] ?? null,
                'object_key' => $row->object_key, 'upload_id' => $upload['UploadId'], 'status' => 'uploading',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->client()->abortMultipartUpload($this->object($row) + ['UploadId' => $upload['UploadId']]);
            throw $e;
        }

        return ['id' => $id, 'part_size' => self::PART_SIZE, 'parts' => (int) ceil($data['size'] / self::PART_SIZE)];
    }

    public function owned(string $id, int $userId): object
    {
        $row = DB::table('university_media')->where('id', $id)->where('created_by', $userId)->first();
        abort_unless($row, 404);

        return $row;
    }

    public function part(object $row, int $number): string
    {
        abort_unless($row->status === 'uploading' && $number >= 1 && $number <= ceil($row->size / self::PART_SIZE), 422);
        $command = $this->client()->getCommand('UploadPart', $this->object($row) + ['UploadId' => $row->upload_id, 'PartNumber' => $number]);

        return (string) $this->client()->createPresignedRequest($command, '+15 minutes')->getUri();
    }

    public function complete(object $row): array
    {
        return DB::transaction(function () use ($row) {
            $row = DB::table('university_media')->where('id', $row->id)->lockForUpdate()->first();
            if ($row->status === 'ready') {
                return ['id' => $row->id, 'status' => 'ready'];
            }
            abort_unless($row->status === 'uploading', 409, 'Загрузка отменена.');
            $client = $this->client();
            $object = $this->object($row);
            try {
                $parts = $client->listParts($object + ['UploadId' => $row->upload_id])['Parts'] ?? [];
                abort_unless(count($parts) === (int) ceil($row->size / self::PART_SIZE), 422, 'Загружены не все части файла.');
                foreach ($parts as $i => $part) {
                    $expected = min(self::PART_SIZE, $row->size - $i * self::PART_SIZE);
                    abort_unless($part['PartNumber'] === $i + 1 && $part['Size'] === $expected, 422, 'Размер части файла не совпадает.');
                }
                $client->completeMultipartUpload($object + ['UploadId' => $row->upload_id, 'MultipartUpload' => ['Parts' => array_map(fn ($p) => ['PartNumber' => $p['PartNumber'], 'ETag' => $p['ETag']], $parts)]]);
            } catch (S3Exception $e) {
                // Completion may have succeeded before the previous HTTP response was lost.
                if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                    throw $e;
                }
            }
            $head = $client->headObject($object);
            abort_unless((int) $head['ContentLength'] === (int) $row->size && $head['ContentType'] === $row->mime, 422, 'Размер или тип файла не совпадает.');
            $prefix = (string) $client->getObject($object + ['Range' => 'bytes=0-65535'])['Body'];
            abort_unless($this->validSignature($prefix, $row->mime), 422, 'Содержимое файла не соответствует выбранному типу.');
            // A public bucket policy overrides a private ACL. Fail closed, never publish such assets.
            $url = rtrim(config('filesystems.disks.yandex.endpoint'), '/').'/'.$object['Bucket'].'/'.$row->object_key;
            $anonymous = Http::timeout(10)->withOptions(['allow_redirects' => false])->head($url);
            abort_unless(in_array($anonymous->status(), [403, 404], true), 503, 'Учебное хранилище не закрыто для анонимного доступа. Обратитесь к администратору.');
            DB::table('university_media')->where('id', $row->id)->update(['status' => 'ready', 'upload_id' => null, 'updated_at' => now()]);

            return ['id' => $row->id, 'status' => 'ready'];
        });
    }

    public function validSignature(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'video/mp4' => strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp',
            'application/pdf' => str_starts_with($bytes, '%PDF-'),
            'image/jpeg', 'image/png', 'image/webp' => (@getimagesizefromstring($bytes)['mime'] ?? null) === $mime,
            default => false,
        };
    }

    public function abort(object $row): void
    {
        DB::transaction(function () use ($row) {
            $row = DB::table('university_media')->where('id', $row->id)->lockForUpdate()->first();
            if ($row->status !== 'uploading') {
                return;
            }
            try {
                $this->client()->abortMultipartUpload($this->object($row) + ['UploadId' => $row->upload_id]);
            } catch (S3Exception $e) {
                if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                    throw $e;
                }
                $this->client()->deleteObject($this->object($row));
            }
            DB::table('university_media')->where('id', $row->id)->update(['status' => 'cancelled', 'upload_id' => null, 'updated_at' => now()]);
        });
    }

    public function read(string $id): array
    {
        $row = DB::table('university_media')->where('id', $id)->where('status', 'ready')->first();
        abort_unless($row, 404);
        $command = $this->client()->getCommand('GetObject', $this->object($row));

        return ['id' => $id, 'url' => (string) $this->client()->createPresignedRequest($command, '+4 hours')->getUri(), 'mime' => $row->mime, 'filename' => $row->filename, 'duration' => $row->duration, 'expires_in' => 14400];
    }
}

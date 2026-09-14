<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class MebelProjectImages
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Read only files uploaded into one of this owner's furniture folders. */
    public function prepare(array $urls, array $licenseIds): array
    {
        $prefix = rtrim(config('filesystems.disks.yandex.endpoint'), '/')
            .'/'.config('filesystems.disks.yandex.bucket').'/';
        $folders = array_map(fn ($id) => 'mebel/'.md5($id).'/', $licenseIds);
        $images = [];

        foreach ($urls as $index => $url) {
            $key = str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : '';
            $allowed = false;
            foreach ($folders as $folder) {
                if (str_starts_with($key, $folder)
                    && preg_match('/\A[a-zA-Z0-9]{40}\.(jpg|jpeg|png|webp)\z/i', substr($key, strlen($folder)))) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                throw ValidationException::withMessages(["image_urls.$index" => 'Выберите фотографию, загруженную для вашего сайта.']);
            }

            try {
                $disk = Storage::disk('yandex');
                if ($disk->size($key) > self::MAX_BYTES) {
                    throw new \RuntimeException('Image too large');
                }
                $stream = $disk->readStream($key);
                if (! is_resource($stream)) {
                    throw new \RuntimeException('Image unavailable');
                }
                try {
                    $content = stream_get_contents($stream, self::MAX_BYTES + 1);
                } finally {
                    fclose($stream);
                }
                $info = $content ? @getimagesizefromstring($content) : false;
                if (! $info || strlen($content) > self::MAX_BYTES
                    || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    throw new \RuntimeException('Invalid image');
                }
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(["image_urls.$index" => 'Не удалось проверить фотографию. Загрузите JPEG, PNG или WebP размером до 10 МБ.']);
            }

            $images[] = [
                'path' => $url,
                'filename' => basename($key),
                'original_name' => basename($key),
                'hash' => hash('sha256', $content),
                'size' => strlen($content),
                'mime_type' => $info['mime'],
                'sort_order' => $index + 1,
                'is_active' => true,
            ];
        }

        return $images;
    }
}

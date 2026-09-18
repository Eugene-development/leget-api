<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class BrandLogo
{
    public function validate(string $url, string $licenseId): void
    {
        $prefix = rtrim(config('filesystems.disks.yandex.endpoint'), '/').'/'.config('filesystems.disks.yandex.bucket').'/';
        $folder = 'brand-logos/'.md5($licenseId).'/';
        $key = str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : '';
        if (! str_starts_with($key, $folder)
            || ! preg_match('/\A[a-zA-Z0-9]{40}\.(jpg|jpeg|png|webp)\z/i', substr($key, strlen($folder)))) {
            throw ValidationException::withMessages(['logo' => 'Загрузите логотип для текущего сайта.']);
        }
        try {
            $disk = Storage::disk('yandex');
            if ($disk->size($key) > MebelProjectImages::MAX_BYTES) {
                throw new \RuntimeException;
            }
            $stream = $disk->readStream($key);
            if (! is_resource($stream)) {
                throw new \RuntimeException;
            }
            try {
                $content = stream_get_contents($stream, MebelProjectImages::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            $info = $content ? @getimagesizefromstring($content) : false;
            if (! $info || strlen($content) > MebelProjectImages::MAX_BYTES
                || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['logo' => 'Не удалось проверить логотип. Загрузите JPEG, PNG или WebP до 10 МБ.']);
        }
    }
}

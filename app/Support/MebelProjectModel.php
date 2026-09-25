<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** HTML remains untrusted content and is rendered only in a sandboxed, network-isolated frame. */
final class MebelProjectModel
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MAX_HTML_BYTES = 5 * 1024 * 1024;

    public const MAX_TRIANGLES = 300000;

    public const MAX_PARTS = 500;

    public const MAX_TEXTURE_PIXELS = 16777216;

    public function prepare(string $url, string $licenseId): array
    {
        $prefix = rtrim(config('filesystems.disks.yandex.endpoint'), '/')
            .'/'.config('filesystems.disks.yandex.bucket').'/';
        $folder = 'mebel-models/'.md5($licenseId).'/';
        $key = str_starts_with($url, $prefix.$folder) ? substr($url, strlen($prefix)) : '';
        if (! $key || ! preg_match('/\A[a-zA-Z0-9]{40}\.(glb|html)\z/', substr($key, strlen($folder)))) {
            throw ValidationException::withMessages(['model_url' => 'Загрузите GLB или HTML для этого сайта.']);
        }
        $html = str_ends_with($key, '.html');
        $limit = $html ? self::MAX_HTML_BYTES : self::MAX_BYTES;

        try {
            $disk = Storage::disk('yandex');
            $size = $disk->size($key);
            if ($size < 20 || $size > $limit) {
                throw new \RuntimeException('Invalid size');
            }
            $stream = $disk->readStream($key);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Model unavailable');
            }
            try {
                $content = stream_get_contents($stream, $limit + 1);
            } finally {
                fclose($stream);
            }
            if (! is_string($content) || strlen($content) > $limit) {
                throw new \RuntimeException('Incomplete model');
            }
            if (! $html && str_starts_with($content, "\x1f\x8b")) {
                $content = gzdecode($content, self::MAX_BYTES + 1);
                if (! is_string($content) || strlen($content) > self::MAX_BYTES) {
                    throw new \RuntimeException('Invalid compressed model');
                }
            }
            if ($html) {
                if (! mb_check_encoding($content, 'UTF-8') || ! preg_match('/<html\b/i', $content)) {
                    throw new \RuntimeException('Invalid HTML document');
                }
            } else {
                $stats = self::validateGlb($content);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['model_url' => 'Не удалось проверить модель. Нужен GLB 2.0 до 25 МБ или самостоятельный HTML до 5 МБ.']);
        }

        if ($html) {
            return ['url' => $url, 'size' => $size, 'sha256' => hash('sha256', $content), 'format' => 'html'];
        }
        // An immutable derivative: signed PUT URLs for the original cannot overwrite the served model.
        // Keep the original upload for re-export/reprocessing, and use the smaller version for delivery.
        $gzip = gzencode($content, 9);
        if ($gzip === false) {
            throw ValidationException::withMessages(['model_url' => 'Не удалось сжать модель. Повторите сохранение.']);
        }
        $compress = strlen($gzip) < strlen($content);
        $encoded = $compress ? $gzip : $content;
        $deliveryKey = $folder.substr(hash('sha256', 'delivery-v1:'.$content), 0, 40).'.glb';
        try {
            if (! $disk->put($deliveryKey, $encoded, [
                'ContentType' => 'model/gltf-binary',
                'CacheControl' => 'public, max-age=31536000, immutable',
                ...($compress ? ['ContentEncoding' => 'gzip'] : []),
            ])) {
                throw new \RuntimeException('Model storage failed');
            }
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['model_url' => 'Не удалось сохранить сжатую модель. Повторите попытку.']);
        }

        return ['url' => $prefix.$deliveryKey, 'original_url' => $url, 'size' => strlen($content), 'transfer_size' => strlen($encoded),
            'encoding' => $compress ? 'gzip' : 'identity', 'sha256' => hash('sha256', $content), ...$stats];
    }

    public static function validateGlb(string $content): array
    {
        $length = strlen($content);
        if ($length < 20 || $length > self::MAX_BYTES || substr($content, 0, 4) !== 'glTF') {
            throw new \RuntimeException('Invalid GLB');
        }
        $header = unpack('Vversion/Vlength', substr($content, 4, 8));
        if ($header['version'] !== 2 || $header['length'] !== $length) {
            throw new \RuntimeException('Invalid header');
        }
        $chunks = [];
        for ($offset = 12; $offset < $length;) {
            if ($offset + 8 > $length) {
                throw new \RuntimeException('Incomplete chunk');
            }
            $chunk = unpack('Vlength/Vtype', substr($content, $offset, 8));
            $offset += 8;
            if ($chunk['length'] % 4 || $offset + $chunk['length'] > $length
                || isset($chunks[$chunk['type']])) {
                throw new \RuntimeException('Invalid chunk');
            }
            if ($chunks === [] && $chunk['type'] !== 0x4E4F534A) {
                throw new \RuntimeException('JSON must be first');
            }
            if ($chunk['type'] === 0x4E4F534A && $chunk['length'] > 2 * 1024 * 1024) {
                throw new \RuntimeException('Scene description too large');
            }
            $chunks[$chunk['type']] = substr($content, $offset, $chunk['length']);
            $offset += $chunk['length'];
        }
        $json = json_decode($chunks[0x4E4F534A] ?? '', true, 64, JSON_THROW_ON_ERROR);
        if (array_intersect($json['extensionsRequired'] ?? [], ['KHR_draco_mesh_compression', 'EXT_meshopt_compression', 'KHR_texture_basisu'])) {
            throw ValidationException::withMessages(['model_url' => 'Экспортируйте обычный GLB с PNG/JPEG/WebP-текстурами, без Draco, Meshopt и KTX2. Сжатие для доставки выполняется автоматически.']);
        }
        if (($json['asset']['version'] ?? null) !== '2.0' || empty($json['meshes'])
            || empty($json['scenes']) || count($json['buffers'] ?? []) !== 1) {
            throw new \RuntimeException('Missing scene or geometry');
        }
        $binaryLength = strlen($chunks[0x004E4942] ?? '');
        $bufferLength = $json['buffers'][0]['byteLength'] ?? 0;
        if (! is_int($bufferLength) || $bufferLength <= 0 || $bufferLength > $binaryLength
            || $binaryLength - $bufferLength > 3) {
            throw new \RuntimeException('Invalid binary buffer');
        }
        // Reject external and data URIs recursively, including extensions. Textures must use bufferView.
        array_walk_recursive($json, static function ($value, $key) {
            if (strtolower((string) $key) === 'uri') {
                throw new \RuntimeException('External resources are not allowed');
            }
        });
        $parts = 0;
        $triangles = 0;
        // Count instances as well as distinct meshes: a tiny mesh repeated many times still costs GPU time.
        if (count($json['nodes'] ?? []) > 2000) {
            throw ValidationException::withMessages(['model_url' => 'Слишком много объектов в модели. Экспортируйте облегчённую версию.']);
        }
        foreach ($json['nodes'] ?? [] as $node) {
            if (! isset($node['mesh'])) {
                continue;
            }
            foreach ($json['meshes'][$node['mesh']]['primitives'] ?? [] as $primitive) {
                $parts++;
                $accessor = $json['accessors'][$primitive['indices'] ?? $primitive['attributes']['POSITION'] ?? -1] ?? [];
                $count = $accessor['count'] ?? 0;
                if (! is_int($count) || $count < 0) {
                    throw new \RuntimeException('Invalid geometry');
                }
                $triangles += ($primitive['mode'] ?? 4) === 4 ? (int) ceil($count / 3) : $count;
                if ($count > 1000000) {
                    throw new \RuntimeException('Oversized accessor');
                }
            }
        }
        if ($parts > self::MAX_PARTS || $triangles > self::MAX_TRIANGLES) {
            throw ValidationException::withMessages(['model_url' => 'Модель слишком сложная для просмотра на телефоне: максимум 300 000 треугольников и 500 частей. Упростите модель перед загрузкой.']);
        }
        $pixels = 0;
        foreach ($json['bufferViews'] ?? [] as $view) {
            $offset = $view['byteOffset'] ?? 0;
            $size = $view['byteLength'] ?? -1;
            if (! is_int($offset) || ! is_int($size) || $offset < 0 || $size < 0 || $offset + $size > $bufferLength) {
                throw new \RuntimeException('Invalid buffer view');
            }
        }
        foreach ($json['images'] ?? [] as $image) {
            $view = $json['bufferViews'][$image['bufferView'] ?? -1] ?? null;
            if (! $view) {
                throw new \RuntimeException('Missing image buffer');
            }
            $info = @getimagesizefromstring(substr($chunks[0x004E4942], $view['byteOffset'] ?? 0, $view['byteLength']));
            if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new \RuntimeException('Unsupported texture');
            }
            $pixels += $info[0] * $info[1];
            if ($info[0] > 4096 || $info[1] > 4096 || $pixels > self::MAX_TEXTURE_PIXELS) {
                throw ValidationException::withMessages(['model_url' => 'Текстуры слишком большие: максимум 4096 px по стороне и 16 мегапикселей суммарно. Уменьшите текстуры перед загрузкой.']);
            }
        }

        return ['triangles' => $triangles, 'parts' => $parts, 'texture_pixels' => $pixels];
    }
}

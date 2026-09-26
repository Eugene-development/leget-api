<?php

declare(strict_types=1);

namespace Tests\Feature\MebelProject;

use App\Support\MebelProjectImages;
use App\Support\ProjectImageCaption;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Tests\TestCase;

final class ProjectImageCaptionTest extends TestCase
{
    private function captionOptions(array $overrides = []): array
    {
        return array_replace(['text' => 'Мебель', 'position' => 'left', 'font_size' => 32, 'opacity' => 100, 'color' => '#ffffff', 'padding' => 20, 'bold' => false], $overrides);
    }

    private function original(): string
    {
        return (string) ImageManager::gd()->create(640, 480)->fill('#000000')->toPng();
    }

    public function test_all_positions_render_cyrillic_at_the_bottom(): void
    {
        foreach (['left', 'center', 'right'] as $position) {
            $content = app(ProjectImageCaption::class)->render($this->original(), $this->captionOptions(['position' => $position]));
            $image = imagecreatefromstring($content);
            $xs = $ys = [];
            for ($y = 0; $y < 480; $y++) {
                for ($x = 0; $x < 640; $x++) {
                    if ((imagecolorat($image, $x, $y) & 255) > 100) {
                        $xs[] = $x;
                        $ys[] = $y;
                    }
                }
            }
            self::assertNotEmpty($xs);
            self::assertGreaterThan(400, min($ys));
            self::assertLessThan(462, max($ys));
            if ($position === 'left') {
                self::assertLessThan(25, min($xs));
            } elseif ($position === 'right') {
                self::assertGreaterThan(610, max($xs));
            } else {
                self::assertEqualsWithDelta(320, (min($xs) + max($xs)) / 2, 4);
            }
            imagedestroy($image);
        }
    }

    public function test_zero_opacity_keeps_photo_visually_unchanged_and_long_text_fits(): void
    {
        $output = app(ProjectImageCaption::class)->render($this->original(), $this->captionOptions(['opacity' => 0]));
        self::assertSame((string) ImageManager::gd()->read($this->original())->toWebp(90), $output);
        $output = app(ProjectImageCaption::class)->render($this->original(), $this->captionOptions(['text' => str_repeat('Ш', 120), 'font_size' => 160, 'bold' => true]));
        self::assertSame([640, 480], array_slice(getimagesizefromstring($output), 0, 2));
    }

    public function test_derivatives_are_stable_and_never_overwrite_originals(): void
    {
        Storage::fake('yandex');
        config(['filesystems.disks.yandex.endpoint' => 'https://storage.yandexcloud.net', 'filesystems.disks.yandex.bucket' => 'leget-main']);
        $key = 'mebel/'.md5('owner').'/'.str_repeat('a', 40).'.png';
        $original = $this->original();
        Storage::disk('yandex')->put($key, $original);
        $url = 'https://storage.yandexcloud.net/leget-main/'.$key;
        $service = app(MebelProjectImages::class);
        $first = $service->prepare([$url], ['owner'], $this->captionOptions());
        self::assertNotSame($url, $first[0]['path']);
        self::assertSame('image/webp', $first[0]['mime_type']);
        self::assertSame($first, $service->prepare([$url], ['owner'], $this->captionOptions()));
        self::assertSame($original, Storage::disk('yandex')->get($key));
        self::assertSame($url, $service->prepare([$url], ['owner'])[0]['path']);
        self::assertNotSame($first[0]['path'], $service->prepare([$url], ['owner'], $this->captionOptions(['opacity' => 50]))[0]['path']);
        $this->expectException(ValidationException::class);
        $service->prepare([$url], ['another-owner'], $this->captionOptions());
    }

    public function test_failed_storage_write_does_not_return_an_unsaved_image(): void
    {
        config(['filesystems.disks.yandex.endpoint' => 'https://storage.yandexcloud.net', 'filesystems.disks.yandex.bucket' => 'leget-main']);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->original());
        rewind($stream);
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->once()->andReturn(strlen($this->original()));
        $disk->shouldReceive('readStream')->once()->andReturn($stream);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('yandex')->andReturn($disk);
        $this->expectException(ValidationException::class);
        app(MebelProjectImages::class)->prepare(['https://storage.yandexcloud.net/leget-main/mebel/'.md5('owner').'/'.str_repeat('a', 40).'.png'], ['owner'], $this->captionOptions());
    }

    public function test_invalid_caption_settings_are_rejected(): void
    {
        foreach ([['text' => ' '], ['text' => str_repeat('а', 121)], ['font_size' => 161], ['opacity' => -1], ['padding' => 101], ['position' => 'top'], ['color' => 'url(https://example.com)'], ['font' => '/etc/passwd']] as $invalid) {
            try {
                ProjectImageCaption::validate($this->captionOptions($invalid));
                self::fail('Expected invalid caption to be rejected');
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors());
            }
        }
    }
}

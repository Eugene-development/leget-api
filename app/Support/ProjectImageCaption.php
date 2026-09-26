<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

final class ProjectImageCaption
{
    public static function validate(?array $options): ?array
    {
        if ($options === null) {
            return null;
        }
        $options['text'] = preg_replace('/\s+/u', ' ', trim($options['text'] ?? ''));

        return Validator::make(['image_caption' => $options], [
            'image_caption' => ['array:text,position,font_size,opacity,color,padding,bold'],
            'image_caption.text' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'image_caption.position' => ['required', 'in:left,center,right'],
            'image_caption.font_size' => ['required', 'integer', 'between:12,160'],
            'image_caption.opacity' => ['required', 'integer', 'between:0,100'],
            'image_caption.color' => ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'image_caption.padding' => ['required', 'integer', 'between:0,100'],
            'image_caption.bold' => ['required', 'boolean'],
        ], ['image_caption.*' => 'Проверьте настройки подписи: текст до 120 символов, размер 12–160 px, непрозрачность и отступ 0–100.'])
            ->validate()['image_caption'];
    }

    public function render(string $content, array $options): string
    {
        $image = ImageManager::gd()->read($content);
        // Bundled with the existing dompdf dependency, including Cyrillic glyphs.
        $fontPath = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans'.($options['bold'] ? '-Bold' : '').'.ttf');
        $padding = min($options['padding'], (int) (min($image->width(), $image->height()) / 4));
        $size = $options['font_size'];
        // A single line must fit even for narrow images or long unbroken words.
        do {
            $bounds = imagettfbbox($size * 0.76, 0, $fontPath, $options['text']);
            $width = max($bounds[0], $bounds[2], $bounds[4], $bounds[6]) - min($bounds[0], $bounds[2], $bounds[4], $bounds[6]);
            $height = max($bounds[1], $bounds[3], $bounds[5], $bounds[7]) - min($bounds[1], $bounds[3], $bounds[5], $bounds[7]);
            if ($width <= $image->width() - 2 * $padding && $height <= $image->height() - 2 * $padding) {
                break;
            }
            $size--;
        } while ($size > 1);
        $x = match ($options['position']) {
            'center' => (int) round($image->width() / 2),
            'right' => $image->width() - $padding,
            default => $padding,
        };
        $rgb = sscanf($options['color'], '#%02x%02x%02x');
        $color = sprintf('rgba(%d, %d, %d, %s)', ...[...$rgb, $options['opacity'] / 100]);
        $image->text($options['text'], $x, $image->height() - $padding, function (FontFactory $font) use ($fontPath, $size, $color, $options) {
            $font->filename($fontPath)->size($size)->color($color)->align($options['position'])->valign('bottom');
        });

        return (string) $image->toWebp(90);
    }
}

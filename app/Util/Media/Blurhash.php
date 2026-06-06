<?php

namespace App\Util\Media;

use App\Media;
use App\Util\Blurhash\Blurhash as BlurhashEngine;

class Blurhash
{
    const DEFAULT_HASH = 'U4Rfzst8?bt7ogayj[j[~pfQ9Goe%Mj[WBay';

    public static function generate(Media $media, $path = false)
    {
        if (! in_array($media->mime, ['image/png', 'image/jpeg', 'image/jpg', 'video/mp4'])) {
            return self::DEFAULT_HASH;
        }

        if ($media->thumbnail_path == null) {
            return self::DEFAULT_HASH;
        }

        if ($path) {
            $file = $path;
        } else {
            $localFs = config('filesystems.default') === 'local';
            $file = storage_path('app/'.$media->thumbnail_path);
        }

        if (! is_file($file)) {
            return self::DEFAULT_HASH;
        }

        $image = imagecreatefromstring(file_get_contents($file));
        if (! $image) {
            return self::DEFAULT_HASH;
        }
        $width = imagesx($image);
        $height = imagesy($image);

        // Blurhash 4×4 DCT components need no more than ~32px per axis.
        // Without this cap a 1920×1080 frame requires ~1.1 GB PHP heap (2M pixels
        // × 272 bytes array overhead, doubled by the linear-color encoder), which
        // exceeds the default memory_limit and causes a fatal PHP error on HD video.
        $maxDim = 100;
        if ($width > $maxDim || $height > $maxDim) {
            $ratio = min($maxDim / $width, $maxDim / $height);
            $newWidth = max(1, (int) round($width * $ratio));
            $newHeight = max(1, (int) round($height * $ratio));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                return self::DEFAULT_HASH;
            }
            if (! imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                imagedestroy($resized);
                imagedestroy($image);
                return self::DEFAULT_HASH;
            }
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);
                $colors = imagecolorsforindex($image, $index);

                $row[] = [$colors['red'], $colors['green'], $colors['blue']];
            }
            $pixels[] = $row;
        }

        imagedestroy($image);

        $components_x = 4;
        $components_y = 4;
        $blurhash = BlurhashEngine::encode($pixels, $components_x, $components_y);
        if (strlen($blurhash) > 191) {
            return self::DEFAULT_HASH;
        }

        return $blurhash;
    }
}

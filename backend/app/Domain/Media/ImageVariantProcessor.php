<?php

namespace App\Domain\Media;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns an uploaded image into the app's fixed 16:9 artwork: one card image
 * (up to 1280×720) and one thumbnail (up to 640×360), both re-encoded as WebP.
 *
 * Re-encoding is the point as much as resizing: the bytes that reach storage
 * are produced here from decoded pixels, so metadata, trailing data and
 * polyglot payloads in the upload never survive, and a file that merely
 * claims to be an image fails to decode and is rejected. The source is
 * cropped, never stretched. $focalX/$focalY (0–100) say which part of an
 * image that is not already 16:9 stays in frame, using the same rule as CSS
 * `object-position`, so an admin's preview matches the stored result.
 */
final class ImageVariantProcessor
{
    public const MIN_WIDTH = 640;

    public const MIN_HEIGHT = 360;

    private const MAX_SIDE = 6000;

    /** Decoded bitmaps cost 4 bytes per pixel; this keeps one upload under ~64 MB of memory. */
    private const MAX_PIXELS = 16_000_000;

    private const VARIANTS = ['main' => 1280, 'thumb' => 640];

    private const QUALITY = 82;

    /**
     * Cheap checks that don't decode the image: it must be a readable
     * JPEG/PNG/WebP of a sane size that still reaches the minimum after the
     * 16:9 crop. Used to give feedback the moment a file is chosen.
     *
     * @return array{path: string, width: int, height: int, type: int}
     *
     * @throws ValidationException
     */
    public function inspect(UploadedFile $file, int $focalX = 50, int $focalY = 50): array
    {
        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);

        if ($info === false) {
            throw $this->invalid('That file could not be read as an image.');
        }

        [$width, $height, $type] = $info;

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw $this->invalid('Upload a JPEG, PNG or WebP image.');
        }

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw $this->invalid('That image is too large. Use one up to 4000×4000 pixels.');
        }

        [$cropWidth, $cropHeight] = $this->cropRect($width, $height, $focalX, $focalY);

        if ($cropWidth < self::MIN_WIDTH || $cropHeight < self::MIN_HEIGHT) {
            throw $this->invalid(sprintf(
                'That image is too small. Cropped to 16:9 it would be %d×%d; at least %d×%d is needed.',
                $cropWidth, $cropHeight, self::MIN_WIDTH, self::MIN_HEIGHT,
            ));
        }

        return ['path' => $path, 'width' => $width, 'height' => $height, 'type' => $type];
    }

    /**
     * @return array{main: string, thumb: string, mime: string}
     *
     * @throws ValidationException
     */
    public function process(UploadedFile $file, int $focalX = 50, int $focalY = 50): array
    {
        ['path' => $path, 'width' => $width, 'height' => $height, 'type' => $type] = $this->inspect($file, $focalX, $focalY);

        $source = $this->decode($path, $type);

        if (! $source instanceof GdImage) {
            throw $this->invalid('That image appears to be damaged and could not be processed.');
        }

        $source = $this->applyExifOrientation($source, $path, $type);

        // Orientation can swap the sides; recompute the crop for what we actually have.
        [$cropWidth, $cropHeight, $cropX, $cropY] = $this->cropRect(imagesx($source), imagesy($source), $focalX, $focalY);

        if ($cropWidth < self::MIN_WIDTH || $cropHeight < self::MIN_HEIGHT) {
            imagedestroy($source);

            throw $this->invalid('That image is too small once rotated and cropped to 16:9.');
        }

        try {
            $variants = [];

            foreach (self::VARIANTS as $name => $targetWidth) {
                // A multiple of 16 keeps the output exactly 16:9, not within a pixel of it.
                $outWidth = intdiv(min($targetWidth, $cropWidth), 16) * 16;
                $outHeight = intdiv($outWidth * 9, 16);
                $variants[$name] = $this->render($source, $cropX, $cropY, $cropWidth, $cropHeight, $outWidth, $outHeight);
            }
        } finally {
            imagedestroy($source);
        }

        return ['main' => $variants['main'], 'thumb' => $variants['thumb'], 'mime' => 'image/webp'];
    }

    /**
     * The largest 16:9 window inside a width×height image, positioned like
     * CSS object-position: at P% the window's offset is P% of the free space.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} width, height, x, y
     */
    public function cropRect(int $width, int $height, int $focalX, int $focalY): array
    {
        $focalX = max(0, min(100, $focalX));
        $focalY = max(0, min(100, $focalY));

        if ($width * 9 > $height * 16) {
            $cropWidth = (int) round($height * 16 / 9);

            return [$cropWidth, $height, (int) round(($width - $cropWidth) * $focalX / 100), 0];
        }

        $cropHeight = (int) round($width * 9 / 16);

        return [$width, $cropHeight, 0, (int) round(($height - $cropHeight) * $focalY / 100)];
    }

    private function decode(string $path, int $type): GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };
    }

    private function applyExifOrientation(GdImage $image, string $path, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);

        return $rotated instanceof GdImage ? $rotated : throw $this->invalid('That image could not be processed.');
    }

    private function render(GdImage $source, int $cropX, int $cropY, int $cropWidth, int $cropHeight, int $outWidth, int $outHeight): string
    {
        $canvas = imagecreatetruecolor($outWidth, $outHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $outWidth, $outHeight, $cropWidth, $cropHeight);

        ob_start();
        imagewebp($canvas, null, self::QUALITY);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['image' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Safe image uploads for logos and icons.
 *
 *  - the file's CONTENT decides what it is (finfo), never its name or the browser's Content-Type;
 *  - only PNG / JPEG / WebP / GIF are accepted — SVG is refused on purpose (it can carry scripts);
 *  - dimensions are checked BEFORE decoding (decompression-bomb guard);
 *  - the image is fully decoded and RE-ENCODED with GD, so metadata, embedded payloads and
 *    polyglot tricks are discarded; output is WebP (or PNG when WebP is unavailable);
 *  - files get random names inside public/uploads/<kind>/, where PHP execution is disabled.
 */
final class Assets
{
    public const MAX_BYTES = 2_097_152;
    private const MAX_PIXELS = 16_000_000;

    /** kind → [max width/height, folder] */
    private const KINDS = [
        'site_logo'     => [512, 'branding'],
        'favicon'       => [256, 'branding'],
        'platform_logo' => [256, 'platforms'],
        'service_icon'  => [160, 'services'],
    ];

    /**
     * @param array<string,mixed> $file one entry of $_FILES
     * @return array{path:string,url:string,width:int,height:int,bytes:int,format:string}
     */
    public static function fromUpload(array $file, string $kind): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new HttpException($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 413 : 422, match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large (maximum 2 MB).',
                UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
                default => 'The upload failed. Please try again.',
            }, 'upload_failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli')) {
            throw new HttpException(422, 'Invalid upload.', 'upload_failed');
        }
        if ((int) filesize($tmp) > self::MAX_BYTES) {
            throw new HttpException(413, 'That image is too large (maximum 2 MB).', 'too_large');
        }
        $bytes = (string) file_get_contents($tmp);
        return self::fromBytes($bytes, $kind);
    }

    /** @return array{path:string,url:string,width:int,height:int,bytes:int,format:string} */
    public static function fromBytes(string $bytes, string $kind): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unknown asset kind.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new HttpException(503, 'Image processing (PHP GD) is not available on this server.', 'gd_missing');
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new HttpException(413, 'That image is too large (maximum 2 MB).', 'too_large');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            throw new HttpException(422, 'Only PNG, JPEG, WebP or GIF images are allowed (SVG and other files are not accepted).', 'bad_type');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 16 || $info[1] < 16) {
            throw new HttpException(422, 'That file is not a valid image (minimum 16×16 pixels).', 'bad_image');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new HttpException(422, 'That image has too many pixels. Please use one under 4000×4000.', 'too_large');
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new HttpException(422, 'That image could not be read. Try saving it again as PNG or JPEG.', 'bad_image');
        }
        // honour phone-camera rotation, then discard all metadata by re-encoding
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $rot = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($rot !== 0 && ($r = imagerotate($src, $rot, 0)) !== false) {
                $src = $r;
            }
        }

        [$max, $folder] = self::KINDS[$kind];
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $max / max($w, $h));
        $nw = max(16, (int) round($w * $scale));
        $nh = max(16, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $webp = function_exists('imagewebp');
        ob_start();
        $webp ? imagewebp($dst, null, 86) : imagepng($dst, null, 9);
        $out = (string) ob_get_clean();
        imagedestroy($dst);
        if ($out === '') {
            throw new HttpException(500, 'The image could not be processed.', 'encode_failed');
        }

        $dir = SMB_PUBLIC . '/uploads/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HttpException(500, 'The uploads folder is not writable. Set permissions on public/uploads to 755 or 775.', 'not_writable');
        }
        $name = bin2hex(random_bytes(10)) . ($webp ? '.webp' : '.png');
        if (@file_put_contents($dir . '/' . $name, $out) === false) {
            throw new HttpException(500, 'The uploads folder is not writable. Set permissions on public/uploads to 755 or 775.', 'not_writable');
        }
        @chmod($dir . '/' . $name, 0644);
        $rel = $folder . '/' . $name;
        return ['path' => $rel, 'url' => (string) upload_url($rel), 'width' => $nw, 'height' => $nh, 'bytes' => strlen($out), 'format' => $webp ? 'webp' : 'png'];
    }

    /** Only files this class created can be deleted (no path traversal). */
    public static function isOwnPath(?string $path): bool
    {
        return is_string($path) && (bool) preg_match('#^(branding|platforms|services)/[a-f0-9]{20}\.(webp|png)$#D', $path);
    }

    public static function delete(?string $path): void
    {
        if (self::isOwnPath($path)) {
            $f = SMB_PUBLIC . '/uploads/' . $path;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}

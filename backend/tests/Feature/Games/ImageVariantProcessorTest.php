<?php

namespace Tests\Feature\Games;

use App\Domain\Media\ImageVariantProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImageVariantProcessorTest extends TestCase
{
    /**
     * A wide image whose left half is red and right half blue, so which part
     * survives a crop is visible in the output pixels.
     */
    private function splitImage(int $width, int $height, string $name = 'split.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width, $height, imagecolorallocate($image, 0, 0, 255));
        $path = tempnam(sys_get_temp_dir(), 'img');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * @return array{0: int, 1: int, 2: array{0: int, 1: int, 2: int}} width, height, rgb of a pixel at ($x, $y)
     */
    private function inspectWebp(string $bytes, int $x = 0, int $y = 0): array
    {
        $image = imagecreatefromstring($bytes);
        $rgb = imagecolorat($image, $x, $y);

        return [imagesx($image), imagesy($image), [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255]];
    }

    public function test_a_16_9_image_becomes_a_1280_card_and_a_640_thumbnail_in_webp(): void
    {
        $result = (new ImageVariantProcessor)->process($this->splitImage(1920, 1080));

        $this->assertSame('image/webp', $result['mime']);
        $this->assertSame([1280, 720], array_slice($this->inspectWebp($result['main']), 0, 2));
        $this->assertSame([640, 360], array_slice($this->inspectWebp($result['thumb']), 0, 2));
        $this->assertStringStartsWith('RIFF', $result['main']);
        $this->assertSame('WEBP', substr($result['main'], 8, 4));
    }

    public function test_other_ratios_are_cropped_to_exactly_16_9_never_stretched(): void
    {
        foreach ([[1600, 1200], [1200, 1600], [3000, 1000], [1000, 1000]] as [$w, $h]) {
            [$outW, $outH] = $this->inspectWebp((new ImageVariantProcessor)->process($this->splitImage($w, $h))['main']);

            $this->assertSame(0, $outW * 9 - $outH * 16, "{$w}x{$h} did not come out 16:9 ({$outW}x{$outH})");
        }
    }

    public function test_the_crop_position_decides_which_part_of_a_wide_image_stays(): void
    {
        $processor = new ImageVariantProcessor;

        // 2400x900 is wider than 16:9, so the window slides horizontally.
        $left = $this->inspectWebp($processor->process($this->splitImage(2400, 900), 0, 50)['main'], 5, 5);
        $right = $this->inspectWebp($processor->process($this->splitImage(2400, 900), 100, 50)['main'], 1270, 5);

        $this->assertGreaterThan(200, $left[2][0], 'focal 0 should keep the red left edge');
        $this->assertGreaterThan(200, $right[2][2], 'focal 100 should keep the blue right edge');
    }

    public function test_crop_rect_follows_the_css_object_position_rule(): void
    {
        $processor = new ImageVariantProcessor;

        $this->assertSame([1600, 900, 0, 0], $processor->cropRect(1600, 900, 40, 70));
        $this->assertSame([1600, 900, 800, 0], $processor->cropRect(2400, 900, 100, 0));
        $this->assertSame([1600, 900, 400, 0], $processor->cropRect(2400, 900, 50, 0));
        $this->assertSame([1000, 563, 0, 437], $processor->cropRect(1000, 1000, 50, 100));
        $this->assertSame([1600, 900, 0, 0], $processor->cropRect(1600, 900, 999, -5), 'out-of-range focal values are clamped');
    }

    public function test_an_image_too_small_after_cropping_is_rejected(): void
    {
        foreach ([[500, 300], [1200, 300], [639, 1000]] as [$w, $h]) {
            try {
                (new ImageVariantProcessor)->process($this->splitImage($w, $h));
                $this->fail("{$w}x{$h} should have been rejected");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('too small', $e->errors()['image'][0]);
            }
        }
    }

    public function test_oversized_dimensions_are_rejected_before_decoding(): void
    {
        $this->expectException(ValidationException::class);

        (new ImageVariantProcessor)->process($this->splitImage(6100, 20));
    }

    public function test_a_file_that_only_claims_to_be_an_image_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, "<?php echo 'not an image'; ?>");
        $fake = new UploadedFile($path, 'evil.png', 'image/png', null, true);

        $this->expectException(ValidationException::class);

        (new ImageVariantProcessor)->process($fake);
    }

    public function test_a_truncated_image_is_rejected_cleanly(): void
    {
        $good = $this->splitImage(1600, 900);
        $path = tempnam(sys_get_temp_dir(), 'cut');
        file_put_contents($path, substr((string) file_get_contents($good->getRealPath()), 0, 400));

        $this->expectException(ValidationException::class);

        (new ImageVariantProcessor)->process(new UploadedFile($path, 'cut.png', 'image/png', null, true));
    }

    public function test_unsupported_image_formats_are_rejected(): void
    {
        $image = imagecreatetruecolor(1280, 720);
        $path = tempnam(sys_get_temp_dir(), 'gif');
        imagegif($image, $path);

        try {
            (new ImageVariantProcessor)->process(new UploadedFile($path, 'anim.gif', 'image/gif', null, true));
            $this->fail('GIF should be rejected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('JPEG, PNG or WebP', $e->errors()['image'][0]);
        }
    }

    public function test_the_stored_bytes_are_freshly_encoded_so_embedded_payloads_do_not_survive(): void
    {
        $upload = $this->splitImage(1600, 900);
        file_put_contents($upload->getRealPath(), '<?php system($_GET["c"]); ?>', FILE_APPEND);

        $result = (new ImageVariantProcessor)->process($upload);

        $this->assertStringNotContainsString('system(', $result['main']);
        $this->assertStringNotContainsString('<?php', $result['thumb']);
    }
}

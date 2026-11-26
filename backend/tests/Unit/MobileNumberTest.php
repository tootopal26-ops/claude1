<?php

namespace Tests\Unit;

use App\Support\MobileNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileNumberTest extends TestCase
{
    /**
     * @return list<list<string>>
     */
    public static function messyFormats(): array
    {
        return [
            ['+91 82730 93895'],
            ['918273093895'],
            ['08273093895'],
            ['(827) 309-3895'],
        ];
    }

    #[DataProvider('messyFormats')]
    public function test_normalize_strips_country_code_trunk_prefix_and_punctuation(string $messy): void
    {
        $this->assertSame('8273093895', MobileNumber::normalize($messy));
    }

    public function test_a_genuine_ten_digit_number_starting_91_is_left_alone(): void
    {
        $this->assertSame('9123456789', MobileNumber::normalize('9123456789'));
        $this->assertTrue(MobileNumber::isValid('9123456789'));
    }

    public function test_an_already_clean_number_is_unchanged(): void
    {
        $this->assertSame('8273093895', MobileNumber::normalize('8273093895'));
    }

    public function test_is_valid_accepts_only_exactly_ten_digits_after_normalising(): void
    {
        $this->assertTrue(MobileNumber::isValid('+91 8273093895'));
        $this->assertTrue(MobileNumber::isValid('08273093895'));
    }

    /**
     * @return list<list<string>>
     */
    public static function garbage(): array
    {
        return [
            ['abcdefghij'],
            ['12345'],
            ['123456789012345'],
            [''],
            ['91827309389'], // 11 digits, starts 91, but not 12 — not a country-code case
        ];
    }

    #[DataProvider('garbage')]
    public function test_garbage_is_not_valid(string $garbage): void
    {
        $this->assertFalse(MobileNumber::isValid($garbage));
    }

    public function test_format_renders_a_display_string_with_the_country_code(): void
    {
        $this->assertSame('+91 8273093895', MobileNumber::format('8273093895'));
        $this->assertSame('+91 8273093895', MobileNumber::format('+91 82730 93895'));
    }
}

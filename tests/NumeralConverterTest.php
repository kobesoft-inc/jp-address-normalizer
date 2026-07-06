<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\Internal\NumeralConverter;
use PHPUnit\Framework\TestCase;

final class NumeralConverterTest extends TestCase
{
    /** @dataProvider kanjiToArabicCases */
    public function testKanjiToArabic(string $input, string $expected): void
    {
        $this->assertSame($expected, NumeralConverter::kanjiToArabic($input));
    }

    public static function kanjiToArabicCases(): array
    {
        return [
            ['北一条西', '北１条西'],
            ['曙一条', '曙１条'],
            ['神居八条', '神居８条'],
            ['大通西', '大通西'],
        ];
    }

    /** @dataProvider arabicToKanjiCases */
    public function testArabicToKanji(string $input, string $expected): void
    {
        $this->assertSame($expected, NumeralConverter::arabicToKanji($input));
    }

    public static function arabicToKanjiCases(): array
    {
        return [
            ['北１条西', '北一条西'],
            ['新琴似７条', '新琴似七条'],
            ['第４７線', '第四十七線'],
            ['大通西', '大通西'],
        ];
    }
}

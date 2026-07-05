<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\StreetParser;
use PHPUnit\Framework\TestCase;

final class StreetParserTest extends TestCase
{
    /** @dataProvider cases */
    public function testParse(string $input, ?int $chome, ?int $banchi, ?int $banchiSub, ?int $go): void
    {
        $street = StreetParser::parse($input);
        $this->assertSame($chome, $street->chome);
        $this->assertSame($banchi, $street->banchi);
        $this->assertSame($banchiSub, $street->banchiSub);
        $this->assertSame($go, $street->go);
        $this->assertSame($input, $street->raw);
    }

    public static function cases(): array
    {
        return [
            ['６丁目１－２', 6, 1, 2, null],
            ['４丁目１番地', 4, 1, null, null],
            ['２丁目１番１１号', 2, 1, null, 11],
            ['５７番地３１', null, 57, 31, null],
            ['１８３の１５', null, 183, 15, null],
            ['１４丁目', 14, null, null, null],
            // 丁目を示すキーワードが無くても、ハイフン区切りの数字3つは
            // 「丁目-番-号」を意味する慣用的な表記として解釈する。
            ['８－１３－１４', 8, 13, null, 14],
            ['２－８－１', 2, 8, null, 1],
        ];
    }

    /** @dataProvider alphaChomeCases */
    public function testParseAlphaChome(string $input, string $chomeLabel, ?int $banchi): void
    {
        $street = StreetParser::parse($input);
        $this->assertNull($street->chome);
        $this->assertSame($chomeLabel, $street->chomeLabel);
        $this->assertSame($banchi, $street->banchi);
    }

    public static function alphaChomeCases(): array
    {
        return [
            // 区画整理の経緯等で、丁目番号の代わりにアルファベットが使われている地域が実在する。
            ['Ａ丁目１番２号', 'A', 1],
            ['B丁目３番地', 'B', 3],
            ['ｃ丁目', 'C', null],
        ];
    }

    /** @dataProvider unparsableCases */
    public function testUnparsablePatternsKeepRawOnly(string $input): void
    {
        $street = StreetParser::parse($input);
        $this->assertNull($street->chome);
        $this->assertNull($street->banchi);
        $this->assertSame($input, $street->raw);
        $this->assertSame($input, $street->format());
    }

    public static function unparsableCases(): array
    {
        return [
            ['２地割８１－１'],
            ['第４７線北１３番地'],
        ];
    }
}

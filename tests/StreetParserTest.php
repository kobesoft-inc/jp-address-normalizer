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

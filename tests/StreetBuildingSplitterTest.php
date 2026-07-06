<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\Internal\StreetBuildingSplitter;
use PHPUnit\Framework\TestCase;

final class StreetBuildingSplitterTest extends TestCase
{
    /** @dataProvider cases */
    public function testSplit(string $input, string $expectedStreet, string $expectedBuilding): void
    {
        $result = StreetBuildingSplitter::split($input);
        $this->assertSame($expectedStreet, $result['street']);
        $this->assertSame($expectedBuilding, $result['building']);
    }

    public static function cases(): array
    {
        return [
            ['６丁目１－２アーバンネット札幌ビル２Ｆ', '６丁目１－２', 'アーバンネット札幌ビル２Ｆ'],
            ['５丁目地下鉄大通駅西側コンコース内', '５丁目', '地下鉄大通駅西側コンコース内'],
            ['４丁目１番地', '４丁目１番地', ''],
            ['１丁目６番地さっぽろ創生スクエア９階', '１丁目６番地', 'さっぽろ創生スクエア９階'],
            ['１８３の１５（浦臼郵便局私書箱第１号）', '１８３の１５', '（浦臼郵便局私書箱第１号）'],
            ['目黒テラス', '', '目黒テラス'],
            ['Ａ丁目１番２号', 'Ａ丁目１番２号', ''],
            ['B丁目３番地', 'B丁目３番地', ''],
            ['ABCビル３階', '', 'ABCビル３階'],
            ['', '', ''],
        ];
    }
}

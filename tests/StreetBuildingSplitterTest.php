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

    /**
     * 漢数字や「の」が地名の一部として単語の途中に現れる字名は、番地の開始と
     * 誤認識して単語を分断してはいけない（「一已」「八木田」「地の岡」等）。
     *
     * @dataProvider azaWordSplitCases
     */
    public function testDoesNotSplitAzaNameMidWord(string $input, string $expectedAza, string $expectedStreet): void
    {
        $result = StreetBuildingSplitter::split($input);
        $this->assertSame($expectedAza, $result['aza']);
        $this->assertSame($expectedStreet, $result['street']);
        $this->assertSame('', $result['building']);
    }

    public static function azaWordSplitCases(): array
    {
        return [
            ['字一已１８６３番地', '字一已', '１８６３番地'],
            ['字上八木田１０', '字上八木田', '１０'],
            ['字権九郎新田４９６番地１', '字権九郎新田', '４９６番地１'],
            ['字地の岡６４番３', '字地の岡', '６４番３'],
            ['字北内町４１番地の５', '字北内町', '４１番地の５'],
        ];
    }

    /**
     * 字名と建物名の間に区切りが無く一般ロジックでは分離できないケースは、
     * `AddressExceptions`の既知の字名テーブルと照合できたcity_codeが渡された
     * 場合のみ正しく分離できる。
     */
    public function testUsesKnownAzaTableWhenCityCodeProvided(): void
    {
        $withoutCityCode = StreetBuildingSplitter::split('字小曲コーポパッション１０３');
        $this->assertSame('字小曲コーポパッション', $withoutCityCode['aza']);
        $this->assertSame('１０３', $withoutCityCode['street']);
        $this->assertSame('', $withoutCityCode['building']);

        $withCityCode = StreetBuildingSplitter::split('字小曲コーポパッション１０３', '13421');
        $this->assertSame('字小曲', $withCityCode['aza']);
        $this->assertSame('１０３', $withCityCode['street']);
        $this->assertSame('コーポパッション', $withCityCode['building']);
    }
}

<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\AddressMatcher;
use JpAddressNormalizer\AddressNormalizer;
use JpAddressNormalizer\MatchLevel;
use JpAddressNormalizer\ParsedAddress;
use JpAddressNormalizer\Street;
use PHPUnit\Framework\TestCase;

final class AddressMatcherTest extends TestCase
{
    private static ?AddressMatcher $matcher = null;

    public static function setUpBeforeClass(): void
    {
        $dbPath = __DIR__ . '/../jp_postal_code.db';
        if (!file_exists($dbPath)) {
            self::markTestSkipped('jp_postal_code.db not found');
        }
        $normalizer = new AddressNormalizer($dbPath);
        self::$matcher = new AddressMatcher($normalizer);
    }

    // ================================================================
    // Exact: 完全一致（表記ゆれを正規化後に同一）
    // ================================================================

    public function testExactMatch(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5-3',
            '東京都渋谷区道玄坂１丁目５−３'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }

    public function testExactMatchFullWidthVsHalfWidth(): void
    {
        $level = self::$matcher->compare(
            '北海道札幌市中央区北一条西5丁目2-1',
            '北海道札幌市中央区北一条西５丁目２−１'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }

    public function testExactMatchKanjiNumerals(): void
    {
        $level = self::$matcher->compare(
            '北海道札幌市中央区北一条西5丁目2-1',
            '北海道札幌市中央区北一条西五丁目二-一'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }

    public function testExactMatchIdenticalStrings(): void
    {
        $level = self::$matcher->compare(
            '東京都千代田区丸の内1-1',
            '東京都千代田区丸の内1-1'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }

    public function testExactMatchVariousHyphens(): void
    {
        // FULLWIDTH HYPHEN-MINUS vs standard hyphen
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5－3',
            '東京都渋谷区道玄坂1丁目5-3'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }

    // ================================================================
    // Go: 号まで一致（建物名が異なる）
    // ================================================================

    public function testGoLevelDifferentBuilding(): void
    {
        $level = self::$matcher->compare(
            '東京都新宿区西新宿2丁目8番1号ビルA',
            '東京都新宿区西新宿2丁目8番1号ビルB'
        );
        $this->assertSame(MatchLevel::Go, $level);
    }

    public function testGoLevelOneMissingBuilding(): void
    {
        // 片方に建物名がない場合 — goまでは一致しているのでGoレベル
        $level = self::$matcher->compare(
            '東京都新宿区西新宿2丁目8番1号都庁',
            '東京都新宿区西新宿2丁目8番1号'
        );
        // 片方に建物名が無い場合、go が一致していれば Go
        // ただし片方buildingが空 → Exact判定ではない（buildingA !== buildingB）
        $this->assertSame(MatchLevel::Go, $level);
    }

    // ================================================================
    // Banchi: 番地まで一致（号が異なる）
    // ================================================================

    public function testBanchiLevelDifferentGo(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5番3号',
            '東京都渋谷区道玄坂1丁目5番9号'
        );
        $this->assertSame(MatchLevel::Banchi, $level);
    }

    // ================================================================
    // Chome: 丁目まで一致（番地が異なる）
    // ================================================================

    public function testChomeLevelMatch(): void
    {
        $level = self::$matcher->compare(
            '北海道札幌市中央区北一条西5丁目2-1',
            '北海道札幌市中央区北一条西5丁目9-9'
        );
        $this->assertSame(MatchLevel::Chome, $level);
    }

    public function testChomeLevelDifferentBanchi(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5-3',
            '東京都渋谷区道玄坂1丁目10-1'
        );
        $this->assertSame(MatchLevel::Chome, $level);
    }

    // ================================================================
    // Town: 町名まで一致（丁目が異なる）
    // ================================================================

    public function testTownLevelMatch(): void
    {
        $level = self::$matcher->compare(
            '北海道札幌市中央区北一条西5丁目2-1',
            '北海道札幌市中央区北一条西22丁目1-1'
        );
        $this->assertSame(MatchLevel::Town, $level);
    }

    public function testTownLevelDifferentChome(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5-3',
            '東京都渋谷区道玄坂2丁目10-1'
        );
        $this->assertSame(MatchLevel::Town, $level);
    }

    // ================================================================
    // City: 市区町村まで一致（町名が異なる）
    // ================================================================

    public function testCityLevelDifferentTown(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5-3',
            '東京都渋谷区千駄ヶ谷1-1'
        );
        $this->assertSame(MatchLevel::City, $level);
    }

    // ================================================================
    // Prefecture: 都道府県まで一致（市区町村が異なる）
    // ================================================================

    public function testPrefectureLevelDifferentCity(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5-3',
            '東京都新宿区西新宿2-8-1'
        );
        $this->assertSame(MatchLevel::Prefecture, $level);
    }

    // ================================================================
    // None: 一致しない（都道府県が異なる）
    // ================================================================

    public function testNoneDifferentPrefecture(): void
    {
        $level = self::$matcher->compare(
            '東京都渋谷区道玄坂1丁目5-3',
            '北海道札幌市中央区北一条西5丁目2-1'
        );
        $this->assertSame(MatchLevel::None, $level);
    }

    // ================================================================
    // compareNormalized: 直接ParsedAddressを比較するケース
    // ================================================================

    public function testCompareNormalizedBothPrefectureNull(): void
    {
        $emptyStreet = new Street('', null, null, null, null);
        $a = new ParsedAddress(null, null, null, '', $emptyStreet, '');
        $b = new ParsedAddress(null, null, null, '', $emptyStreet, '');
        $this->assertSame(MatchLevel::None, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedOnePrefectureNull(): void
    {
        $emptyStreet = new Street('', null, null, null, null);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $emptyStreet, '');
        $b = new ParsedAddress(null, null, null, '', $emptyStreet, '');
        $this->assertSame(MatchLevel::None, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedSamePrefectureDifferentCity(): void
    {
        $emptyStreet = new Street('', null, null, null, null);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $emptyStreet, '');
        $b = new ParsedAddress(null, '13', '13104', '西新宿', $emptyStreet, '');
        $this->assertSame(MatchLevel::Prefecture, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedSameCityDifferentTown(): void
    {
        $street = new Street('1丁目5-3', 1, 5, 3, null);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $street, '');
        $b = new ParsedAddress(null, '13', '13113', '千駄ヶ谷', $street, '');
        $this->assertSame(MatchLevel::City, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedSameTownDifferentChome(): void
    {
        $streetA = new Street('1丁目5-3', 1, 5, 3, null);
        $streetB = new Street('2丁目5-3', 2, 5, 3, null);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $streetA, '');
        $b = new ParsedAddress(null, '13', '13113', '道玄坂', $streetB, '');
        $this->assertSame(MatchLevel::Town, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedSameChomeDifferentBanchi(): void
    {
        $streetA = new Street('1丁目5-3', 1, 5, 3, null);
        $streetB = new Street('1丁目10-3', 1, 10, 3, null);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $streetA, '');
        $b = new ParsedAddress(null, '13', '13113', '道玄坂', $streetB, '');
        $this->assertSame(MatchLevel::Chome, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedSameBanchiDifferentGo(): void
    {
        $streetA = new Street('1丁目5番3号', 1, 5, null, 3);
        $streetB = new Street('1丁目5番9号', 1, 5, null, 9);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $streetA, '');
        $b = new ParsedAddress(null, '13', '13113', '道玄坂', $streetB, '');
        $this->assertSame(MatchLevel::Banchi, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedExactWithBuilding(): void
    {
        $street = new Street('1丁目5番3号', 1, 5, null, 3);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $street, 'ビルA');
        $b = new ParsedAddress(null, '13', '13113', '道玄坂', $street, 'ビルA');
        $this->assertSame(MatchLevel::Exact, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedGoWithDifferentBuilding(): void
    {
        $street = new Street('1丁目5番3号', 1, 5, null, 3);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $street, 'ビルA');
        $b = new ParsedAddress(null, '13', '13113', '道玄坂', $street, 'ビルB');
        $this->assertSame(MatchLevel::Go, AddressMatcher::compareNormalized($a, $b));
    }

    public function testCompareNormalizedBanchiSubDiffers(): void
    {
        $streetA = new Street('5-3', null, 5, 3, null);
        $streetB = new Street('5-7', null, 5, 7, null);
        $a = new ParsedAddress(null, '13', '13113', '道玄坂', $streetA, '');
        $b = new ParsedAddress(null, '13', '13113', '道玄坂', $streetB, '');
        $this->assertSame(MatchLevel::Banchi, AddressMatcher::compareNormalized($a, $b));
    }

    // ================================================================
    // 都道府県省略のケース
    // ================================================================

    public function testPrefectureOmittedStillMatches(): void
    {
        $level = self::$matcher->compare(
            '帯広市自由が丘1丁目3-5',
            '北海道帯広市自由が丘1丁目3-5'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }

    // ================================================================
    // 郵便番号付きの住所
    // ================================================================

    public function testWithPostalCodePrefix(): void
    {
        $level = self::$matcher->compare(
            '〒150-0043 東京都渋谷区道玄坂1-5-3',
            '東京都渋谷区道玄坂1丁目5-3'
        );
        $this->assertSame(MatchLevel::Exact, $level);
    }
}

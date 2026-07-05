<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\AddressNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * マニアックな住所パターンで郵便番号の逆引きが正しく動くかを検証する統合テスト。
 * 実際のjp_postal_code.dbを使用する。
 */
final class AddressNormalizerIntegrationTest extends TestCase
{
    private static ?AddressNormalizer $normalizer = null;

    public static function setUpBeforeClass(): void
    {
        $dbPath = __DIR__ . '/../jp_postal_code.db';
        if (!file_exists($dbPath)) {
            self::markTestSkipped('jp_postal_code.db not found');
        }
        self::$normalizer = new AddressNormalizer($dbPath);
    }

    private function assertPostalCode(string $expectedPostalCode, string $address, string $message = ''): void
    {
        $result = self::$normalizer->normalize($address);
        $this->assertSame(
            $expectedPostalCode,
            $result->postalCode,
            $message ?: "Address: {$address}"
        );
    }

    // ================================================================
    // 丁目で郵便番号が分かれる（札幌市中央区 北一条西）
    // ================================================================

    public function testSapporoChomeRange1to19(): void
    {
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5丁目2-1');
    }

    public function testSapporoChomeRange20to28(): void
    {
        $this->assertPostalCode('0640821', '北海道札幌市中央区北一条西22丁目1-1');
    }

    // ================================================================
    // 丁目の列挙パターン（帯広市 自由が丘）
    // ================================================================

    public function testObihiroChomeList(): void
    {
        $this->assertPostalCode('0800848', '北海道帯広市自由が丘1丁目3-5');
        $this->assertPostalCode('0802476', '北海道帯広市自由が丘5丁目10-1');
    }

    // ================================================================
    // 番地以上/以下（紀の川市 竹房）
    // ================================================================

    public function testBanchiBound(): void
    {
        $this->assertPostalCode('6496413', '和歌山県紀の川市竹房100');
        $this->assertPostalCode('6496162', '和歌山県紀の川市竹房500');
        $this->assertPostalCode('6496413', '和歌山県紀の川市竹房450');
        $this->assertPostalCode('6496162', '和歌山県紀の川市竹房451');
    }

    // ================================================================
    // 番地の範囲 + その他（札幌市南区 常盤）
    // ================================================================

    public function testBanchiRangeWithCatchAll(): void
    {
        $this->assertPostalCode('0050865', '北海道札幌市南区常盤50');
        $this->assertPostalCode('0050863', '北海道札幌市南区常盤200');
    }

    // ================================================================
    // 地区名テキストマッチ（稲沢市 平和町）
    // ================================================================

    public function testDistrictNameMatch(): void
    {
        $this->assertPostalCode('4901322', '愛知県稲沢市平和町下前浪1-1');
        $this->assertPostalCode('4901305', '愛知県稲沢市平和町鷲尾1-1');
    }

    // ================================================================
    // 高層ビル階数パターン
    // ================================================================

    public function testHighriseFloor(): void
    {
        $this->assertPostalCode('4506010', '愛知県名古屋市中村区名駅ＪＲセントラルタワーズ10階');
        $this->assertPostalCode('4506035', '愛知県名古屋市中村区名駅ＪＲセントラルタワーズ35F');
        $this->assertPostalCode('4506090', '愛知県名古屋市中村区名駅ＪＲセントラルタワーズ');
    }

    // ================================================================
    // 京都の通り名テキストマッチ
    // ================================================================

    public function testKyotoStreetName(): void
    {
        $this->assertPostalCode('6008310', '京都府京都市下京区夷之町七条通新町西入');
    }

    // ================================================================
    // 都道府県省略
    // ================================================================

    public function testPrefectureOmitted(): void
    {
        $this->assertPostalCode('0800848', '帯広市自由が丘1丁目3-5');
    }

    // ================================================================
    // 全角/半角の数字混在
    // ================================================================

    public function testFullWidthNumbers(): void
    {
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西５丁目２−１');
    }

    public function testHalfWidthNumbers(): void
    {
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5丁目2-1');
    }

    // ================================================================
    // 漢数字
    // ================================================================

    public function testKanjiNumeralChome(): void
    {
        $this->assertPostalCode('0640821', '北海道札幌市中央区北一条西二十二丁目1-1');
    }

    // ================================================================
    // ハイフン区切りの丁目-番-号表記
    // ================================================================

    public function testHyphenatedChomeBanchiGo(): void
    {
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5-2-1');
        $this->assertPostalCode('0640821', '北海道札幌市中央区北一条西22-1-1');
    }

    // ================================================================
    // 全角ハイフン（−, ー, －）
    // ================================================================

    public function testFullWidthHyphens(): void
    {
        // FULLWIDTH HYPHEN-MINUS (U+FF0D)
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5丁目2－1');
        // MINUS SIGN (U+2212) — treated as separator
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5丁目2−1');
        // KATAKANA-HIRAGANA PROLONGED SOUND MARK (U+30FC) — sometimes used as hyphen
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5丁目2ー1');
    }

    // ================================================================
    // 「の」で番地を区切るパターン
    // ================================================================

    public function testNoSeparator(): void
    {
        $this->assertPostalCode('0600001', '北海道札幌市中央区北一条西5丁目2の1');
    }

    // ================================================================
    // 「番地」「番」「号」を明示
    // ================================================================

    public function testExplicitBanchiGo(): void
    {
        $this->assertPostalCode('1500043', '東京都渋谷区道玄坂1丁目5番地3号');
        $this->assertPostalCode('1500043', '東京都渋谷区道玄坂1丁目5番3号');
    }

    // ================================================================
    // 郵便番号付き住所
    // ================================================================

    public function testWithPostalCode(): void
    {
        $result = self::$normalizer->normalize('〒150-0043 東京都渋谷区道玄坂1-5-3');
        $this->assertSame('1500043', $result->postalCode);
    }

    public function testWithPostalCodeNoHyphen(): void
    {
        $result = self::$normalizer->normalize('〒1500043 東京都渋谷区道玄坂1-5-3');
        $this->assertSame('1500043', $result->postalCode);
    }

    // ================================================================
    // ケ/ヶ の表記ゆれ
    // ================================================================

    public function testKeGaVariation(): void
    {
        // DB上は「千駄ヶ谷」（小さいヶ）
        $this->assertPostalCode('1510051', '東京都渋谷区千駄ヶ谷1-1');
        // 大きいケで書く人もいる
        $this->assertPostalCode('1510051', '東京都渋谷区千駄ケ谷1-1');
    }

    // ================================================================
    // 丸の内（「の」を含む町名）
    // ================================================================

    public function testTownNameWithNo(): void
    {
        $this->assertPostalCode('1000005', '東京都千代田区丸の内1-1');
    }

    // ================================================================
    // 西新宿（郵便番号が1つ — 階数分割なし）
    // ================================================================

    public function testNishiShinjuku(): void
    {
        $this->assertPostalCode('1600023', '東京都新宿区西新宿2-8-1');
    }

    // ================================================================
    // 空白が混入
    // ================================================================

    public function testWithSpaces(): void
    {
        $result = self::$normalizer->normalize('東京都 渋谷区 道玄坂 1-5-3');
        // 空白が町名の先頭に入るとマッチしにくいが、都道府県・市区町村は空白をスキップすべき
        // 現状の実装がどこまで対応しているか確認
        $this->assertSame('13113', $result->cityCode);
    }

    // ================================================================
    // 帯広市 西十九条南（条+丁目の混在する範囲パターン）
    // ================================================================

    public function testObihiroJouPattern(): void
    {
        $result = self::$normalizer->normalize('北海道帯広市西十九条南35丁目1-1');
        $this->assertSame('01207', $result->cityCode);
        $this->assertSame('西十九条南', $result->town);
    }
}

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

    // 「府中市」は東京都・広島県の2市に存在するが、続く町名がどちらか一方にしか
    // 実在しない場合は、それをもとに一意に判定できる（推測ではなく判定）。
    public function testAmbiguousCityDisambiguatedByTownName(): void
    {
        $tokyo = self::$normalizer->normalize('府中市朝日町1-1');
        $this->assertSame('東京都', $tokyo->prefectureName);
        $this->assertSame('1830003', $tokyo->postalCode);

        $hiroshima = self::$normalizer->normalize('府中市阿字町1-1');
        $this->assertSame('広島県', $hiroshima->prefectureName);
        $this->assertSame('7293212', $hiroshima->postalCode);
    }

    // 町名からも一意に決められない場合は、誤った推測をするより未解決のままにする。
    public function testAmbiguousCityStaysUnresolvedWithoutDistinguishingTown(): void
    {
        $result = self::$normalizer->normalize('府中市1-1');
        $this->assertNull($result->prefectureCode);
        $this->assertNull($result->cityCode);
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

    // ================================================================
    // 町名なし地域の番地範囲区分（小菅村）
    // ================================================================

    public function testKosugeMuraBanchiRange(): void
    {
        $this->assertPostalCode('4090142', '山梨県北都留郡小菅村100');
        $this->assertPostalCode('4090142', '山梨県北都留郡小菅村663');
        $this->assertPostalCode('4090211', '山梨県北都留郡小菅村664');
        $this->assertPostalCode('4090211', '山梨県北都留郡小菅村4000');
    }

    // ================================================================
    // 番地以降パターン（ParsedDetailのBanchiBound）
    // ================================================================

    public function testBanchiBoundIkou(): void
    {
        // 小菅村: ６６４番地以降 → 4090211
        $result = self::$normalizer->normalize('山梨県北都留郡小菅村664番地');
        $this->assertSame('4090211', $result->postalCode);
    }

    // ================================================================
    // 町名に「町」が付加/省略されるパターン
    // ================================================================

    // DB上の町名「清滝」に、住所側で「町」が付加されて書かれるケース。
    public function testTownWithAppendedMachi(): void
    {
        $result = self::$normalizer->normalize('栃木県日光市清滝町500');
        $this->assertSame('清滝', $result->town);
        $this->assertSame('500', $result->street->format());
        $this->assertSame('', $result->building);
        $this->assertSame('3211444', $result->postalCode);
    }

    // 「番町」（新潟市中央区等）は「番」だけでは「町」の手前で一致が止まり、
    // 番地が丸ごとbuildingに落ちてしまっていた。
    public function testBanchoAfterBanchi(): void
    {
        $result = self::$normalizer->normalize('新潟県新潟市中央区東堀前通7番町1071-1');
        $this->assertSame('東堀前通', $result->town);
        $this->assertSame('7-1071', $result->street->format());
        $this->assertSame('', $result->building);
        $this->assertSame('9518066', $result->postalCode);
    }

    // 町名に付加される「町」の候補は、「大字」「字」除去マッチには使わない。
    // 使ってしまうと「貞光」＋「字」＋「町」という並びが「貞光」＋「町」という
    // 付加バリエーションに一致してしまい、続く小字名の「字」以降を丸ごと失う。
    public function testAppendedMachiDoesNotSwallowAzaMarker(): void
    {
        $result = self::$normalizer->normalize('徳島県美馬郡つるぎ町貞光字町37番地');
        $this->assertSame('貞光', $result->town);
        $this->assertSame('37', $result->street->format());
        $this->assertSame('', $result->building);
    }

    // 町名再マッチのフォールバックは、番地に辿り着けない場合は採用しない。
    // 「弘法町」に続く「弘法山」の頭が偶然「弘法」という別の町名と前方一致するが、
    // 採用すると「弘法山」全体が失われてしまう。
    public function testTownRetryDoesNotDropTextWithoutStreet(): void
    {
        $result = self::$normalizer->normalize('愛知県知立市弘法町弘法山19');
        $this->assertSame('弘法町', $result->town);
        $this->assertSame('弘法山19', $result->building);
        $this->assertSame('愛知県知立市弘法町弘法山19', $result->format());
    }

    // ================================================================
    // 建物名の分割漏れ
    // ================================================================

    // 「小字」も「字」「大字」と同じアザ・マーカーとして認識する。
    public function testKoazaMarker(): void
    {
        $result = self::$normalizer->normalize('京都府舞鶴市字南田辺小字南裏町149');
        $this->assertSame('南田辺', $result->town);
        $this->assertSame('小字南裏町', $result->aza);
        $this->assertSame('149', $result->street->format());
        $this->assertSame('', $result->building);
        $this->assertSame('6240853', $result->postalCode);
    }

    // 「23号303室」のように、「号」の直後に区切りなく続く数字（部屋番号等）は
    // 番地の一部ではなくbuildingに残す。STREET_PATTERNが貪欲に取り込んだ後、
    // StreetParserが3つ目以降の数字を保持できずに消えてしまっていた。
    public function testDigitsAfterGoStayInBuilding(): void
    {
        $result = self::$normalizer->normalize('千葉県市川市市川南3丁目14番23号303室');
        $this->assertSame('市川南', $result->town);
        $this->assertSame('3丁目14番23号', $result->street->format());
        $this->assertSame('303室', $result->building);
        $this->assertSame('2720033', $result->postalCode);
    }

    // 番地・建物名が続かず「字」＋地名だけで終わる場合、残り全体をazaとして保持する
    // （番地の開始位置を手がかりにできないため、従来はbuildingに落ちていた）。
    public function testAzaWithNothingFollowing(): void
    {
        $result = self::$normalizer->normalize('東京都小笠原村父島字東町');
        $this->assertSame('父島', $result->town);
        $this->assertSame('字東町', $result->aza);
        $this->assertSame('', $result->building);
        $this->assertSame('1002101', $result->postalCode);
    }

    // STREET_PATTERNは裸の漢数字1文字も番地の一部として認識するため、番地・号が
    // 確定した直後にビル名の先頭の漢数字（「十条会館」の「十」等）だけを誤って
    // 取り込んでしまっていた。
    public function testLeadingKanjiNumeralInBuildingNameStaysInBuilding(): void
    {
        $result = self::$normalizer->normalize('東京都北区上十条2丁目5番13号十条会館204-B');
        $this->assertSame('上十条', $result->town);
        $this->assertSame('2丁目5番13号', $result->street->format());
        $this->assertSame('十条会館204-B', $result->building);
        $this->assertSame('1140034', $result->postalCode);
    }

    // 「一ノ瀬ビル」「二ノ宮ビル」のような「○ノ○」型のビル名は、算用数字に直接
    // 続く裸の漢数字（「一」）が番地の続きと誤認識されていた。
    public function testKanjiNumeralAfterArabicDigitStaysInBuilding(): void
    {
        $result = self::$normalizer->normalize('東京都板橋区大山町9-5一ノ瀬ビル501');
        $this->assertSame('大山町', $result->town);
        $this->assertSame('9-5', $result->street->format());
        $this->assertSame('一ノ瀬ビル501', $result->building);
    }

    // ハイフン区切りの漢数字表記（丁目・番地・号が漢数字で書かれ、ハイフンで
    // 連結される正規のパターン）は、上記の取り残し判定の対象にしない。
    public function testHyphenatedKanjiNumeralStreetIsNotTrimmed(): void
    {
        $result = self::$normalizer->normalize('北海道札幌市中央区北一条西五丁目二-一');
        $this->assertSame('5丁目2-1', $result->street->format());
        $this->assertSame('', $result->building);
    }

    // 「ノ町」は新潟市中央区等の「番町」の異表記（例:「東湊町通2ノ町2577番地1」）。
    // 「ノ」だけでは「町」の手前で一致が止まり、続く番地が丸ごとbuildingに落ちてしまう。
    public function testNochoAfterDigit(): void
    {
        $result = self::$normalizer->normalize('新潟県新潟市中央区東湊町通2ノ町2577番地1');
        $this->assertSame('東湊町通', $result->town);
        $this->assertSame('2-2577', $result->street->format());
        $this->assertSame('', $result->building);
        $this->assertSame('9518028', $result->postalCode);
    }

    // 「486番地の甲」「2505番地の内イ」のような、番地内の小分け表記（甲乙丙等の
    // 順序表記や「内」＋記号）は数字の枝番（banchiSub）に構造化できないため、
    // 文字列としてbanchiSubLabelに保持する（buildingへの取り残しを避ける）。
    public function testBanchiSubLabelIsStructured(): void
    {
        $result = self::$normalizer->normalize('埼玉県入間市宮寺486番地の甲');
        $this->assertSame('宮寺', $result->town);
        $this->assertSame('甲', $result->street->banchiSubLabel);
        $this->assertNull($result->street->banchiSub);
        $this->assertSame('486の甲', $result->street->format());
        $this->assertSame('', $result->building);
        $this->assertSame('埼玉県入間市宮寺486の甲', $result->format());
    }

    public function testBanchiSubLabelWithInnerMark(): void
    {
        $result = self::$normalizer->normalize('茨城県つくば市上郷2505番地の内イ');
        $this->assertSame('上郷', $result->town);
        $this->assertSame('内イ', $result->street->banchiSubLabel);
        $this->assertSame('2505の内イ', $result->street->format());
        $this->assertSame('', $result->building);
    }

    // 「西18線9番地」（北海道の方角＋線名）は、数字の前に付く方角の接頭語のために
    // STREET_PATTERN（先頭アンカー）が一致せず、丸ごとbuildingに落ちていた。
    public function testDirectionPrefixedLine(): void
    {
        $result = self::$normalizer->normalize('北海道川上郡標茶町字熊牛原野西18線9番地');
        $this->assertSame('熊牛原野', $result->town);
        $this->assertSame('西18線9番地', $result->street->raw);
        $this->assertSame('', $result->building);
        $this->assertSame('0883145', $result->postalCode);
    }

    // 「第10地割85番地」（岩手県等の開拓地番）も同様に、数字の前に付く「第」の
    // ためにSTREET_PATTERNが一致せず、丸ごとbuildingに落ちていた。
    public function testDaiChiwariPrefix(): void
    {
        $result = self::$normalizer->normalize('岩手県九戸郡軽米町大字軽米第10地割85番地');
        $this->assertSame('軽米', $result->town);
        $this->assertSame('第10地割85番地', $result->street->raw);
        $this->assertSame('', $result->building);
        $this->assertSame('0286302', $result->postalCode);
    }

    // 「七ノ坪100番地」（「ノ坪」＝番町・ノ町と同じ住所区分単位）も、「ノ」だけでは
    // 「坪」の手前で一致が止まり、続く番地が丸ごとbuildingに落ちてしまっていた。
    public function testTsuboAfterDigit(): void
    {
        $result = self::$normalizer->normalize('京都府向日市寺戸町七ノ坪100番地');
        $this->assertSame('寺戸町', $result->town);
        $this->assertSame('', $result->building);
        $this->assertSame('6170002', $result->postalCode);
    }

    // 甲乙丙丁の他に十二支（子丑寅卯辰巳午未申酉戌亥）で番地の枝番の順序を
    // 表す地域もある。片仮名の「ノ」表記にも対応する。
    public function testBanchiSubLabelWithZodiac(): void
    {
        $result = self::$normalizer->normalize('新潟県十日町市中条戊800番地ノ甲');
        $this->assertSame('中条戊', $result->town);
        $this->assertSame('甲', $result->street->banchiSubLabel);
        $this->assertSame('', $result->building);
        $this->assertSame('9498618', $result->postalCode);
    }

    // 町名の候補がこの市区町村に実在するにもかかわらず一致しなかった場合
    // （例:「大阪市港区九条通四丁目361番地」— 「九条」は実際には大阪市西区の町名）、
    // 番地の開始位置が分からないため、地名先頭の裸の漢数字（「九条」の「九」）を
    // 誤って番地として取り込まず、残り全体をbuildingとして保持する。
    public function testUnmatchedTownDoesNotMisparseLeadingKanjiNumeral(): void
    {
        $result = self::$normalizer->normalize('大阪府大阪市港区九条通四丁目361番地');
        $this->assertSame('', $result->town);
        $this->assertSame('', $result->street->format());
        $this->assertSame('九条通四丁目361番地', $result->building);
        $this->assertNull($result->postalCode);
    }

    // 「大字」「小字」「字」から始まる場合は、町名マッチの成否に関わらず
    // StreetBuildingSplitter側で安全にazaとして処理できるため、上記の
    // フォールバック（buildingへの丸ごと退避）の対象外とする。
    public function testUnmatchedTownStillExtractsAzaMarker(): void
    {
        $result = self::$normalizer->normalize('鹿児島県大島郡与論町大字麦屋868番地2');
        $this->assertSame('', $result->town);
        $this->assertSame('大字麦屋', $result->aza);
        $this->assertSame('868-2', $result->street->format());
        $this->assertSame('', $result->building);
    }

    // 京都の通り名表記の後、町名の短縮バリエーション（「東側町」→「東側」）に
    // 一致してしまい、続く「二条殿町」という本来の（より長い）町名を再マッチで
    // 見つけられなくなっていた（matchedLengthに通り名部分が含まれるため、
    // 長さの比較を誤っていた）。再マッチが採用された場合も、最初のマッチで
    // 消費された文字列（通り名を含む）はkyotoStreetに保持し、失わない。
    public function testKyotoRetryFindsLongerTownAndPreservesDiscardedPrefix(): void
    {
        $result = self::$normalizer->normalize('京都府京都市中京区烏丸御池上る東側二条殿町541番地泰宏ビル');
        $this->assertSame('二条殿町', $result->town);
        $this->assertSame('烏丸御池上る東側', $result->kyotoStreet);
        $this->assertSame('541', $result->street->format());
        $this->assertSame('泰宏ビル', $result->building);
        $this->assertSame('京都府京都市中京区烏丸御池上る東側二条殿町541泰宏ビル', $result->format());
        $this->assertSame('6040845', $result->postalCode);
    }

    // 「五条72番地4」（愛知県清須市等、方角無しの「N条」）は、町名の後に続く
    // 裸の漢数字「五」だけが番地の数字と誤認識され、続く「条72番地4」がbuildingに
    // 取り残されていた。「条」を番地として構造化する仕組みは無いため、情報を失わない
    // よう残り全体をbuildingとして保持する（郵便番号は解決できないままで良い）。
    public function testBareKanjiNumeralWithoutFollowingKeywordStaysInBuilding(): void
    {
        $result = self::$normalizer->normalize('愛知県清須市朝日五条72番地4');
        $this->assertSame('朝日', $result->town);
        $this->assertSame('', $result->street->format());
        $this->assertSame('五条72番地4', $result->building);
        $this->assertSame('愛知県清須市朝日五条72番地4', $result->format());
    }
}

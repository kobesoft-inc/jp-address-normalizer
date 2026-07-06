<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

/**
 * 一般的なロジックでは判定できない、市区町村（city_code）ごとの既知の例外パターンを
 * 保持するテーブル。
 *
 * `AZA_NAMES`は、字名と建物名の間に数字も区切り文字も無いケース（例:「字小曲コーポパッション103」）で、
 * 実在する字名を直接照合するために使う。`StreetBuildingSplitter`の一般ロジックは番地の開始位置を
 * 数字や「丁目」「番地」等のキーワードから探すため、区切りが無いと字名と建物名を分離できない。
 *
 * `OMITTABLE_TOWN_PREFIXES`は、DB上の正式な町名（例:「神田三崎町」）から、かつての区名等に
 * 由来する接頭辞が省略された表記（「三崎町」）に対応する。`TownMatcher`の「○○町○○」大字名
 * 繰り返し省略と同じ安全策として、接頭辞を除いた名前が同じ市区町村内の別の独立した町名と
 * 衝突する場合は追加しない。
 *
 * 括弧書きの旧地名の分割（「東京都文京区（東京市小石川区久堅町91番地）」のようなケース）は、
 * 個別の市区町村データではなく「町名の直後に丸括弧が来る」という書式上の特徴だけで
 * 判定できる、`AddressExceptions`とは性質の異なる汎用ロジックのため、消費元である
 * `StreetBuildingSplitter`側に実装している。
 *
 * 京都の通り名が省略され、かつ同じ町名が複数の郵便番号ブロックに分かれている場合
 * （例:「京都市中京区河原町通御池下る大黒町5番地」の通り名部分が無いケース）、その番地が
 * どのブロックに属するかを住所文字列だけから決める情報は無いため、このテーブルには含めない。
 * 決め打ちの登録は「判定」ではなく「推測」であり、外れれば誤った郵便番号を返す。
 */
final class AddressExceptions
{
    /**
     * city_code => 既知の字名の一覧（長い順）。
     * `StreetBuildingSplitter`が「大字」「字」に続く字名の終わりを、
     * 番地や建物名の開始位置から逆算するのではなく、ここに登録された
     * 実在の字名と直接照合することで判定する。
     *
     * @var array<string, list<string>>
     */
    private const AZA_NAMES = [
        // 東京都小笠原村: 「字小曲コーポパッション103」→ 字名「小曲」+ 建物名「コーポパッション103」
        '13421' => ['小曲'],
        // 岩手県大船渡市: 「字みどり町コーポ田中嶋101」→ 字名「みどり町」+ 建物名「コーポ田中嶋101」
        '03203' => ['みどり町'],
    ];

    /**
     * city_code => 省略されうる旧区名等の町名接頭辞の一覧。
     *
     * @var array<string, list<string>>
     */
    private const OMITTABLE_TOWN_PREFIXES = [
        // 東京都千代田区: 神田三崎町 → 三崎町 等（旧神田区に由来する28町全てに付く接頭辞）
        '13101' => ['神田'],
        // 東京都中央区: 日本橋大伝馬町 → 大伝馬町 等（旧日本橋区に由来する接頭辞）
        '13102' => ['日本橋'],
        // 東京都新宿区: 四谷本塩町 → 本塩町 等（住居表示未実施地区で使われる旧四谷区由来の接頭辞）
        '13104' => ['四谷'],
    ];

    /**
     * $textの先頭（「大字」「字」を除いた部分）が、$cityCodeの既知の字名と一致するか調べる。
     * 一致すればその字名を返す（「大字」「字」自体は含まない）。
     */
    public static function matchKnownAza(string $cityCode, string $text): ?string
    {
        foreach (self::AZA_NAMES[$cityCode] ?? [] as $name) {
            if (str_starts_with($text, $name)) {
                return $name;
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function omittableTownPrefixes(string $cityCode): array
    {
        return self::OMITTABLE_TOWN_PREFIXES[$cityCode] ?? [];
    }
}

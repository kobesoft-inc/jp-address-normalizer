<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

/**
 * 町名より後ろの文字列を、番地部分(street)と建物名等(building)に正規表現で分割する。
 *
 * 数字・丁目・番地・号・線・区・「の」（旧仮名遣いの「ノ」を含む）・ハイフン類が続く限りをstreetとみなし、
 * それ以外の文字（建物名、私書箱の注記等）が現れた時点で以降をbuildingとする。
 */
final class StreetBuildingSplitter
{
    // 数字の連続、または「丁目」「番地」「番」「号」「線」「区」「地割」「の」というキーワード単位、
    // または区切りのハイフン類の繰り返し。「地」や「目」を単独の文字クラスに含めないのは、
    // 「地下鉄」「目黒」のような建物名・地名の先頭と誤って連結するのを防ぐため。
    // 「地割」（北海道の開拓地番）はキーワードとして認識するが、数字の抽出はStreetParser側で行わない。
    // 「Ａ丁目」のようにアルファベットが丁目番号の代わりに使われる地域があるため、
    // アルファベットは単独では認識せず「丁目」に直接続く場合のみキーワード単位として認識する
    // （「ABCビル」のような建物名の先頭を誤ってstreetに取り込まないため）。
    // 「の」は片仮名の「ノ」で書かれることもある（例:「30番地ノ1」＝「30番地の1」、
    // 戦前の登記書類等でよく見られる表記）ため、同じ区切り文字として扱う。
    private const STREET_PATTERN = '/^(?:[A-Za-zＡ-Ｚａ-ｚ]+丁目|[0-90-9０-９一二三四五六七八九十百千]+|丁目|番地|地割|番|号|線|区|[のノ]|[\-－ー])+/u';
    // 先頭アンカー無しで番地の開始位置を探す際に使う、より厳格なパターン。
    // STREET_PATTERNと違い、裸の漢数字1文字（一/八/九等）や「の」を開始トリガーに含めない。
    // 「一已」「八木田」「九郎新田」「地の岡」のように、漢数字や「の」が地名の一部として
    // 単語の途中に現れることがあり、それを番地の開始と誤認識して単語を分断してしまうため
    // （例:「字一已１８６３番地」→ 誤って「字」+「一」+「已１８６３番地」に3分割される）。
    // 算用数字や「丁目」「番地」等の明確なキーワードのみを開始トリガーとする。
    private const ANYWHERE_STREET_PATTERN = '/(?:[0-90-9０-９]+|丁目|番地|地割|番|号|線|区)+/u';
    // 括弧書きの旧地名（`looksLikeObsoleteAnnotation`）専用の開始位置検出パターン。
    // 旧区名（「小石川区」「浅草区」等）は必ず「区」で終わるため、ANYWHERE_STREET_PATTERNのまま
    // 使うと「区」自体を番地の開始と誤認識し、実際の番地に辿り着く前に区名の途中で
    // 区切ってしまう。「区」「線」は数字に後続する場合のみ意味を持つキーワードなので、
    // ここでは開始トリガーから外す（一致箇所の続きを切り出す際はSTREET_PATTERN側の「区」
    // 「線」が引き続き機能するため、番地に後続する「区」「線」の取りこぼしは無い）。
    private const OBSOLETE_ANNOTATION_START_PATTERN = '/(?:[0-90-9０-９]+|丁目|番地|地割|番|号)+/u';
    private const TRAILING_SEPARATOR_PATTERN = '/[\-－ーのノ]+$/u';

    /**
     * @param string|null $cityCode 既知の字名テーブル（`AddressExceptions`）の参照に使う。
     *                              分からない場合はnullでよい（該当ケースは判定できないだけ）。
     * @return array{street: string, building: string, aza: string}
     */
    public static function split(string $text, ?string $cityCode = null): array
    {
        $text = trim($text);

        // 「字小曲コーポパッション103」のように、字名と建物名の間に数字も区切りも無く、
        // 一般ロジックでは分離できないケース。実在する字名が既知テーブルに登録されていれば、
        // それを最優先で確定させ、残りを改めてstreet/buildingに分割する。
        if ($cityCode !== null && preg_match('/^(大字|字)/u', $text, $mm) === 1) {
            $marker = $mm[1];
            $afterMarker = mb_substr($text, mb_strlen($marker));
            $knownAza = AddressExceptions::matchKnownAza($cityCode, $afterMarker);
            if ($knownAza !== null) {
                $remainder = mb_substr($afterMarker, mb_strlen($knownAza));
                $rest = self::splitRemainder($remainder);

                return ['street' => $rest['street'], 'building' => $rest['building'], 'aza' => $marker . $knownAza];
            }
        }

        if (preg_match(self::STREET_PATTERN, $text, $m) === 1) {
            $street = (string) preg_replace(self::TRAILING_SEPARATOR_PATTERN, '', $m[0]);
            $building = trim(mb_substr($text, mb_strlen($street)));

            return ['street' => $street, 'building' => $building, 'aza' => ''];
        }

        // 町名マッチで捉えきれなかった小字（例:「字北内町」）や、括弧書きの旧地名
        // （例:「（東京市小石川区久堅町91番地）」。`looksLikeObsoleteAnnotation`参照）が
        // 番地の前に残っている場合、番地部分を探して切り出す。これらはbuildingではなく、
        // 町名と番地の間に位置する情報（aza）として別枠に保持する（buildingに混ぜると
        // 「41-5字北内町」のように番地の後に地名が来る、あべこべな並びで復元されてしまうため）。
        // それ以外（純粋な建物名等）は従来通りbuildingに残す。
        $isObsoleteAnnotation = self::looksLikeObsoleteAnnotation($text);
        $startPattern = $isObsoleteAnnotation ? self::OBSOLETE_ANNOTATION_START_PATTERN : self::ANYWHERE_STREET_PATTERN;
        if ((preg_match('/^(?:大字|字)/u', $text) === 1 || $isObsoleteAnnotation)
            && preg_match($startPattern, $text, $m, PREG_OFFSET_CAPTURE) === 1
        ) {
            $offset = $m[0][1];
            // 一致箇所が「丁目」から始まる場合、その直前に丁目番号を表す漢数字（例:「二丁目」の
            // 「二」）が続いていることがある。ANYWHERE_STREET_PATTERNは単語途中の漢数字誤爆を
            // 避けるため裸の漢数字を開始トリガーに含めていないが、既に「丁目」自体が確定的な
            // トリガーとして一致した以上、その直前の漢数字は丁目番号の一部と確定でき、azaに
            // 取り込んでしまうと丁目情報が失われる（例:「磯辺通二丁目」→ azaに「二」が残り
            // 「丁目」だけが渡されて丁目番号を復元できなくなる）ため、開始位置を前にずらす。
            if (str_starts_with(substr($text, $offset), '丁目')
                && preg_match('/[一二三四五六七八九十百千]+$/u', substr($text, 0, $offset), $nm) === 1
            ) {
                $offset -= strlen($nm[0]);
            }
            $aza = substr($text, 0, $offset);
            $rest = self::splitRemainder(mb_substr($text, mb_strlen($aza)));

            return ['street' => $rest['street'], 'building' => $rest['building'], 'aza' => $aza];
        }

        return ['street' => '', 'building' => $text, 'aza' => ''];
    }

    /**
     * 字名の続き（既に区切り済みの残り文字列）を、番地部分と建物名に分割する。
     * @return array{street: string, building: string}
     */
    private static function splitRemainder(string $remainder): array
    {
        if (preg_match(self::STREET_PATTERN, $remainder, $m) === 1) {
            $street = (string) preg_replace(self::TRAILING_SEPARATOR_PATTERN, '', $m[0]);
            $building = trim(mb_substr($remainder, mb_strlen($street)));

            return ['street' => $street, 'building' => $building];
        }

        if (preg_match(self::ANYWHERE_STREET_PATTERN, $remainder, $m, PREG_OFFSET_CAPTURE) === 1) {
            $prefix = substr($remainder, 0, $m[0][1]);
            $afterPrefix = mb_substr($remainder, mb_strlen($prefix));
            // 開始位置は厳格パターンで確定させ、実際の番地の範囲はSTREET_PATTERNで
            // 改めて計算する（「の」やハイフンの続きを取りこぼさないため）。
            preg_match(self::STREET_PATTERN, $afterPrefix, $sm);
            $street = (string) preg_replace(self::TRAILING_SEPARATOR_PATTERN, '', $sm[0]);
            $building = trim($prefix . mb_substr($afterPrefix, mb_strlen($street)));

            return ['street' => $street, 'building' => $building];
        }

        return ['street' => '', 'building' => $remainder];
    }

    /**
     * $textが、括弧書きの旧地名の注記らしい形（丸括弧で始まる）かどうかを判定する。
     *
     * 「東京都文京区（東京市小石川区久堅町91番地）」のように、法人登記の住所には戦前・戦後の
     * 市区再編前の旧地名が括弧書きで残っていることがある（東京23区の前身35区の統合、大阪市・
     * 名古屋市・横浜市・札幌市・神戸市等での区の分割・統合など）。これらの旧地名は現在の
     * 参照データに存在しないため町名としてマッチせず、郵便番号を判定することはできない
     * （できたとしても、現在の郵便番号制度とは無関係な情報であり意味が無い）。
     *
     * 旧地名そのものは全国の区再編の数だけ存在し、`AddressExceptions`のように市区町村ごとに
     * 個別列挙するのは非現実的かつ際限が無い。一方、これらは共通して「町名の直後に丸括弧が
     * 来る」という書式上の強い特徴を持つ（現在実在する町名が丸括弧から始まることは無い）。
     * そのため、個別の旧地名データを持たず、この書式上の特徴だけで「旧地名の注記である」
     * ことを判定し、番地の開始位置を手がかりに旧地名部分・番地部分・建物名部分へ機械的に
     * 分割する。郵便番号や町名としての意味付けはせず、あくまで文字列としての分割
     * （`format()`で元の表記に復元できること）のみを目的とする。
     */
    private static function looksLikeObsoleteAnnotation(string $text): bool
    {
        return str_starts_with($text, '（') || str_starts_with($text, '(');
    }
}

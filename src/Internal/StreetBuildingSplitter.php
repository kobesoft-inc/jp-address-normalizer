<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

/**
 * 町名より後ろの文字列を、番地部分(street)と建物名等(building)に正規表現で分割する。
 *
 * 数字・丁目・番地・号・線・区・「の」・ハイフン類が続く限りをstreetとみなし、
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
    private const STREET_PATTERN = '/^(?:[A-Za-zＡ-Ｚａ-ｚ]+丁目|[0-90-9０-９一二三四五六七八九十百千]+|丁目|番地|地割|番|号|線|区|の|[\-－ー])+/u';
    // 先頭アンカー無しで番地の開始位置を探す際に使う、より厳格なパターン。
    // STREET_PATTERNと違い、裸の漢数字1文字（一/八/九等）や「の」を開始トリガーに含めない。
    // 「一已」「八木田」「九郎新田」「地の岡」のように、漢数字や「の」が地名の一部として
    // 単語の途中に現れることがあり、それを番地の開始と誤認識して単語を分断してしまうため
    // （例:「字一已１８６３番地」→ 誤って「字」+「一」+「已１８６３番地」に3分割される）。
    // 算用数字や「丁目」「番地」等の明確なキーワードのみを開始トリガーとする。
    private const ANYWHERE_STREET_PATTERN = '/(?:[0-90-9０-９]+|丁目|番地|地割|番|号|線|区)+/u';
    private const TRAILING_SEPARATOR_PATTERN = '/[\-－ーの]+$/u';

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

        // 町名マッチで捉えきれなかった小字（例:「字北内町」）が番地の前に残っている場合、
        // 番地部分を探して切り出す。この小字はbuildingではなく、町名と番地の間に位置する
        // 情報（aza）として別枠に保持する（buildingに混ぜると「41-5字北内町」のように
        // 番地の後に地名が来る、あべこべな並びで復元されてしまうため）。
        // それ以外（純粋な建物名等）は従来通りbuildingに残す。
        if (preg_match('/^(?:大字|字)/u', $text) === 1
            && preg_match(self::ANYWHERE_STREET_PATTERN, $text, $m, PREG_OFFSET_CAPTURE) === 1
        ) {
            $aza = substr($text, 0, $m[0][1]);
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
}

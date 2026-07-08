<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

use JpAddressNormalizer\Street;

/**
 * StreetBuildingSplitterが切り出した「番地部分」の文字列を、丁目・番地・枝番・号に分解する。
 *
 * 「地割」「線」のような特殊な表記は、数字の抽出を行わない（rawのみ保持し、構造化フィールドは
 * 全てnullのままにする）。丁目・番地の並びとして読み取れるものだけを対象にする。
 */
final class StreetParser
{
    private const CHOME_PATTERN = '/^([0-90-9０-９]+)丁目/u';
    private const ALPHA_CHOME_PATTERN = '/^([A-Za-zＡ-Ｚａ-ｚ]+)丁目/u';
    private const SPECIAL_KEYWORDS = ['地割', '線'];

    public static function parse(string $raw): Street
    {
        $trimmed = trim($raw);

        foreach (self::SPECIAL_KEYWORDS as $keyword) {
            if (mb_strpos($trimmed, $keyword) !== false) {
                return new Street($raw, null, null, null, null);
            }
        }

        // 「三十八丁目」のような漢数字表記の丁目・番地も抽出できるよう、算用数字に統一してから解析する。
        // 「三八」のように「十」を欠いた表記も、桁を並べた算用数字（38）として解釈する。
        $remaining = NumeralConverter::kanjiToArabic($trimmed);
        $chome = null;
        $chomeLabel = null;
        if (preg_match(self::CHOME_PATTERN, $remaining, $m) === 1) {
            $chome = NumeralConverter::toHalfwidthInt($m[1]);
            $remaining = mb_substr($remaining, mb_strlen($m[0]));
        } elseif (preg_match(self::ALPHA_CHOME_PATTERN, $remaining, $m) === 1) {
            // 区画整理の経緯等で「Ａ丁目」のようにアルファベットが丁目番号の代わりに
            // 使われている地域が実在するため、数字として解釈できなくても保持する。
            $chomeLabel = self::toHalfwidthUpper($m[1]);
            $remaining = mb_substr($remaining, mb_strlen($m[0]));
        }

        $numbers = self::extractNumbers($remaining);
        $hasGo = mb_strpos($remaining, '号') !== false;

        $banchi = null;
        $banchiSub = null;
        $go = null;

        // 「西新宿２－８－１」のように、丁目を示すキーワードが無くてもハイフン区切りの
        // 数字が3つ続く場合は、日本の慣用的な表記として「丁目-番-号」を意味する。
        // これを見落とすと3つ目の数字（号）が捨てられてしまうため、数字・ハイフン以外の
        // 文字を含まない場合に限りこの並びとして解釈する。
        $isPureNumericHyphen = preg_match('/^[0-90-9０-９\-－ー]+$/u', $remaining) === 1;
        if ($chome === null && $chomeLabel === null && $isPureNumericHyphen && count($numbers) === 3) {
            $chome = $numbers[0];
            $banchi = $numbers[1];
            $go = $numbers[2];
        } else {
            if (count($numbers) >= 1) {
                $banchi = $numbers[0];
            }
            if (count($numbers) >= 2) {
                if ($hasGo) {
                    $go = $numbers[1];
                } else {
                    $banchiSub = $numbers[1];
                }
            }
        }

        return new Street($raw, $chome, $banchi, $banchiSub, $go, $chomeLabel);
    }

    // 「の」は片仮名の「ノ」で書かれることもある（他の枝番表記と同様）。甲乙丙丁の他に
    // 十二支（子丑寅卯辰巳午未申酉戌亥）で順序を表す地域もある（例:「800番地ノ甲」
    // 「5990番地の卯」）。
    private const BANCHI_SUB_LABEL_PATTERN = '/^[のノ](甲|乙|丙|丁|子|丑|寅|卯|辰|巳|午|未|申|酉|戌|亥|内[0-90-9０-９一二三四五六七八九十百千ｦ-ﾟァ-ヶA-Za-zＡ-Ｚａ-ｚ]{0,4})$/u';

    /**
     * 「486番地の甲」「2505番地の内イ」のように、番地の枝番が甲乙丙等の順序表記や
     * 「内」＋記号で書かれるケースに対応する。$buildingがこの表記だけで構成されている
     * （番地の枝番以外の情報を含まない）場合に限り、$streetの$banchiSubLabelへ
     * 吸収し、$buildingを空にする。数字の枝番（$banchiSub）と異なりStreet::formatが
     * この文字列をそのまま保持できるため、buildingに残すよりも情報を失わない。
     *
     * @return array{0: Street, 1: string} [調整後のStreet, 調整後のbuilding]
     */
    public static function absorbBanchiSubLabel(Street $street, string $building): array
    {
        if ($street->banchi === null || $street->banchiSub !== null) {
            return [$street, $building];
        }

        if (preg_match(self::BANCHI_SUB_LABEL_PATTERN, $building, $m) === 1) {
            $street = new Street(
                $street->raw . $building,
                $street->chome,
                $street->banchi,
                null,
                $street->go,
                $street->chomeLabel,
                $m[1],
            );

            return [$street, ''];
        }

        return [$street, $building];
    }

    /** @return list<int> */
    private static function extractNumbers(string $text): array
    {
        preg_match_all('/[0-90-9０-９]+/u', $text, $m);
        return array_map(NumeralConverter::toHalfwidthInt(...), $m[0]);
    }

    private static function toHalfwidthUpper(string $letters): string
    {
        $halfwidth = strtr($letters, array_combine(
            mb_str_split('ＡＢＣＤＥＦＧＨＩＪＫＬＭＮＯＰＱＲＳＴＵＶＷＸＹＺａｂｃｄｅｆｇｈｉｊｋｌｍｎｏｐｑｒｓｔｕｖｗｘｙｚ'),
            str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz')
        ));
        return mb_strtoupper($halfwidth);
    }
}

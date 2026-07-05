<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

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

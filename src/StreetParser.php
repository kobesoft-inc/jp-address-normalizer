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
    private const SPECIAL_KEYWORDS = ['地割', '線'];

    public static function parse(string $raw): Street
    {
        $trimmed = trim($raw);

        foreach (self::SPECIAL_KEYWORDS as $keyword) {
            if (mb_strpos($trimmed, $keyword) !== false) {
                return new Street($raw, null, null, null, null);
            }
        }

        $remaining = $trimmed;
        $chome = null;
        if (preg_match(self::CHOME_PATTERN, $remaining, $m) === 1) {
            $chome = self::toInt($m[1]);
            $remaining = mb_substr($remaining, mb_strlen($m[0]));
        }

        $numbers = self::extractNumbers($remaining);
        $hasGo = mb_strpos($remaining, '号') !== false;

        $banchi = null;
        $banchiSub = null;
        $go = null;

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

        return new Street($raw, $chome, $banchi, $banchiSub, $go);
    }

    /** @return list<int> */
    private static function extractNumbers(string $text): array
    {
        preg_match_all('/[0-90-9０-９]+/u', $text, $m);
        return array_map(self::toInt(...), $m[0]);
    }

    private static function toInt(string $digits): int
    {
        $halfwidth = strtr($digits, array_combine(
            mb_str_split('０１２３４５６７８９'),
            str_split('0123456789')
        ));
        return (int) $halfwidth;
    }
}

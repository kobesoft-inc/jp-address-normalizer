<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

/**
 * 町名・番地に現れる漢数字(一〜九十、十一〜九十九)と全角算用数字(１〜99)を
 * 相互に変換するユーティリティ。
 */
final class NumeralConverter
{
    private const KANJI_DIGITS = [
        '壱' => 1, '弐' => 2, '参' => 3,
        '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5,
        '六' => 6, '七' => 7, '八' => 8, '九' => 9,
    ];

    private const FULLWIDTH_DIGITS = '０１２３４５６７８９';

    private static ?string $kanjiNumberPattern = null;

    private static function kanjiNumberPattern(): string
    {
        return self::$kanjiNumberPattern ??= '[一二三四五六七八九壱弐参]?十[一二三四五六七八九壱弐参]?|[一二三四五六七八九壱弐参]';
    }

    private static function kanjiTokenToInt(string $token): int
    {
        if ($token === '十') {
            return 10;
        }
        $chars = mb_str_split($token);
        if (count($chars) === 1) {
            return self::KANJI_DIGITS[$chars[0]];
        }
        if ($chars[0] === '十') {
            return 10 + self::KANJI_DIGITS[$chars[1]];
        }
        if (end($chars) === '十') {
            return self::KANJI_DIGITS[$chars[0]] * 10;
        }
        return self::KANJI_DIGITS[$chars[0]] * 10 + self::KANJI_DIGITS[$chars[2]];
    }

    private static function intToKanji(int $n): string
    {
        $arabicToKanji = array_flip(self::KANJI_DIGITS);
        if ($n < 10) {
            return $arabicToKanji[$n];
        }
        if ($n === 10) {
            return '十';
        }
        $tens = intdiv($n, 10);
        $ones = $n % 10;
        $s = ($tens === 1 ? '' : $arabicToKanji[$tens]) . '十';
        return $ones > 0 ? $s . $arabicToKanji[$ones] : $s;
    }

    /** @return array<string,string> 半角数字1文字 => 全角数字1文字 */
    private static function halfToFullMap(): array
    {
        return array_combine(str_split('0123456789'), mb_str_split(self::FULLWIDTH_DIGITS));
    }

    /** @return array<string,string> 全角数字1文字 => 半角数字1文字 */
    private static function fullToHalfMap(): array
    {
        return array_combine(mb_str_split(self::FULLWIDTH_DIGITS), str_split('0123456789'));
    }

    private static function intToFullwidth(int $n): string
    {
        return strtr((string) $n, self::halfToFullMap());
    }

    /**
     * 全角算用数字を半角に変換し、整数として返す。
     * 半角算用数字が含まれていればそのまま処理される。
     */
    public static function toHalfwidthInt(string $digits): int
    {
        $halfwidth = strtr($digits, self::fullToHalfMap());
        return (int) $halfwidth;
    }

    /** 文字列中の全角算用数字を半角に変換する（漢数字や他の文字はそのまま）。 */
    public static function toHalfwidthDigits(string $text): string
    {
        return strtr($text, self::fullToHalfMap());
    }

    /** 文字列中の漢数字(1〜99)を全角算用数字に変換する。 */
    public static function kanjiToArabic(string $text): string
    {
        return (string) preg_replace_callback(
            '/' . self::kanjiNumberPattern() . '/u',
            static fn (array $m): string => self::intToFullwidth(self::kanjiTokenToInt($m[0])),
            $text
        );
    }

    /** 文字列中の算用数字(全角/半角、1〜99)を漢数字に変換する。 */
    public static function arabicToKanji(string $text): string
    {
        return (string) preg_replace_callback(
            '/[0-9０-９]+/u',
            static function (array $m): string {
                $halfwidth = strtr($m[0], self::fullToHalfMap());
                $n = (int) $halfwidth;
                if ($n >= 1 && $n <= 99 && (string) $n === $halfwidth) {
                    return self::intToKanji($n);
                }
                return $m[0];
            },
            $text
        );
    }
}

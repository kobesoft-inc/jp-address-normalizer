<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

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
    private const STREET_PATTERN = '/^(?:[0-90-9０-９一二三四五六七八九十百千]+|丁目|番地|地割|番|号|線|区|の|[\-－ー])+/u';
    private const TRAILING_SEPARATOR_PATTERN = '/[\-－ーの]+$/u';

    /** @return array{street: string, building: string} */
    public static function split(string $text): array
    {
        $text = trim($text);
        if (preg_match(self::STREET_PATTERN, $text, $m) !== 1) {
            return ['street' => '', 'building' => $text];
        }

        $street = (string) preg_replace(self::TRAILING_SEPARATOR_PATTERN, '', $m[0]);
        $building = trim(mb_substr($text, mb_strlen($street)));

        return ['street' => $street, 'building' => $building];
    }
}

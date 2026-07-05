<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

final class AddressMatcher
{
    public function __construct(private readonly AddressNormalizer $normalizer)
    {
    }

    /**
     * 2つの住所文字列を正規化して比較し、どのレベルまで一致するかを返す。
     */
    public function compare(string $address1, string $address2): MatchLevel
    {
        $a = $this->normalizer->normalize($address1);
        $b = $this->normalizer->normalize($address2);

        return self::compareNormalized($a, $b);
    }

    /**
     * 正規化済みの2つのParsedAddressを比較する。
     */
    public static function compareNormalized(ParsedAddress $a, ParsedAddress $b): MatchLevel
    {
        // 都道府県が異なる or 片方が解決できていない
        if ($a->prefectureCode === null || $b->prefectureCode === null) {
            return MatchLevel::None;
        }
        if ($a->prefectureCode !== $b->prefectureCode) {
            return MatchLevel::None;
        }

        // 市区町村
        if ($a->cityCode === null || $b->cityCode === null) {
            return MatchLevel::Prefecture;
        }
        if ($a->cityCode !== $b->cityCode) {
            return MatchLevel::Prefecture;
        }

        // 町名
        if ($a->town === '' || $b->town === '') {
            return MatchLevel::City;
        }
        if ($a->town !== $b->town) {
            return MatchLevel::City;
        }

        // 丁目
        if ($a->street->chome !== $b->street->chome) {
            // chomeLabel(アルファベット丁目)も比較
            if ($a->street->chomeLabel !== $b->street->chomeLabel) {
                return MatchLevel::Town;
            }
            if ($a->street->chome !== null && $b->street->chome !== null) {
                return MatchLevel::Town;
            }
        }

        // 番地
        if ($a->street->banchi !== $b->street->banchi) {
            if ($a->street->banchi !== null && $b->street->banchi !== null) {
                return MatchLevel::Chome;
            }
            // 片方がnullの場合、丁目までは一致
            return MatchLevel::Chome;
        }

        // 枝番
        if ($a->street->banchiSub !== $b->street->banchiSub) {
            if ($a->street->banchiSub !== null && $b->street->banchiSub !== null) {
                return MatchLevel::Banchi;
            }
        }

        // 号
        if ($a->street->go !== $b->street->go) {
            if ($a->street->go !== null && $b->street->go !== null) {
                return MatchLevel::Banchi;
            }
        }

        // ここまで来たら番地レベルまでは一致
        // 建物名の比較
        $buildingA = trim($a->building);
        $buildingB = trim($b->building);

        if ($buildingA === '' && $buildingB === '') {
            return MatchLevel::Exact;
        }
        if ($buildingA === $buildingB) {
            return MatchLevel::Exact;
        }

        // 建物名が異なる場合
        // 号(go)レベルまで一致していれば Go、そうでなければ Banchi
        if ($a->street->go !== null && $b->street->go !== null && $a->street->go === $b->street->go) {
            return MatchLevel::Go;
        }
        if ($a->street->banchiSub !== null && $b->street->banchiSub !== null && $a->street->banchiSub === $b->street->banchiSub) {
            return MatchLevel::Go;
        }

        return MatchLevel::Banchi;
    }
}

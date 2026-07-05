<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 町名・番地から、郵便番号をベストエフォートで逆引きする。
 *
 * ある町名が1つの郵便番号にしか対応しない場合（大半のケース）は、それをそのまま返す。
 * 複数の郵便番号に分かれる場合は、丁目番号がtown_details.chome_from/chome_toの範囲に
 * 一意に収まればそれを、そうでなければtown_details.detailの生テキストが住所文字列に
 * 含まれているかで絞り込みを試みる。それでも一意に絞り込めない場合は候補を複数返す。
 */
final class PostalCodeResolver
{
    public function __construct(private readonly PostalCodeRepository $repository)
    {
    }

    /** @return array{postalCode: ?string, candidates: list<string>} */
    public function resolve(string $cityCode, string $town, Street $street, string $remainingText): array
    {
        $postalCodes = $this->repository->postalCodesForTown($cityCode, $town);
        if (count($postalCodes) === 0) {
            return ['postalCode' => null, 'candidates' => []];
        }
        if (count($postalCodes) === 1) {
            return ['postalCode' => $postalCodes[0], 'candidates' => []];
        }

        $details = $this->repository->townDetails($cityCode, $town);

        if ($street->chome !== null) {
            $byChome = array_values(array_filter(
                $details,
                static fn (array $d): bool => $d['chome_from'] !== null
                    && $d['chome_from'] <= $street->chome
                    && $street->chome <= $d['chome_to']
            ));
            if (count($byChome) === 1) {
                return ['postalCode' => $byChome[0]['postal_code'], 'candidates' => []];
            }
        }

        // detailは複数の判別情報を「、」区切りで連結していることがあるため(例: 京都の通り名を
        // 列挙したもの)、項目ごとに分けてhaystackに含まれるか照合する。
        $haystack = $street->raw . $remainingText;
        $byText = array_values(array_filter(
            $details,
            static function (array $d) use ($haystack): bool {
                if ($d['detail'] === '') {
                    return false;
                }
                foreach (explode('、', $d['detail']) as $part) {
                    if ($part !== '' && mb_strpos($haystack, $part) !== false) {
                        return true;
                    }
                }
                return false;
            }
        ));
        if (count($byText) === 1) {
            return ['postalCode' => $byText[0]['postal_code'], 'candidates' => []];
        }

        return ['postalCode' => null, 'candidates' => $postalCodes];
    }
}

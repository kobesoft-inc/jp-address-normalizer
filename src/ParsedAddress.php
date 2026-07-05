<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 住所文字列を分割した結果。解決できなかった項目はnullまたは空文字列になる。
 *
 * postalCodeは、入力文字列に郵便番号が含まれていた場合はそれを、含まれていない場合は
 * town・streetから逆引きで一意に確定できた場合にその値を格納する（ある程度のベストエフォート）。
 * 複数の郵便番号の候補があり一意に絞り込めない場合は、postalCodeはnullのまま
 * postalCodeCandidatesに候補が入る。
 */
final class ParsedAddress
{
    /** @param list<string> $postalCodeCandidates */
    public function __construct(
        public readonly ?string $postalCode,
        public readonly ?string $prefectureCode,
        public readonly ?string $cityCode,
        public readonly string $town,
        public readonly Street $street,
        public readonly string $building,
        public readonly array $postalCodeCandidates = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'postal_code_candidates' => $this->postalCodeCandidates,
            'prefecture_code' => $this->prefectureCode,
            'city_code' => $this->cityCode,
            'town' => $this->town,
            'street' => $this->street->toArray(),
            'building' => $this->building,
        ];
    }
}

<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 住所文字列を分割した結果。解決できなかった項目はnullまたは空文字列になる。
 */
final class ParsedAddress
{
    public function __construct(
        public readonly ?string $postalCode,
        public readonly ?string $prefectureCode,
        public readonly ?string $cityCode,
        public readonly string $town,
        public readonly string $street,
        public readonly string $building,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'prefecture_code' => $this->prefectureCode,
            'city_code' => $this->cityCode,
            'town' => $this->town,
            'street' => $this->street,
            'building' => $this->building,
        ];
    }
}

<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

final class ParsedAddress
{
    public function __construct(
        public readonly ?string $postalCode,
        public readonly ?string $prefectureCode,
        public readonly ?string $cityCode,
        public readonly string $town,
        public readonly Street $street,
        public readonly string $building,
        public readonly ?string $prefectureName = null,
        public readonly ?string $cityName = null,
        public readonly ?UnresolvedReason $unresolvedReason = null,
    ) {
    }

    public function format(): string
    {
        return ($this->prefectureName ?? '')
            . ($this->cityName ?? '')
            . $this->town
            . $this->street->format()
            . $this->building;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'prefecture_code' => $this->prefectureCode,
            'prefecture_name' => $this->prefectureName,
            'city_code' => $this->cityCode,
            'city_name' => $this->cityName,
            'town' => $this->town,
            'street' => $this->street->toArray(),
            'building' => $this->building,
            'unresolved_reason' => $this->unresolvedReason?->value,
            'formatted' => $this->format(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 住所解析結果。
 *
 * `town`はDB（jp-postal-code-db）上の正式表記（例:「南７線西」のように算用数字が
 * 正式な町名も、変換せずそのまま保持する）。入力側で実際にどう書かれていたかを
 * 知りたい場合は`townRaw`（表記ゆれ吸収・正規化前の元の文字列）を参照する。
 * 住所全体の元の入力文字列は`raw`にそのまま保持している。
 *
 * `aza`は、町名より後・番地より前に位置する「字北内町」のような小字名で、
 * 参照データ上の独立した町名としては見つからなかったものを保持する
 * （建物名（`building`）とは異なり、番地の前に位置する情報のため区別している）。
 */
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
        public readonly ?string $kyotoStreet = null,
        public readonly string $raw = '',
        public readonly ?string $townRaw = null,
        public readonly string $aza = '',
    ) {
    }

    /**
     * 正規化済みの各パーツを組み立て直した文字列。
     * 表記ゆれを吸収した結果であり、元の入力文字列そのものではない
     * （元の入力が欲しい場合は`raw`を使う）。
     */
    public function format(): string
    {
        return ($this->prefectureName ?? '')
            . ($this->cityName ?? '')
            . ($this->kyotoStreet ?? '')
            . $this->town
            . $this->aza
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
            'town_raw' => $this->townRaw,
            'aza' => $this->aza,
            'street' => $this->street->toArray(),
            'building' => $this->building,
            'kyoto_street' => $this->kyotoStreet,
            'unresolved_reason' => $this->unresolvedReason?->value,
            'raw' => $this->raw,
            'formatted' => $this->format(),
        ];
    }
}

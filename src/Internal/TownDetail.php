<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

/**
 * 郵便番号とdetail文字列のペアを表す値オブジェクト。
 * detail文字列をParsedDetailにパースし、マッチ判定を委譲する。
 */
final class TownDetail
{
    private ?ParsedDetail $parsed = null;

    public function __construct(
        public readonly string $postalCode,
        public readonly string $detail,
    ) {
    }

    public function parsed(): ParsedDetail
    {
        return $this->parsed ??= ParsedDetail::parse($this->detail);
    }

    public function isCatchAll(): bool
    {
        return $this->parsed()->isCatchAll();
    }

    public function hasChomeRange(): bool
    {
        return $this->parsed()->discriminatesByChome();
    }

    public function isChomeExistenceOnly(): bool
    {
        return $this->parsed()->isChomeExistenceOnly();
    }

    public function hasChomeAbsence(): bool
    {
        return $this->parsed()->hasChomeAbsence();
    }

    public function matchesChome(int $chome): bool
    {
        return $this->parsed()->matchesChome($chome);
    }

    public function matchesPureChome(int $chome): bool
    {
        return $this->parsed()->matchesPureChome($chome);
    }

    public function describesBanchi(): bool
    {
        return $this->parsed()->discriminatesByBanchi();
    }

    public function describesPureBanchi(): bool
    {
        return $this->parsed()->discriminatesByPureBanchi();
    }

    /** @return bool|null */
    public function evaluateBanchi(int $banchi, ?int $banchiSub): ?bool
    {
        return $this->parsed()->evaluateBanchi($banchi, $banchiSub);
    }

    public function hasChomeBanchi(): bool
    {
        return $this->parsed()->hasChomeBanchi();
    }

    /** @return bool|null */
    public function evaluateChomeBanchi(int $chome, int $banchi, ?int $banchiSub): ?bool
    {
        return $this->parsed()->evaluateChomeBanchi($chome, $banchi, $banchiSub);
    }

    public function hasChomeBanchiGo(): bool
    {
        return $this->parsed()->hasChomeBanchiGo();
    }

    public function evaluateChomeBanchiGo(int $chome, int $banchi, ?int $go): bool
    {
        return $this->parsed()->evaluateChomeBanchiGo($chome, $banchi, $go);
    }

    public function describesFloor(): bool
    {
        return $this->parsed()->discriminatesByFloor();
    }

    public function floorNumber(): ?int
    {
        return $this->parsed()->floorNumber();
    }

    public function isUnknownFloor(): bool
    {
        return $this->parsed()->isUnknownFloor();
    }

    public function matchesText(string $haystack): bool
    {
        return $this->parsed()->matchesText($haystack);
    }

    public function isConfidentlyExcludableByTextAbsence(): bool
    {
        return $this->parsed()->isConfidentlyExcludableByTextAbsence();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $items = $this->parsed()->items;
        $patternValue = count($items) > 0 ? $items[0]->pattern->value : null;

        return [
            'postal_code' => $this->postalCode,
            'detail' => $this->detail,
            'pattern' => $patternValue,
        ];
    }
}

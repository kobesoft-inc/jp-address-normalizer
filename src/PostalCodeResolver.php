<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 町名・番地から、郵便番号をベストエフォートで逆引きする。
 *
 * ある町名が1つの郵便番号にしか対応しない場合（大半のケース）は、それをそのまま返す。
 * 複数の郵便番号に分かれる場合は、次の順で絞り込みを試みる。
 *
 * 1. detailが「１〜１９丁目」のような丁目範囲として読み取れ、丁目番号が一意に収まるか。
 * 2. detailが「１〜１３１番地」「２７９４−１２番地」「６７−４〜１１３−７番地」の
 *    ような番地の範囲・列挙として読み取れ、番地（枝番を含む）がそこに一意に収まるか
 *    （北海道等、丁目を持たず番地だけで区域が分かれる地域向け）。範囲の境界が
 *    枝番付きで、住所側の枝番が不明な場合は、含まれるとも除外できるとも判定しない。
 * 3. 階数パターン（高層ビルの郵便番号は階ごとに分かれる）。
 * 4. detailの生テキスト（地区名や京都の通り名等）が住所文字列に含まれているか。
 * 5. 「その他」（またはdetailが空文字列）は、KEN_ALLデータ上で他の条件に当てはまらない
 *    住所の受け皿として使われる表記。他の候補全てが丁目・番地の条件から明確に
 *    該当しないと判定できる場合に限り、消去法でこの受け皿を採用する。
 *
 * 確定できなかった場合は、なぜ確定できなかったかの理由コードを返す。
 */
final class PostalCodeResolver
{
    public function __construct(private readonly PostalCodeRepository $repository)
    {
    }

    public function resolve(string $cityCode, string $town, Street $street, string $remainingText): PostalCodeResolveResult
    {
        $postalCodes = $this->repository->postalCodesForTown($cityCode, $town);
        if (count($postalCodes) === 0) {
            return PostalCodeResolveResult::unresolved(UnresolvedReason::TownNotFound);
        }
        if (count($postalCodes) === 1) {
            return PostalCodeResolveResult::resolved($postalCodes[0]);
        }

        $details = $this->repository->details($cityCode, $town);

        // 丁目+番地(+号)の複合条件（ChomeBanchi / ChomeBanchiGo）で絞り込み
        $hasChomeBanchi = false;
        foreach ($details as $d) {
            if ($d->hasChomeBanchi() || $d->hasChomeBanchiGo()) {
                $hasChomeBanchi = true;
                break;
            }
        }
        if ($hasChomeBanchi && $street->chome !== null && $street->banchi !== null) {
            $match = $this->findUniqueMatch(
                $details,
                static function (TownDetail $d) use ($street): bool {
                    if ($d->hasChomeBanchiGo()) {
                        return $d->evaluateChomeBanchiGo($street->chome, $street->banchi, $street->go) === true;
                    }
                    if ($d->hasChomeBanchi()) {
                        return $d->evaluateChomeBanchi($street->chome, $street->banchi, $street->banchiSub) === true;
                    }
                    return $d->hasChomeRange() && $d->matchesPureChome($street->chome);
                }
            );
            if ($match !== null) {
                return PostalCodeResolveResult::resolved($match->postalCode);
            }
        }

        // 丁目で絞り込み
        $hasChomeDetails = false;
        foreach ($details as $d) {
            if ($d->hasChomeRange()) {
                $hasChomeDetails = true;
                break;
            }
        }
        if ($hasChomeDetails) {
            if ($street->chome !== null) {
                // ChomeBanchi（丁目+番地の複合条件）は上のブロックで既に判定済みのため、
                // ここでは丁目のみで判定できるChomeRange/ChomeExistenceだけを対象にする。
                $match = $this->findUniqueMatch(
                    $details,
                    static fn (TownDetail $d): bool => $d->matchesPureChome($street->chome)
                );
                if ($match !== null) {
                    return PostalCodeResolveResult::resolved($match->postalCode);
                }
            }
        }

        // 番地で絞り込み（丁目+番地の複合条件＝ChomeBanchiは対象外。既に上のブロックで判定済みであり、
        // evaluateBanchi()は丁目を考慮できず誤って「判定不能」を返してしまうため）
        $hasBanchiDetails = false;
        foreach ($details as $d) {
            if ($d->describesPureBanchi()) {
                $hasBanchiDetails = true;
                break;
            }
        }
        if ($hasBanchiDetails && $street->banchi !== null) {
            $banchiDetails = array_values(array_filter(
                $details,
                static fn (TownDetail $d): bool => $d->describesPureBanchi()
            ));
            $byBanchi = array_values(array_filter(
                $banchiDetails,
                static fn (TownDetail $d): bool => $d->evaluateBanchi($street->banchi, $street->banchiSub) === true
            ));
            if (count($byBanchi) === 1) {
                return PostalCodeResolveResult::resolved($byBanchi[0]->postalCode);
            }
            // 枝番が不明なために確定できないケースを検出
            $uncertain = array_values(array_filter(
                $banchiDetails,
                static fn (TownDetail $d): bool => $d->evaluateBanchi($street->banchi, $street->banchiSub) === null
            ));
            if (count($uncertain) > 0 && count($byBanchi) === 0) {
                return PostalCodeResolveResult::unresolved(UnresolvedReason::SubBanchiUnknown);
            }
        }

        // 階数パターン（高層ビルの郵便番号）
        if ($this->hasFloorDetails($details)) {
            $floor = $this->extractFloor($street->raw . $remainingText);
            if ($floor !== null) {
                $match = $this->findUniqueMatch(
                    $details,
                    static fn (TownDetail $d): bool => $d->floorNumber() === $floor
                );
                if ($match !== null) {
                    return PostalCodeResolveResult::resolved($match->postalCode);
                }
            }
            // 階数が特定できない場合は「地階・階層不明」を採用
            $match = $this->findUniqueMatch(
                $details,
                static fn (TownDetail $d): bool => $d->isUnknownFloor()
            );
            if ($match !== null) {
                return PostalCodeResolveResult::resolved($match->postalCode);
            }
        }

        // テキストマッチ（複数マッチ時は最長キーワード一致を優先）
        $haystack = $street->raw . $remainingText;
        $match = $this->findBestTextMatch($details, $haystack);
        if ($match !== null) {
            return PostalCodeResolveResult::resolved($match->postalCode);
        }

        // catch-all（「その他」）への消去法
        $catchAll = array_values(array_filter(
            $details,
            static fn (TownDetail $d): bool => $d->isCatchAll()
        ));
        if (count($catchAll) === 1) {
            $others = array_values(array_filter($details, static fn (TownDetail $d): bool => $d !== $catchAll[0]));
            if (count($others) > 0 && self::allDefinitelyExcluded($others, $street, $haystack)) {
                return PostalCodeResolveResult::resolved($catchAll[0]->postalCode);
            }
        }

        // 最終的に絞り込めなかった理由を判定
        $reason = $this->diagnoseFailure($details, $street, $hasChomeDetails, $hasBanchiDetails);
        return PostalCodeResolveResult::unresolved($reason);
    }

    /**
     * @param list<TownDetail> $details
     */
    private function diagnoseFailure(array $details, Street $street, bool $hasChomeDetails, bool $hasBanchiDetails): UnresolvedReason
    {
        if ($hasChomeDetails && $street->chome === null) {
            return UnresolvedReason::ChomeUnknown;
        }
        if ($hasBanchiDetails && $street->banchi === null) {
            return UnresolvedReason::BanchiUnknown;
        }
        $hasTextOnlyDetails = false;
        foreach ($details as $d) {
            if (!$d->isCatchAll() && !$d->hasChomeRange() && !$d->describesBanchi() && !$d->describesFloor()) {
                $hasTextOnlyDetails = true;
                break;
            }
        }
        if ($hasTextOnlyDetails) {
            return UnresolvedReason::DistrictUnmatched;
        }
        return UnresolvedReason::Ambiguous;
    }

    /** @param list<TownDetail> $details */
    private function hasFloorDetails(array $details): bool
    {
        foreach ($details as $d) {
            if ($d->describesFloor()) {
                return true;
            }
        }
        return false;
    }

    private function extractFloor(string $text): ?int
    {
        if (preg_match('/([0-90-9０-９]+)\s*[fFＦ階]/u', $text, $m) === 1) {
            return NumeralConverter::toHalfwidthInt($m[1]);
        }
        return null;
    }

    /**
     * @param list<TownDetail> $details
     * @param callable(TownDetail): bool $predicate
     */
    private function findUniqueMatch(array $details, callable $predicate): ?TownDetail
    {
        $matches = array_values(array_filter($details, $predicate));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * テキストマッチで複数のdetailがマッチした場合、最長キーワード一致を優先する。
     * 「二俣川向」と「二俣」が両方マッチする場合、「二俣川向」を採用。
     *
     * @param list<TownDetail> $details
     */
    private function findBestTextMatch(array $details, string $haystack): ?TownDetail
    {
        $matches = [];
        foreach ($details as $d) {
            $len = $d->parsed()->longestMatchLength($haystack);
            if ($len > 0) {
                $matches[] = [$d, $len];
            }
        }
        if (count($matches) === 0) {
            return null;
        }
        if (count($matches) === 1) {
            return $matches[0][0];
        }
        // 最長マッチを選択
        usort($matches, static fn ($a, $b) => $b[1] - $a[1]);
        if ($matches[0][1] > $matches[1][1]) {
            return $matches[0][0];
        }
        // 同点なら確定できない
        return null;
    }

    /**
     * @param list<TownDetail> $others
     */
    private static function allDefinitelyExcluded(array $others, Street $street, string $haystack): bool
    {
        foreach ($others as $d) {
            if ($d->hasChomeBanchiGo()) {
                // 丁目+番+号の複合条件は、丁目・番地の両方が分かっている場合のみ
                // 正しく除外判定できる（evaluateChomeBanchiGo()が両方を必要とするため）。
                if ($street->chome === null || $street->banchi === null) {
                    return false;
                }
                if ($d->evaluateChomeBanchiGo($street->chome, $street->banchi, $street->go) !== false) {
                    return false;
                }
                continue;
            }
            if ($d->hasChomeBanchi()) {
                // 丁目+番地の複合条件は、丁目・番地の両方が分かっている場合のみ
                // 正しく除外判定できる（evaluateChomeBanchi()が両方を必要とするため）。
                if ($street->chome === null || $street->banchi === null) {
                    return false;
                }
                if ($d->evaluateChomeBanchi($street->chome, $street->banchi, $street->banchiSub) !== false) {
                    return false;
                }
                continue;
            }
            if ($d->hasChomeRange()) {
                if ($street->chome === null) {
                    // 「丁目」（丁目の有無のみが条件）の場合、丁目が無いことは
                    // 「該当しない」と確定できる（何丁目かが不明なのとは異なる）。
                    if ($d->isChomeExistenceOnly()) {
                        continue;
                    }
                    return false;
                }
                if ($d->matchesChome($street->chome)) {
                    return false;
                }
                continue;
            }
            if ($d->describesBanchi()) {
                if ($street->banchi === null) {
                    return false;
                }
                if ($d->evaluateBanchi($street->banchi, $street->banchiSub) !== false) {
                    return false;
                }
                continue;
            }
            // 階数条件は上のブロックで既に判定済みのはずで、ここでは対象外
            // （matchesText()は階数を理解できないため、誤って除外扱いにしないよう保守的に扱う）。
            if ($d->describesFloor()) {
                return false;
            }
            // 残りは地区名・建物名等のテキスト条件（「大字」「○○ビル」等）。
            // ある程度長さのある固有名詞的キーワードであれば、住所に一切含まれていない
            // ことをもって該当しないと確定できる（「南」等の短い方角語は自由記述の
            // 省略・言い換えの可能性があるため対象外）。
            if ($d->isConfidentlyExcludableByTextAbsence() && !$d->matchesText($haystack)) {
                continue;
            }
            return false;
        }
        return true;
    }
}

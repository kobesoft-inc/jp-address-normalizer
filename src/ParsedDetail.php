<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * detailテキストをパースした結果。1つのdetailは複数のパターン要素(ParsedDetailItem)のOR条件。
 * 例: 「１〜３丁目、白滝Ｂ・Ｃ、高見」→ [ChomeRange(1-3), Text(白滝Ｂ・Ｃ), Text(高見)]
 *
 * いずれかの要素にマッチすれば、このdetail全体がマッチしたことになる。
 */
final class ParsedDetail
{
    private const CATCH_ALL_DETAILS = ['', 'その他'];

    /** @var list<ParsedDetailItem> */
    public readonly array $items;

    /** @param list<ParsedDetailItem> $items */
    private function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function parse(string $detail): self
    {
        // CatchAll
        if (in_array($detail, self::CATCH_ALL_DETAILS, true)) {
            return new self([new ParsedDetailItem(DetailPattern::CatchAll, [])]);
        }

        // Floor (単一パターンとして特別扱い)
        if ($detail === '地階・階層不明') {
            return new self([new ParsedDetailItem(DetailPattern::Floor, ['floor' => null])]);
        }
        if (preg_match('/^([０-９0-9]+)階$/u', $detail, $m) === 1) {
            return new self([new ParsedDetailItem(DetailPattern::Floor, ['floor' => NumeralConverter::toHalfwidthInt($m[1])])]);
        }

        // 純粋な丁目範囲 (「、」区切りが全て数字丁目)
        if (preg_match('/^[０-９0-9〜～、]+丁目$/u', $detail) === 1) {
            $ranges = self::parseChomeRanges($detail);
            return new self([new ParsedDetailItem(DetailPattern::ChomeRange, ['ranges' => $ranges])]);
        }

        // ChomeExistence
        if ($detail === '丁目') {
            return new self([new ParsedDetailItem(DetailPattern::ChomeExistence, [])]);
        }

        // BanchiBound
        if (preg_match('/^([０-９0-9]+)番地?以(上|降|下)$/u', $detail, $m) === 1) {
            return new self([new ParsedDetailItem(DetailPattern::BanchiBound, [
                'boundary' => NumeralConverter::toHalfwidthInt($m[1]),
                'direction' => $m[2] === '下' ? 'below' : 'above',
            ])]);
        }

        // 「N〜」（N番地以上の別記法）
        if (preg_match('/^([０-９0-9]+)[〜～]$/u', $detail, $m) === 1) {
            return new self([new ParsedDetailItem(DetailPattern::BanchiBound, [
                'boundary' => NumeralConverter::toHalfwidthInt($m[1]),
                'direction' => 'above',
            ])]);
        }

        // 純粋なBanchiRange
        if (preg_match('/番地$/u', $detail) === 1) {
            $segments = self::parseBanchiSegments($detail);
            if ($segments !== null) {
                return new self([new ParsedDetailItem(DetailPattern::BanchiRange, ['segments' => $segments])]);
            }
        }

        // 一般: 「、」区切りの各パートを個別にパースし、アイテムの配列にする
        return new self(self::parseItems($detail));
    }

    // ========================================================================
    // マッチ判定（いずれかのitemにマッチすればtrue）
    // ========================================================================

    public function isCatchAll(): bool
    {
        return $this->hasItemOfType(DetailPattern::CatchAll);
    }

    public function discriminatesByChome(): bool
    {
        return $this->hasItemOfType(DetailPattern::ChomeRange, DetailPattern::ChomeExistence, DetailPattern::ChomeBanchi);
    }

    /**
     * detailが「丁目」（丁目の有無のみを条件とする）かどうか。
     * この場合、住所に丁目が無い（chome===null）ことが「該当しない」ことの明確な根拠になる
     * （ChomeRangeの場合と異なり、chomeが不明なのではなく「無い」と確定しているため）。
     */
    public function isChomeExistenceOnly(): bool
    {
        return $this->hasItemOfType(DetailPattern::ChomeExistence)
            && !$this->hasItemOfType(DetailPattern::ChomeRange, DetailPattern::ChomeBanchi);
    }

    public function matchesChome(int $chome): bool
    {
        foreach ($this->items as $item) {
            if ($item->pattern === DetailPattern::ChomeRange) {
                foreach ($item->args['ranges'] as $r) {
                    if ($r['from'] <= $chome && $chome <= $r['to']) {
                        return true;
                    }
                }
            } elseif ($item->pattern === DetailPattern::ChomeBanchi) {
                foreach ($item->args['chome'] as $r) {
                    if ($r['from'] <= $chome && $chome <= $r['to']) {
                        return true;
                    }
                }
            } elseif ($item->pattern === DetailPattern::ChomeExistence) {
                return true;
            }
        }
        return false;
    }

    /**
     * 丁目のみで一致判定できるか（ChomeBanchiの丁目部分は含めない）。
     * ChomeBanchi（例:「4丁目1〜14番」）は丁目が一致しても番地条件を満たすとは限らないため、
     * 丁目だけで一致とみなしてはいけない（evaluateChomeBanchi()で番地も含めて判定する）。
     */
    public function matchesPureChome(int $chome): bool
    {
        foreach ($this->items as $item) {
            if ($item->pattern === DetailPattern::ChomeRange) {
                foreach ($item->args['ranges'] as $r) {
                    if ($r['from'] <= $chome && $chome <= $r['to']) {
                        return true;
                    }
                }
            } elseif ($item->pattern === DetailPattern::ChomeExistence) {
                return true;
            }
        }
        return false;
    }

    public function discriminatesByBanchi(): bool
    {
        return $this->hasItemOfType(DetailPattern::BanchiRange, DetailPattern::BanchiBound, DetailPattern::ChomeBanchi);
    }

    /**
     * 丁目に依らない、純粋な番地のみによる絞り込み条件を持つか。
     * ChomeBanchi（丁目+番地の複合条件）はここには含めない。
     * evaluateBanchi()は丁目を考慮できないため、ChomeBanchiを含む詳細に対して
     * evaluateBanchi()を呼ぶと常に「判定不能」を返してしまう
     * （実際にはevaluateChomeBanchi()で丁目も含めて判定済みのため、二重に扱うと誤判定になる）。
     */
    public function discriminatesByPureBanchi(): bool
    {
        return $this->hasItemOfType(DetailPattern::BanchiRange, DetailPattern::BanchiBound);
    }

    public function hasChomeBanchi(): bool
    {
        return $this->hasItemOfType(DetailPattern::ChomeBanchi);
    }

    /**
     * 丁目+番地の複合条件を同時に評価する。
     * @return bool|null true=マッチ, false=除外, null=判定不能
     */
    public function evaluateChomeBanchi(int $chome, int $banchi, ?int $banchiSub): ?bool
    {
        foreach ($this->items as $item) {
            if ($item->pattern !== DetailPattern::ChomeBanchi) {
                continue;
            }
            $chomeMatch = false;
            foreach ($item->args['chome'] as $r) {
                if ($r['from'] <= $chome && $chome <= $r['to']) {
                    $chomeMatch = true;
                    break;
                }
            }
            if (!$chomeMatch) {
                continue;
            }
            $b = $item->args['banchi'];
            if ($b['type'] === 'bound') {
                $result = $b['direction'] === 'above'
                    ? $banchi >= $b['boundary']
                    : $banchi <= $b['boundary'];
                if ($result) {
                    return true;
                }
            } elseif ($b['type'] === 'range') {
                if ($banchi >= $b['from'] && $banchi <= $b['to']) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return bool|null */
    public function evaluateBanchi(int $banchi, ?int $banchiSub): ?bool
    {
        $uncertain = false;
        foreach ($this->items as $item) {
            if ($item->pattern === DetailPattern::BanchiBound) {
                $result = $item->args['direction'] === 'above'
                    ? $banchi >= $item->args['boundary']
                    : $banchi <= $item->args['boundary'];
                if ($result) {
                    return true;
                }
            } elseif ($item->pattern === DetailPattern::BanchiRange) {
                foreach ($item->args['segments'] as $seg) {
                    $verdict = self::evaluateSegment($seg, $banchi, $banchiSub);
                    if ($verdict === true) {
                        return true;
                    }
                    if ($verdict === null) {
                        $uncertain = true;
                    }
                }
            }
        }
        if ($uncertain) {
            return null;
        }
        return $this->hasItemOfType(DetailPattern::BanchiRange, DetailPattern::BanchiBound) ? false : null;
    }

    public function discriminatesByFloor(): bool
    {
        return $this->hasItemOfType(DetailPattern::Floor);
    }

    public function floorNumber(): ?int
    {
        foreach ($this->items as $item) {
            if ($item->pattern === DetailPattern::Floor) {
                return $item->args['floor'];
            }
        }
        return null;
    }

    public function isUnknownFloor(): bool
    {
        foreach ($this->items as $item) {
            if ($item->pattern === DetailPattern::Floor && $item->args['floor'] === null) {
                return true;
            }
        }
        return false;
    }

    public function matchesText(string $haystack): bool
    {
        return $this->longestMatchLength($haystack) > 0;
    }

    /**
     * haystackに含まれるキーワードのうち最長のものの文字数を返す。マッチしなければ0。
     */
    public function longestMatchLength(string $haystack): int
    {
        $longest = 0;
        foreach ($this->items as $item) {
            if ($item->pattern === DetailPattern::Text) {
                $keyword = $item->args['keyword'];
                if (mb_strpos($haystack, $keyword) !== false) {
                    $longest = max($longest, mb_strlen($keyword));
                }
                foreach (self::expandSuffixList($keyword) as $expanded) {
                    if (mb_strpos($haystack, $expanded) !== false) {
                        $longest = max($longest, mb_strlen($expanded));
                    }
                }
            } elseif ($item->pattern === DetailPattern::ChomeExistence) {
                if (mb_strpos($haystack, '丁目') !== false) {
                    $longest = max($longest, 2);
                }
            }
        }
        return $longest;
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    private function hasItemOfType(DetailPattern ...$patterns): bool
    {
        foreach ($this->items as $item) {
            foreach ($patterns as $pattern) {
                if ($item->pattern === $pattern) {
                    return true;
                }
            }
        }
        return false;
    }

    // ========================================================================
    // パート別パース
    // ========================================================================

    /** @return list<ParsedDetailItem> */
    private static function parseItems(string $detail): array
    {
        $parts = self::splitByComma($detail);
        $items = [];
        // 先読み: 数字のみのパートの直後に「N丁目」パートが続く場合、
        // 「１、２丁目」のような省略表記とみなし丁目範囲に統合する。
        $pendingChomeNumbers = [];
        for ($i = 0; $i < count($parts); $i++) {
            $part = trim($parts[$i]);
            if ($part === '') {
                continue;
            }
            // 数字のみのパートは丁目省略の可能性があるのでバッファに貯める
            if (preg_match('/^[０-９0-9]+$/u', $part) === 1) {
                // 後ろに丁目を含むパートがあるかチェック
                $isChomePrefix = false;
                for ($j = $i + 1; $j < count($parts); $j++) {
                    $next = trim($parts[$j]);
                    if (preg_match('/^[０-９0-9]+丁目/u', $next) === 1 || preg_match('/^[０-９0-9]+$/u', $next) === 1) {
                        if (preg_match('/丁目/u', $next) === 1) {
                            $isChomePrefix = true;
                        }
                        continue;
                    }
                    break;
                }
                if ($isChomePrefix) {
                    $pendingChomeNumbers[] = NumeralConverter::toHalfwidthInt($part);
                    continue;
                }
            }
            // 丁目パートに到達したら保留中の数字を丁目範囲に統合
            if (!empty($pendingChomeNumbers) && preg_match('/^[０-９0-9]+丁目/u', $part) === 1) {
                $parsed = self::parseOnePart($part);
                if ($parsed->pattern === DetailPattern::ChomeRange) {
                    $ranges = $parsed->args['ranges'];
                    foreach ($pendingChomeNumbers as $n) {
                        $ranges[] = ['from' => $n, 'to' => $n];
                    }
                    $items[] = new ParsedDetailItem(DetailPattern::ChomeRange, ['ranges' => $ranges]);
                } elseif ($parsed->pattern === DetailPattern::ChomeBanchi) {
                    $chome = $parsed->args['chome'];
                    foreach ($pendingChomeNumbers as $n) {
                        $chome[] = ['from' => $n, 'to' => $n];
                    }
                    $items[] = new ParsedDetailItem(DetailPattern::ChomeBanchi, [
                        'chome' => $chome,
                        'banchi' => $parsed->args['banchi'],
                    ]);
                } else {
                    // フォールバック: 保留をそれぞれ独立パースして追加
                    foreach ($pendingChomeNumbers as $n) {
                        $items[] = new ParsedDetailItem(DetailPattern::ChomeRange, ['ranges' => [['from' => $n, 'to' => $n]]]);
                    }
                    $items[] = $parsed;
                }
                $pendingChomeNumbers = [];
                continue;
            }
            // 保留中のものがあるが丁目パートではなかった → 独立パース
            foreach ($pendingChomeNumbers as $n) {
                $items[] = self::parseOnePart((string)$n);
            }
            $pendingChomeNumbers = [];
            $items[] = self::parseOnePart($part);
        }
        // 末尾に保留が残った場合
        foreach ($pendingChomeNumbers as $n) {
            $items[] = self::parseOnePart((string)$n);
        }
        return $items ?: [new ParsedDetailItem(DetailPattern::Text, ['keyword' => $detail])];
    }

    private static function parseOnePart(string $part): ParsedDetailItem
    {
        // 階数パターン: 「N階」「地階・階層不明」
        if ($part === '地階・階層不明') {
            return new ParsedDetailItem(DetailPattern::Floor, ['floor' => null]);
        }
        if (preg_match('/^([０-９0-9]+)階$/u', $part, $m) === 1) {
            return new ParsedDetailItem(DetailPattern::Floor, ['floor' => NumeralConverter::toHalfwidthInt($m[1])]);
        }

        // 丁目範囲: 「N丁目」「N〜M丁目」
        if (preg_match('/^([０-９0-9〜～]+)丁目(.*)$/u', $part, $m) === 1) {
            $chomeBody = $m[1];
            $suffix = $m[2];
            $ranges = [];
            if (preg_match('/^([０-９0-9]+)[〜～]([０-９0-9]+)$/u', $chomeBody, $cm) === 1) {
                $ranges[] = ['from' => NumeralConverter::toHalfwidthInt($cm[1]), 'to' => NumeralConverter::toHalfwidthInt($cm[2])];
            } elseif (preg_match('/^([０-９0-9]+)$/u', $chomeBody, $cm) === 1) {
                $n = NumeralConverter::toHalfwidthInt($cm[1]);
                $ranges[] = ['from' => $n, 'to' => $n];
            }
            if (!empty($ranges) && $suffix === '') {
                return new ParsedDetailItem(DetailPattern::ChomeRange, ['ranges' => $ranges]);
            }
            // 丁目+番地以上/以下: 「N丁目M番以上」
            if (!empty($ranges) && preg_match('/^([０-９0-9]+)番以(上|降|下)$/u', $suffix, $bm) === 1) {
                return new ParsedDetailItem(DetailPattern::ChomeBanchi, [
                    'chome' => $ranges,
                    'banchi' => ['type' => 'bound', 'boundary' => NumeralConverter::toHalfwidthInt($bm[1]), 'direction' => $bm[2] === '下' ? 'below' : 'above'],
                ]);
            }
            // 丁目+番地範囲: 「N丁目M番〜P番」
            if (!empty($ranges) && preg_match('/^([０-９0-9]+)番?[〜～]([０-９0-9]+)番$/u', $suffix, $bm) === 1) {
                return new ParsedDetailItem(DetailPattern::ChomeBanchi, [
                    'chome' => $ranges,
                    'banchi' => ['type' => 'range', 'from' => NumeralConverter::toHalfwidthInt($bm[1]), 'to' => NumeralConverter::toHalfwidthInt($bm[2])],
                ]);
            }
            // 丁目+追加条件(番、号等) → テキストとして保持
            return new ParsedDetailItem(DetailPattern::Text, ['keyword' => $part]);
        }

        // 番地以上/以下パターン（parseOnePartレベル）
        if (preg_match('/^([０-９0-9]+)番以(上|降|下)$/u', $part, $m) === 1) {
            return new ParsedDetailItem(DetailPattern::BanchiBound, [
                'boundary' => NumeralConverter::toHalfwidthInt($m[1]),
                'direction' => $m[2] === '下' ? 'below' : 'above',
            ]);
        }

        // 番地範囲部分
        $seg = self::parseBanchiPart($part);
        if ($seg !== null) {
            return new ParsedDetailItem(DetailPattern::BanchiRange, ['segments' => [$seg]]);
        }

        // テキスト
        return new ParsedDetailItem(DetailPattern::Text, ['keyword' => $part]);
    }

    // ========================================================================
    // 丁目パース
    // ========================================================================

    /** @return list<array{from: int, to: int}> */
    private static function parseChomeRanges(string $detail): array
    {
        $body = (string) preg_replace('/丁目$/u', '', $detail);
        $ranges = [];
        foreach (explode('、', $body) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([０-９0-9]+)[〜～]([０-９0-9]+)$/u', $part, $m) === 1) {
                $ranges[] = ['from' => NumeralConverter::toHalfwidthInt($m[1]), 'to' => NumeralConverter::toHalfwidthInt($m[2])];
            } elseif (preg_match('/^([０-９0-9]+)$/u', $part, $m) === 1) {
                $n = NumeralConverter::toHalfwidthInt($m[1]);
                $ranges[] = ['from' => $n, 'to' => $n];
            }
        }
        return $ranges;
    }

    // ========================================================================
    // 番地パース
    // ========================================================================

    /** @return list<array{type: string, ...}>|null */
    private static function parseBanchiSegments(string $detail): ?array
    {
        $body = (string) preg_replace('/番地$/u', '', $detail);
        $segments = [];
        foreach (explode('、', $body) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $seg = self::parseBanchiPart($part);
            if ($seg === null) {
                return null;
            }
            $segments[] = $seg;
        }
        return count($segments) > 0 ? $segments : null;
    }

    /** @return array{type: string, ...}|null */
    private static function parseBanchiPart(string $part): ?array
    {
        if (preg_match('/^([0-90-9０-９]+)$/u', $part, $m) === 1) {
            return ['type' => 'single', 'banchi' => NumeralConverter::toHalfwidthInt($m[1])];
        }

        if (preg_match(
            '/^([0-90-9０-９]+)(?:[-－−の]([0-90-9０-９]+))?[〜～]([0-90-9０-９]+)(?:[-－−の]([0-90-9０-９]+))?$/u',
            $part,
            $m
        ) === 1) {
            $fromBanchi = NumeralConverter::toHalfwidthInt($m[1]);
            $fromSub = ($m[2] ?? '') !== '' ? NumeralConverter::toHalfwidthInt($m[2]) : null;
            $toBanchi = NumeralConverter::toHalfwidthInt($m[3]);
            $toSub = ($m[4] ?? '') !== '' ? NumeralConverter::toHalfwidthInt($m[4]) : null;
            // 「5363-7〜8」のようにto_banchi < from_banchiの場合は同一番地の枝番範囲
            if ($fromSub !== null && $toBanchi < $fromBanchi) {
                $toSub = $toBanchi;
                $toBanchi = $fromBanchi;
            }
            return [
                'type' => 'range',
                'from_banchi' => $fromBanchi,
                'from_sub' => $fromSub,
                'to_banchi' => $toBanchi,
                'to_sub' => $toSub,
            ];
        }

        if (preg_match('/^([0-90-9０-９]+)[-－−の]([0-90-9０-９]+)$/u', $part, $m) === 1) {
            return ['type' => 'single', 'banchi' => NumeralConverter::toHalfwidthInt($m[1])];
        }

        return null;
    }

    private static function evaluateSegment(array $seg, int $banchi, ?int $banchiSub): ?bool
    {
        if ($seg['type'] === 'single') {
            return $seg['banchi'] === $banchi;
        }
        return self::evaluateRange(
            $seg['from_banchi'], $seg['from_sub'],
            $seg['to_banchi'], $seg['to_sub'],
            $banchi, $banchiSub
        );
    }

    private static function evaluateRange(
        int $fromBanchi, ?int $fromSub,
        int $toBanchi, ?int $toSub,
        int $banchi, ?int $banchiSub
    ): ?bool {
        if ($banchi < $fromBanchi || $banchi > $toBanchi) {
            return false;
        }
        if ($banchi > $fromBanchi && $banchi < $toBanchi) {
            return true;
        }
        $uncertain = false;
        if ($banchi === $fromBanchi && $fromSub !== null) {
            if ($banchiSub === null) {
                $uncertain = true;
            } elseif ($banchiSub < $fromSub) {
                return false;
            }
        }
        if ($banchi === $toBanchi && $toSub !== null) {
            if ($banchiSub === null) {
                $uncertain = true;
            } elseif ($banchiSub > $toSub) {
                return false;
            }
        }
        return $uncertain ? null : true;
    }

    /**
     * カギ括弧内の「、」を無視して「、」で分割する。
     * 例: 「中一里山「９番地の４、１２番地を除く」、長尾山」→ ['中一里山「９番地の４、１２番地を除く」', '長尾山']
     *
     * @return list<string>
     */
    private static function splitByComma(string $text): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $chars = mb_str_split($text);
        foreach ($chars as $ch) {
            if ($ch === '「' || $ch === '（' || $ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === '」' || $ch === '）' || $ch === ')') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
            } elseif ($ch === '、' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /**
     * 「白滝Ｂ・Ｃ」のような共通接頭辞+短いサフィックスの列挙を展開する。
     * 「・」で区切った各要素が短く（1-2文字）、共通の接頭辞が残る場合に展開。
     * 例: 「白滝Ｂ・Ｃ」→ ['白滝Ｂ', '白滝Ｃ']
     *
     * @return list<string> 展開できなければ空配列
     */
    private static function expandSuffixList(string $text): array
    {
        if (mb_strpos($text, '・') === false) {
            return [];
        }
        $parts = explode('・', $text);
        if (count($parts) < 2) {
            return [];
        }
        // 最初のパートが最も長く、接頭辞+サフィックスの形。後続は短いサフィックスのみ。
        $first = $parts[0];
        $maxSuffixLen = 0;
        for ($i = 1; $i < count($parts); $i++) {
            $len = mb_strlen($parts[$i]);
            if ($len > $maxSuffixLen) {
                $maxSuffixLen = $len;
            }
        }
        // 後続パートが全て短い（最大2文字）場合に共通接頭辞を推定
        if ($maxSuffixLen > 2 || $maxSuffixLen === 0) {
            return [];
        }
        $prefixLen = mb_strlen($first) - $maxSuffixLen;
        if ($prefixLen <= 0) {
            return [];
        }
        $prefix = mb_substr($first, 0, $prefixLen);
        $expanded = [$first];
        for ($i = 1; $i < count($parts); $i++) {
            $expanded[] = $prefix . $parts[$i];
        }
        return $expanded;
    }
}

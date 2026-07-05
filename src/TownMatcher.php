<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 住所文字列の残り部分に対して、市区町村コードに紐づく町名一覧から最長一致するものを探す。
 *
 * 表記ゆれ（算用数字/漢数字、「字」「大字」の有無）を吸収するため、町名ごとに
 * 複数の表記バリエーションを候補として持ち、最も長く一致するものを採用する。
 * 一致した場合、出力する町名は漢数字表記に統一する。
 */
final class TownMatcher
{
    /** @var array<string,array<string,string>> 市区町村コード => (バリエーション => 正規の町名) */
    private array $candidatesByCity = [];

    /**
     * 地名に現れる異体字・表記ゆれの同値グループ。
     * 各グループ内の文字は互いに置換可能として扱う。
     *
     * @var list<list<string>>
     */
    private const CHAR_EQUIVALENCES = [
        ['ヶ', 'ケ', 'が', 'ガ'],
        ['ノ', '之', 'の'],
        ['ッ', 'ツ'],
        ['ニ', '二'],  // カタカナ「ニ」と漢数字「二」
        ['ハ', '八'],  // カタカナ「ハ」と漢数字「八」
        ['崎', '﨑'],
        ['塚', "\u{FA10}"],  // 塚 (U+FA10)
        ['舘', '館'],
        ['竈', '釜'],
        ['條', '条'],
        ['藪', '薮'],
        ['渕', '淵', '渊'],
        ['曾', '曽'],
    ];

    public function __construct(private readonly PostalCodeRepository $repository)
    {
    }

    /** @return array<string,string> バリエーション => 正規の町名（バリエーションが長い順） */
    private function candidatesForCity(string $cityCode): array
    {
        if (isset($this->candidatesByCity[$cityCode])) {
            return $this->candidatesByCity[$cityCode];
        }

        $variantToTown = [];
        foreach ($this->repository->townsByCity($cityCode) as $town) {
            $variants = [
                $town,
                NumeralConverter::kanjiToArabic($town),
                NumeralConverter::arabicToKanji($town),
            ];
            // 数字バリエーションに対して、さらに文字の異体字バリエーションを生成する
            $allVariants = [];
            foreach ($variants as $variant) {
                foreach (self::generateCharVariants($variant) as $charVariant) {
                    $allVariants[] = $charVariant;
                }
            }
            foreach ($allVariants as $variant) {
                $variantToTown[$variant] ??= $town;
                $variantToTown['字' . $variant] ??= $town;
                $variantToTown['大字' . $variant] ??= $town;
                // 合併住所で町名途中に「大字」「字」が挿入されるケース対応
                // 例: DB上「武雄町昭和」→ 入力「武雄町大字昭和」でもマッチ
                foreach (self::insertAzaVariants($variant) as $azaVariant) {
                    $variantToTown[$azaVariant] ??= $town;
                }
            }
        }

        // 町省略バリエーション: 町名末尾の「町」を省いた短縮形でもマッチさせる
        foreach ($this->repository->townsByCity($cityCode) as $town) {
            if (mb_substr($town, -1) === '町' && mb_strlen($town) >= 3) {
                $shortened = mb_substr($town, 0, -1);
                $variantToTown[$shortened] ??= $town;
            }
        }

        uksort($variantToTown, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        return $this->candidatesByCity[$cityCode] = $variantToTown;
    }

    /**
     * 町名中の「町」「区」等の区切りの後ろに「大字」「字」を挿入したバリエーションを生成。
     * 例: 「武雄町昭和」→ [「武雄町大字昭和」「武雄町字昭和」]
     *
     * @return list<string>
     */
    private static function insertAzaVariants(string $town): array
    {
        $results = [];
        // 「町」「区」の直後に「大字」「字」を挿入する
        if (preg_match_all('/(?:町|区)/u', $town, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $bytePos = $match[1] + strlen($match[0]);
                // 区切りの後ろに既に文字がある場合のみ（末尾の「町」等は対象外）
                if ($bytePos >= strlen($town)) {
                    continue;
                }
                $before = substr($town, 0, $bytePos);
                $after = substr($town, $bytePos);
                // 既に「大字」「字」で始まっている場合はスキップ
                if (str_starts_with($after, '大字') || str_starts_with($after, '字')) {
                    continue;
                }
                $results[] = $before . '大字' . $after;
                $results[] = $before . '字' . $after;
            }
        }
        return $results;
    }

    /**
     * テキストに含まれる異体字を置換して、表記バリエーションの配列を返す。
     * 元のテキストも含む。文字グループごとに独立して置換し、組み合わせ爆発を防ぐため
     * 各グループにつき1回だけ置換する（同じグループの文字が複数出現する場合も一括置換）。
     *
     * @return list<string>
     */
    private static function generateCharVariants(string $text): array
    {
        $results = [$text];

        foreach (self::CHAR_EQUIVALENCES as $group) {
            // このグループのどの文字がテキストに含まれるか確認
            $foundChars = [];
            foreach ($group as $char) {
                if (mb_strpos($text, $char) !== false) {
                    $foundChars[] = $char;
                }
            }
            if ($foundChars === []) {
                continue;
            }

            // 見つかった文字を、グループ内の他の文字に一括置換してバリエーションを追加
            $newResults = [];
            foreach ($results as $current) {
                foreach ($group as $replacement) {
                    // 置換先がすでに含まれている文字と同じならスキップ（元テキストの再生成を防ぐ）
                    $replaced = $current;
                    foreach ($foundChars as $found) {
                        if ($found !== $replacement) {
                            $replaced = str_replace($found, $replacement, $replaced);
                        }
                    }
                    if ($replaced !== $current) {
                        $newResults[] = $replaced;
                    }
                }
            }
            foreach ($newResults as $nr) {
                $results[] = $nr;
            }
        }

        return $results;
    }

    private const KYOTO_DIRECTION_PATTERN = '/(?:上[ルる]|下[ルる]|[東西南北]入[ルる]?)/u';

    /**
     * $textの先頭から最長一致する町名を探す。
     *
     * 京都の住所では「通り名＋方角（上ル/下ル/西入等）＋正式町名」の構造を持つ。
     * 通り名の先頭が別の短い町名にマッチしてしまうのを防ぐため、方角キーワードを
     * 検出した場合はその後ろから改めて町名マッチを試みて、より適切な方を採用する。
     *
     * また、テキスト中の「大字」「字」を除去したバリエーションでもマッチを試みる。
     *
     * @return array{town: string, dbTown: string, matchedLength: int, kyotoStreet: ?string}|null
     */
    public function match(string $cityCode, string $text): ?array
    {
        $candidates = $this->candidatesForCity($cityCode);
        $directMatch = $this->matchFirst($candidates, $text);

        // テキスト中の「大字」「字」を除去して再マッチ（入力に余分な字/大字がある場合）
        if ($directMatch === null || $this->hasAzaInText($text)) {
            $strippedMatch = $this->matchWithAzaStripped($candidates, $text);
            if ($strippedMatch !== null) {
                if ($directMatch === null || $strippedMatch['matchedLength'] > $directMatch['matchedLength']) {
                    $directMatch = $strippedMatch;
                }
            }
        }

        // 京都の通り名対応
        $afterDirection = $this->findAfterDirection($text);
        if ($afterDirection !== null) {
            $rematch = $this->matchFirst($candidates, $afterDirection['remaining']);
            if ($rematch === null) {
                $rematch = $this->matchWithAzaStripped($candidates, $afterDirection['remaining']);
            }
            if ($rematch !== null) {
                $kyotoStreet = mb_substr($text, 0, $afterDirection['offset']);
                return [
                    'town' => $rematch['town'],
                    'dbTown' => $rematch['dbTown'],
                    'matchedLength' => $afterDirection['offset'] + $rematch['matchedLength'],
                    'kyotoStreet' => $kyotoStreet,
                ];
            }
        }

        if ($directMatch !== null) {
            $directMatch['kyotoStreet'] ??= null;
        }
        return $directMatch;
    }

    private function hasAzaInText(string $text): bool
    {
        return mb_strpos($text, '大字') !== false || mb_strpos($text, '字') !== false;
    }

    /**
     * テキストから「大字」「字」を除去してマッチを試みる。
     * マッチした場合、matchedLengthは元テキスト上での消費文字数を返す。
     *
     * @param array<string,string> $candidates
     * @return array{town: string, dbTown: string, matchedLength: int}|null
     */
    private function matchWithAzaStripped(array $candidates, string $text): ?array
    {
        // 「大字」「字」の出現位置を全て見つけて除去
        $stripped = $text;
        $azaPositions = [];
        // 「大字」を先に処理（「字」を先にすると「大字」の「字」部分だけ消えてしまう）
        if (preg_match_all('/大字|字/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = 0;
            foreach ($matches[0] as [$match, $bytePos]) {
                $azaPositions[] = ['byte' => $bytePos, 'len' => strlen($match), 'mbLen' => mb_strlen($match)];
            }
        }
        $stripped = (string) preg_replace('/大字|字/u', '', $text);
        if ($stripped === $text) {
            return null;
        }

        $result = $this->matchFirst($candidates, $stripped);
        if ($result === null) {
            return null;
        }

        // 元テキスト上で何文字消費するか計算
        // strippedでのマッチ長を元テキストにマッピング
        $strippedConsumed = $result['matchedLength'];
        $origPos = 0;
        $strippedPos = 0;
        $chars = mb_str_split($text);
        $i = 0;
        while ($strippedPos < $strippedConsumed && $i < count($chars)) {
            $remaining = mb_substr($text, $origPos);
            if (str_starts_with($remaining, '大字')) {
                $origPos += 2;
                $i += 2;
                continue;
            }
            if (str_starts_with($remaining, '字')) {
                $origPos += 1;
                $i += 1;
                continue;
            }
            $origPos++;
            $strippedPos++;
            $i++;
        }

        $result['matchedLength'] = $origPos;
        return $result;
    }

    /**
     * @param array<string,string> $candidates
     * @return array{town: string, dbTown: string, matchedLength: int}|null
     */
    private function matchFirst(array $candidates, string $text): ?array
    {
        foreach ($candidates as $variant => $canonicalTown) {
            if (str_starts_with($text, $variant)) {
                return [
                    'town' => NumeralConverter::arabicToKanji($canonicalTown),
                    'dbTown' => $canonicalTown,
                    'matchedLength' => mb_strlen($variant),
                ];
            }
        }
        return null;
    }

    /**
     * テキスト中の最後の京都方角キーワードの直後の位置と残り文字列を返す。
     *
     * @return array{offset: int, remaining: string}|null
     */
    private function findAfterDirection(string $text): ?array
    {
        if (preg_match_all(self::KYOTO_DIRECTION_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        // 最後の方角キーワードの後ろから町名を探す
        $lastMatch = end($matches[0]);
        $byteOffset = $lastMatch[1] + strlen($lastMatch[0]);
        $remaining = substr($text, $byteOffset);
        if ($remaining === '' || $remaining === false) {
            return null;
        }
        $offset = mb_strlen(substr($text, 0, $byteOffset));
        return ['offset' => $offset, 'remaining' => $remaining];
    }
}

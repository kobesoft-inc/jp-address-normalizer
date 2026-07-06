<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

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
        ['澤', '沢'],
        ['廣', '広'],
        ['瀉', '潟'],
        ['藏', '蔵'],
        ['應', '応'],
        ['餠', '餅'],
        ['彌', '弥'],
        ['ヱ', 'エ'],  // 旧仮名遣い
        ['ヰ', 'イ'],  // 旧仮名遣い
        ['麴', '麹'],  // 麴町（千代田区）等
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
            $fullwidthArabic = NumeralConverter::kanjiToArabic($town);
            $variants = [
                $town,
                $fullwidthArabic,
                NumeralConverter::toHalfwidthDigits($fullwidthArabic),
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

        // 「〜通り」の送り仮名省略バリエーション（例: 「平和通り」→「平和通」）
        foreach ($this->repository->townsByCity($cityCode) as $town) {
            if (str_ends_with($town, '通り')) {
                $shortened = mb_substr($town, 0, -1);
                $variantToTown[$shortened] ??= $town;
            }
        }

        // 「○○町○○」のように大字名が町名を繰り返すケースでは、住所側で大字部分が
        // 省略され「○○町」とだけ書かれることがある（例: DB「本庄町本庄」→ 入力「本庄町」）。
        // 同じ市区町村に別の独立した「○○町」が存在する場合は誤爆を避けるため追加しない。
        $allTownNames = $this->repository->townsByCity($cityCode);
        $allTownNameSet = array_flip($allTownNames);
        foreach ($allTownNames as $town) {
            if (preg_match('/^(.+)町\1$/u', $town, $m) === 1) {
                $shortAlias = $m[1] . '町';
                if (!isset($allTownNameSet[$shortAlias])) {
                    $variantToTown[$shortAlias] ??= $town;
                }
            }
        }

        // 「神田三崎町」→「三崎町」のように、旧区名等の接頭辞（AddressExceptions::
        // OMITTABLE_TOWN_PREFIXESに登録された、既知の判定可能なもののみ）が省略される
        // ケース。同じ市区町村に別の独立した省略後の町名が存在する場合は追加しない。
        foreach (AddressExceptions::omittableTownPrefixes($cityCode) as $prefix) {
            foreach ($allTownNames as $town) {
                if (str_starts_with($town, $prefix) && mb_strlen($town) > mb_strlen($prefix)) {
                    $withoutPrefix = mb_substr($town, mb_strlen($prefix));
                    if (!isset($allTownNameSet[$withoutPrefix])) {
                        $variantToTown[$withoutPrefix] ??= $town;
                    }
                }
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

    private const KYOTO_DIRECTION_PATTERN = '/(?:上(?:ル|る|がる)|下(?:ル|る|がる)|[東西南北]入(?:ル|る)?)/u';

    /**
     * $textの先頭から最長一致する町名を探す。
     *
     * 京都の住所では「通り名＋方角（上ル/下ル/西入等）＋正式町名」の構造を持つ。
     * 通り名の先頭が別の短い町名にマッチしてしまうのを防ぐため、方角キーワードを
     * 検出した場合はその後ろから改めて町名マッチを試みて、より適切な方を採用する。
     *
     * また、テキスト中の「大字」「字」を除去したバリエーションでもマッチを試みる。
     *
     * @return array{town: string, matchedLength: int, kyotoStreet: ?string}|null
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
            $remaining = $afterDirection['remaining'];
            $extraOffset = 0;
            $rematch = $this->matchFirst($candidates, $remaining);
            if ($rematch === null) {
                $rematch = $this->matchWithAzaStripped($candidates, $remaining);
            }
            // 「通り名＋方角＋丁目」の後に町名が続く場合（例:「大黒町通五条上る２丁目大黒町」
            // 「河原町通二条下る二丁目下丸屋町」）。方角キーワードの直後が丁目表記
            // （算用数字・漢数字どちらも）なら、それも読み飛ばして町名を探す。
            if ($rematch === null && preg_match('/^(?:[０-９0-9]+|[一二三四五六七八九十]+)丁目/u', $remaining, $cm) === 1) {
                $afterChome = mb_substr($remaining, mb_strlen($cm[0]));
                $rematch = $this->matchFirst($candidates, $afterChome);
                if ($rematch === null) {
                    $rematch = $this->matchWithAzaStripped($candidates, $afterChome);
                }
                if ($rematch !== null) {
                    $extraOffset = mb_strlen($cm[0]);
                }
            }
            if ($rematch !== null) {
                $kyotoStreet = mb_substr($text, 0, $afterDirection['offset'] + $extraOffset);
                return [
                    'town' => $rematch['town'],
                    'matchedLength' => $afterDirection['offset'] + $extraOffset + $rematch['matchedLength'],
                    'kyotoStreet' => $kyotoStreet,
                ];
            }
        }

        if ($directMatch !== null) {
            $directMatch['kyotoStreet'] ??= null;
        }

        // 町名なし地域（「○○村一円」のような全域エントリのみが存在する市区町村）は、
        // 住所側に町名の文字が一切現れないため、上記のどのマッチにもかからない。
        // 候補が「一円」の1件だけなら、それを消費文字数0で確定させる。
        if ($directMatch === null) {
            $directMatch = $this->matchIchien($cityCode);
        }

        return $directMatch;
    }

    /**
     * @return array{town: string, matchedLength: int, kyotoStreet: ?string}|null
     */
    private function matchIchien(string $cityCode): ?array
    {
        $towns = $this->repository->townsByCity($cityCode);
        if (count($towns) !== 1 || !str_ends_with($towns[0], '一円')) {
            return null;
        }

        return [
            'town' => $towns[0],
            'matchedLength' => 0,
            'kyotoStreet' => null,
        ];
    }

    /**
     * 除去を試みるトークン（長い順）。
     * 「大字」「字」に加え、「区」も対象とする。平成の大合併で編入された旧市町村名が
     * 「宇陀市榛原区下井足」のように「区」付きで書かれる一方、DB上の町名は
     * 「榛原下井足」のように「区」を含まない場合があるため。
     */
    private const AZA_TOKENS_PATTERN = '/大字|字|区/u';

    private function hasAzaInText(string $text): bool
    {
        return preg_match(self::AZA_TOKENS_PATTERN, $text) === 1;
    }

    /**
     * テキストから「大字」「字」「区」を除去してマッチを試みる。
     * マッチした場合、matchedLengthは元テキスト上での消費文字数を返す。
     *
     * @param array<string,string> $candidates
     * @return array{town: string, dbTown: string, matchedLength: int}|null
     */
    private function matchWithAzaStripped(array $candidates, string $text): ?array
    {
        $stripped = (string) preg_replace(self::AZA_TOKENS_PATTERN, '', $text);
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
            if (str_starts_with($remaining, '字') || str_starts_with($remaining, '区')) {
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
     * @return array{town: string, matchedLength: int}|null
     */
    private const DIGIT_PATTERN = '/^[0-9０-９]+$/u';

    private function matchFirst(array $candidates, string $text): ?array
    {
        foreach ($candidates as $variant => $canonicalTown) {
            // 町名が「十二」→半角"12"のように純粋な数字文字列になる変種があると、
            // PHPの配列キーが自動的にint型になるため、文字列に戻してから比較する。
            $variant = (string) $variant;
            if (!str_starts_with($text, $variant)) {
                continue;
            }
            // 「十二」→"12"のような数字だけの町名バリエーションは、番地の先頭部分と
            // 偶然一致しうる（例: 「12」が実際は番地「123」の一部）。マッチした直後に
            // 数字が続く場合は、番地の一部を誤って消費しているとみなし除外する。
            if (preg_match(self::DIGIT_PATTERN, $variant) === 1) {
                $next = mb_substr($text, mb_strlen($variant), 1);
                if ($next !== '' && preg_match(self::DIGIT_PATTERN, $next) === 1) {
                    continue;
                }
            }
            // DB上の正式表記（$canonicalTown）をそのまま返す。かつて算用数字を
            // 無条件に漢数字化していたが、「南７線西」のように算用数字表記が
            // 正式な町名も実在するため、変換せず正式表記をそのまま採用する。
            return [
                'town' => $canonicalTown,
                'matchedLength' => mb_strlen($variant),
            ];
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

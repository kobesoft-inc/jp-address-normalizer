<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

use JpAddressNormalizer\Internal\PostalCodeRepository;
use JpAddressNormalizer\Internal\PostalCodeResolver;
use JpAddressNormalizer\Internal\StreetBuildingSplitter;
use JpAddressNormalizer\Internal\StreetParser;
use JpAddressNormalizer\Internal\TownMatcher;
use PDO;

/**
 * 日本の住所文字列を、郵便番号・都道府県コード・市区町村コード・町名・番地・建物名に分割する。
 *
 * jp-postal-code-db (https://github.com/kobesoft-inc/jp-postal-code-db) が配布している
 * SQLite3データベースを参照データとして利用し、都道府県名・市区町村名・町名を正規表現の
 * 最長一致で切り出す。町名は表記ゆれ（算用数字/漢数字、「字」「大字」の有無）を吸収した上で、
 * 漢数字表記に統一する。
 *
 * 参照データに存在しない住所（旧地名、実在しない住所等）が渡された場合は、解決できた範囲まで
 * 分割し、それ以降は例外を投げずにそのままの文字列を返す。
 *
 * 都道府県名が省略された住所（「静岡市...」のように、市区町村名だけで書かれたもの）にも
 * 対応する。その市区町村名が全国で一意に決まる場合（県庁所在地の市はほぼ全て該当する）に限り、
 * 都道府県を推測して補う。同名の市区町村が複数の都道府県に存在する場合は、誤った推測をする
 * よりも解決できないまま返すことを優先する。
 */
final class AddressNormalizer
{
    private const POSTAL_CODE_PATTERN = '/^\s*(?:〒|郵便番号)?\s*[:：]?\s*(\d{3})-?(\d{4})\s*/u';

    private PostalCodeRepository $repository;
    private TownMatcher $townMatcher;
    private PostalCodeResolver $postalCodeResolver;

    public function __construct(string $postalCodeDbPath)
    {
        $this->repository = new PostalCodeRepository($postalCodeDbPath);
        $this->townMatcher = new TownMatcher($this->repository);
        $this->postalCodeResolver = new PostalCodeResolver($this->repository);
    }

    public static function fromPdo(PDO $pdo): self
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->repository = PostalCodeRepository::fromPdo($pdo);
        $instance->townMatcher = new TownMatcher($instance->repository);
        $instance->postalCodeResolver = new PostalCodeResolver($instance->repository);
        return $instance;
    }

    public function normalize(string $address): ParsedAddress
    {
        $text = self::preprocess($address);
        $emptyStreet = new Street('', null, null, null, null);

        $postalCode = null;
        if (preg_match(self::POSTAL_CODE_PATTERN, $text, $m) === 1) {
            $postalCode = $m[1] . $m[2];
            $text = mb_substr($text, mb_strlen($m[0]));
        }

        [$prefectureCode, $text] = $this->matchLongestPrefix($text, $this->repository->prefectures());

        $cityCode = null;
        if ($prefectureCode === null) {
            $guess = $this->matchCityIgnoringPrefecture($text);
            if ($guess !== null) {
                [$prefectureCode, $cityCode, $text] = $guess;
            }
        }
        if ($prefectureCode === null) {
            return new ParsedAddress($postalCode, null, null, '', $emptyStreet, trim($text), raw: $address);
        }
        $prefectureName = $this->repository->prefectureName($prefectureCode);

        if ($cityCode === null) {
            [$cityCode, $text] = $this->matchLongestPrefix($text, $this->repository->citiesByPrefecture($prefectureCode));
        }
        if ($cityCode === null) {
            [$cityCode, $text] = $this->matchCityWithGunOmitted($text, $prefectureCode);
        }
        if ($cityCode === null) {
            return new ParsedAddress($postalCode, $prefectureCode, null, '', $emptyStreet, trim($text), raw: $address, prefectureName: $prefectureName);
        }
        $cityName = $this->repository->cityName($prefectureCode, $cityCode);

        $town = '';
        $townRaw = null;
        $kyotoStreet = null;
        $azaPrefix = null;
        $townMatch = $this->townMatcher->match($cityCode, $text);
        if ($townMatch !== null) {
            $town = $townMatch['town'];
            $townRaw = mb_substr($text, 0, $townMatch['matchedLength']);
            $kyotoStreet = $townMatch['kyotoStreet'];
            $azaPrefix = self::extractAzaPrefix($townRaw);
            $text = mb_substr($text, $townMatch['matchedLength']);
        }

        $rest = StreetBuildingSplitter::split($text);
        $street = StreetParser::parse($rest['street']);

        // フォールバック: streetが空でbuildingが別の町名で始まる場合、
        // 最初のマッチが短すぎた可能性がある（例: 「本丸子町」→「本」+「丸子町...」）。
        // buildingの先頭から再度町名マッチを試み、成功すればそちらを採用する。
        if ($townMatch !== null && $rest['street'] === '' && $rest['building'] !== '') {
            $retryMatch = $this->townMatcher->match($cityCode, $rest['building']);
            if ($retryMatch !== null) {
                $town = $retryMatch['town'];
                $townRaw = mb_substr($rest['building'], 0, $retryMatch['matchedLength']);
                $kyotoStreet = $retryMatch['kyotoStreet'];
                $azaPrefix = self::extractAzaPrefix($townRaw);
                $remainingAfterRetry = mb_substr($rest['building'], $retryMatch['matchedLength']);
                $rest = StreetBuildingSplitter::split($remainingAfterRetry);
                $street = StreetParser::parse($rest['street']);
            }
        }

        // 京都の通り名（「七条通油小路東入」等）は、DBのdetail欄に地区名として
        // そのまま収録されていることが多いため、郵便番号の逆引き時のテキスト照合対象に含める。
        // DB側は「上る」「下る」（ひらがな、「がる」無し）で統一されているため、
        // 入力側の表記ゆれ（「上ル」「上がる」等）をそれに合わせて正規化する。
        // 「大字」「字」も、DBのdetail欄が「大字の有無」自体を区別条件にしていることがある
        // （例: 軽井沢町の「大字軽井沢」）ため、町名マッチ時に消費された分を保持しておく。
        $textForResolve = ($azaPrefix ?? '')
            . ($kyotoStreet !== null ? self::normalizeKyotoDirection($kyotoStreet) : '')
            . $rest['building'];

        $unresolvedReason = null;
        if ($postalCode === null && $town !== '') {
            $resolved = $this->postalCodeResolver->resolve($cityCode, $town, $street, $textForResolve);
            $postalCode = $resolved->postalCode;
            $unresolvedReason = $resolved->unresolvedReason;
        }

        // 町名なし地域で detail に番地範囲等がある場合（例: 小菅村）
        if ($postalCode === null && $town === '' && $cityCode !== null) {
            $resolved = $this->postalCodeResolver->resolve($cityCode, '', $street, $textForResolve);
            $postalCode = $resolved->postalCode;
            $unresolvedReason = $resolved->unresolvedReason;
        }

        // 町名なし地域のフォールバック: 「○○村一円」のような全域エントリで郵便番号を解決
        if ($postalCode === null && $town === '' && $cityCode !== null) {
            $postalCode = $this->resolveByIchienEntry($cityCode);
        }

        return new ParsedAddress(
            $postalCode,
            $prefectureCode,
            $cityCode,
            $town,
            $street,
            $rest['building'],
            raw: $address,
            townRaw: $townRaw,
            prefectureName: $prefectureName,
            cityName: $cityName,
            unresolvedReason: $unresolvedReason,
            kyotoStreet: $kyotoStreet,
        );
    }

    private static function preprocess(string $text): string
    {
        // NFC normalization
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;

        $text = (string) preg_replace('/[\s　]+/u', '', $text);

        // Normalize dash/hyphen variants to standard hyphen.
        // Unconditional replacements (these are always dashes, never meaningful kana):
        $text = str_replace(
            [
                "\u{FF0D}", // FULLWIDTH HYPHEN-MINUS
                "\u{FE63}", // SMALL HYPHEN-MINUS
                "\u{2212}", // MINUS SIGN
                "\u{2010}", // HYPHEN
                "\u{2043}", // HYPHEN BULLET
                "\u{2011}", // NON-BREAKING HYPHEN
                "\u{2012}", // FIGURE DASH
                "\u{2013}", // EN DASH
                "\u{2014}", // EM DASH
                "\u{FE58}", // SMALL EM DASH
                "\u{2015}", // HORIZONTAL BAR
                "\u{23AF}", // HORIZONTAL LINE EXTENSION
                "\u{23E4}", // HORIZONTAL BAR (MODIFIER)
                "\u{2500}", // BOX DRAWINGS LIGHT HORIZONTAL
                "\u{2501}", // BOX DRAWINGS HEAVY HORIZONTAL
            ],
            '-',
            $text
        );

        // Conditional: ー (U+30FC) and ｰ (U+FF70) only when between digits
        $text = (string) preg_replace('/(?<=[0-9０-９一二三四五六七八九十百千壱弐参])[ーｰ](?=[0-9０-９一二三四五六七八九十百千壱弐参])/u', '-', $text);

        return $text;
    }

    /**
     * 京都の通り名の方角表記（「上ル」「上がる」等）を、DB上の表記（「上る」「下る」、
     * 「がる」無し）に統一する。
     */
    private static function normalizeKyotoDirection(string $text): string
    {
        return (string) preg_replace(
            ['/上(?:ル|がる)/u', '/下(?:ル|がる)/u', '/([東西南北]入)ル/u'],
            ['上る', '下る', '$1'],
            $text
        );
    }

    /**
     * 町名マッチで消費された部分（$townRaw）に「大字」「字」が含まれていれば、それを返す。
     * DBのdetail欄が「大字の有無」自体を区別条件にしている町（例: 軽井沢町の「大字軽井沢」）で、
     * 町名マッチ時に消費されて消えてしまう情報を後段のテキスト照合に残すために使う。
     */
    private static function extractAzaPrefix(string $townRaw): ?string
    {
        if (str_starts_with($townRaw, '大字')) {
            return '大字';
        }
        if (str_starts_with($townRaw, '字')) {
            return '字';
        }
        return null;
    }

    private const KE_VARIANTS = ['ケ', 'ヶ', 'が', 'ガ', 'ヵ'];

    /**
     * $candidates（表記 => コード、長い表記順）のうち、$textの先頭に一致する最長のものを探す。
     * ケ/ヶ/が/ガ/ヵ の表記ゆれを吸収してマッチする。
     *
     * @param array<string,string> $candidates
     * @return array{0: string|null, 1: string} [一致したコード（無ければnull）, 残りの文字列]
     */
    private function matchLongestPrefix(string $text, array $candidates): array
    {
        $normalizedText = self::normalizeKe($text);
        foreach ($candidates as $name => $code) {
            $normalizedName = self::normalizeKe($name);
            if (str_starts_with($normalizedText, $normalizedName)) {
                return [$code, mb_substr($text, mb_strlen($name))];
            }
        }
        return [null, $text];
    }

    private static function normalizeKe(string $text): string
    {
        return str_replace(self::KE_VARIANTS, 'ヶ', $text);
    }

    /**
     * 都道府県名が省略された$textの先頭から、全国の市区町村名のうち一意に決まるものを探す。
     * 同名の市区町村が複数の都道府県に存在する場合は一致させない（誤った推測を避ける）。
     *
     * @return array{0: string, 1: string, 2: string}|null [都道府県コード, 市区町村コード, 残りの文字列]
     */
    private function matchCityIgnoringPrefecture(string $text): ?array
    {
        foreach ($this->repository->citiesByName() as $name => $entries) {
            if (count($entries) === 1 && str_starts_with($text, $name)) {
                [$prefectureCode, $cityCode] = $entries[0];
                return [$prefectureCode, $cityCode, mb_substr($text, mb_strlen($name))];
            }
        }
        return null;
    }

    /**
     * 郡名が省略された住所に対応する。「XX郡YY町」のうち「YY町」部分だけで書かれた
     * 住所をマッチさせるため、市区町村名から郡を除いた部分で再度前方一致を試みる。
     *
     * @return array{0: string|null, 1: string} [一致した市区町村コード（無ければnull）, 残りの文字列]
     */
    private function matchCityWithGunOmitted(string $text, string $prefectureCode): array
    {
        $cities = $this->repository->citiesByPrefecture($prefectureCode);

        // 郡を含む市区町村名から、郡以降の部分で入力テキストとのマッチを試みる。
        // 衝突（同じ省略形が複数の市区町村にマッチ）する場合は安全のためスキップ。
        $gunStripped = []; // 省略形 => [cityCode, fullName]
        foreach ($cities as $name => $code) {
            if (preg_match('/^.+郡(.+)$/u', $name, $m) === 1) {
                $suffix = $m[1];
                if (!isset($gunStripped[$suffix])) {
                    $gunStripped[$suffix] = $code;
                } else {
                    // 衝突が発生 — この省略形は使わない
                    $gunStripped[$suffix] = false;
                }
            }
        }

        // 省略形が長い順に並べて最長一致
        uksort($gunStripped, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($gunStripped as $suffix => $code) {
            if ($code === false) {
                continue;
            }
            if (str_starts_with($text, $suffix)) {
                return [$code, mb_substr($text, mb_strlen($suffix))];
            }
        }

        return [null, $text];
    }

    /**
     * 町名なし地域（「○○村一円」のような全域エントリ）から郵便番号を解決する。
     */
    private function resolveByIchienEntry(string $cityCode): ?string
    {
        $towns = $this->repository->townsByCity($cityCode);
        foreach ($towns as $town) {
            if (str_ends_with($town, '一円')) {
                $postalCodes = $this->repository->postalCodesForTown($cityCode, $town);
                if (count($postalCodes) === 1) {
                    return $postalCodes[0];
                }
            }
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

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
            return new ParsedAddress($postalCode, null, null, '', $emptyStreet, trim($text));
        }
        $prefectureName = $this->repository->prefectureName($prefectureCode);

        if ($cityCode === null) {
            [$cityCode, $text] = $this->matchLongestPrefix($text, $this->repository->citiesByPrefecture($prefectureCode));
        }
        if ($cityCode === null) {
            [$cityCode, $text] = $this->matchCityWithGunOmitted($text, $prefectureCode);
        }
        if ($cityCode === null) {
            return new ParsedAddress($postalCode, $prefectureCode, null, '', $emptyStreet, trim($text), prefectureName: $prefectureName);
        }
        $cityName = $this->repository->cityName($prefectureCode, $cityCode);

        $town = '';
        $dbTown = '';
        $townMatch = $this->townMatcher->match($cityCode, $text);
        if ($townMatch !== null) {
            $town = $townMatch['town'];
            $dbTown = $townMatch['dbTown'];
            $text = mb_substr($text, $townMatch['matchedLength']);
        }

        $rest = StreetBuildingSplitter::split($text);
        $street = StreetParser::parse($rest['street']);

        $unresolvedReason = null;
        if ($postalCode === null && $dbTown !== '') {
            $resolved = $this->postalCodeResolver->resolve($cityCode, $dbTown, $street, $rest['building']);
            $postalCode = $resolved->postalCode;
            $unresolvedReason = $resolved->unresolvedReason;
        }

        return new ParsedAddress(
            $postalCode,
            $prefectureCode,
            $cityCode,
            $town,
            $street,
            $rest['building'],
            prefectureName: $prefectureName,
            cityName: $cityName,
            unresolvedReason: $unresolvedReason,
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

        $text = str_replace(['ケ', 'ヵ'], 'ヶ', $text);
        return $text;
    }

    /**
     * $candidates（表記 => コード、長い表記順）のうち、$textの先頭に一致する最長のものを探す。
     *
     * @param array<string,string> $candidates
     * @return array{0: string|null, 1: string} [一致したコード（無ければnull）, 残りの文字列]
     */
    private function matchLongestPrefix(string $text, array $candidates): array
    {
        foreach ($candidates as $name => $code) {
            if (str_starts_with($text, $name)) {
                return [$code, mb_substr($text, mb_strlen($name))];
            }
        }
        return [null, $text];
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
}

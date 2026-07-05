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
 */
final class AddressNormalizer
{
    private const POSTAL_CODE_PATTERN = '/^\s*〒?\s*(\d{3})-?(\d{4})\s*/u';

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
        $text = $address;
        $emptyStreet = new Street('', null, null, null, null);

        $postalCode = null;
        if (preg_match(self::POSTAL_CODE_PATTERN, $text, $m) === 1) {
            $postalCode = $m[1] . $m[2];
            $text = mb_substr($text, mb_strlen($m[0]));
        }

        [$prefectureCode, $text] = $this->matchLongestPrefix($text, $this->repository->prefectures());
        if ($prefectureCode === null) {
            return new ParsedAddress($postalCode, null, null, '', $emptyStreet, trim($text));
        }

        [$cityCode, $text] = $this->matchLongestPrefix($text, $this->repository->citiesByPrefecture($prefectureCode));
        if ($cityCode === null) {
            return new ParsedAddress($postalCode, $prefectureCode, null, '', $emptyStreet, trim($text));
        }

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

        $candidates = [];
        if ($postalCode === null && $dbTown !== '') {
            // postal_codes/town_detailsの検索には、表示用に漢数字統一したtownではなく、
            // DBに実際に格納されている表記(dbTown)を使う必要がある。
            $resolved = $this->postalCodeResolver->resolve($cityCode, $dbTown, $street, $rest['building']);
            $postalCode = $resolved['postalCode'];
            $candidates = $resolved['candidates'];
        }

        return new ParsedAddress(
            $postalCode,
            $prefectureCode,
            $cityCode,
            $town,
            $street,
            $rest['building'],
            $candidates,
        );
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
}

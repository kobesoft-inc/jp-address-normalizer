<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

use PDO;

/**
 * jp-postal-code-db (https://github.com/kobesoft-inc/jp-postal-code-db) が配布している
 * SQLite3データベースを読み取り、都道府県・市区町村・町名の参照データを提供する。
 */
final class PostalCodeRepository
{
    private PDO $pdo;

    /** @var array<string,string>|null 都道府県名 => 都道府県コード */
    private ?array $prefectures = null;

    /** @var array<string,string>|null 都道府県コード => 都道府県名 */
    private ?array $prefectureNamesByCode = null;

    /** @var array<string,string> 市区町村コード => 市区町村名 */
    private array $cityNamesByCode = [];

    /** @var array<string,array<string,string>> 都道府県コード => (市区町村名 => 市区町村コード) */
    private array $citiesByPrefecture = [];

    /** @var array<string,list<array{string,string}>>|null 市区町村名 => [[都道府県コード, 市区町村コード], ...]（名前が長い順） */
    private ?array $citiesByName = null;

    /** @var array<string,list<string>> 市区町村コード => 町名の一覧(空文字列を除く) */
    private array $townsByCity = [];

    /** @var array<string,list<string>> "市区町村コード\x00町名" => 郵便番号の一覧 */
    private array $postalCodesByTown = [];

    /** @var array<string,list<TownDetail>> */
    private array $detailsCache = [];

    public function __construct(string $databasePath)
    {
        $this->pdo = new PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function fromPdo(PDO $pdo): self
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->pdo = $pdo;
        return $instance;
    }

    /** @return array<string,string> 都道府県名 => 都道府県コード（名前が長い順） */
    public function prefectures(): array
    {
        if ($this->prefectures === null) {
            $rows = $this->pdo->query('SELECT prefecture_code, name FROM prefectures')->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $row) {
                $map[$row['name']] = $row['prefecture_code'];
            }
            uksort($map, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));
            $this->prefectures = $map;
        }
        return $this->prefectures;
    }

    /** 都道府県コードから正式な都道府県名を引く（統一表記での出力用）。 */
    public function prefectureName(string $prefectureCode): ?string
    {
        if ($this->prefectureNamesByCode === null) {
            $this->prefectureNamesByCode = array_flip($this->prefectures());
        }
        return $this->prefectureNamesByCode[$prefectureCode] ?? null;
    }

    /** @return array<string,string> 市区町村名 => 市区町村コード（名前が長い順） */
    public function citiesByPrefecture(string $prefectureCode): array
    {
        if (!isset($this->citiesByPrefecture[$prefectureCode])) {
            $stmt = $this->pdo->prepare('SELECT city_code, name FROM cities WHERE prefecture_code = ?');
            $stmt->execute([$prefectureCode]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[$row['name']] = $row['city_code'];
                $this->cityNamesByCode[$row['city_code']] = $row['name'];
            }
            uksort($map, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));
            $this->citiesByPrefecture[$prefectureCode] = $map;
        }
        return $this->citiesByPrefecture[$prefectureCode];
    }

    /** 都道府県コード・市区町村コードから正式な市区町村名を引く（統一表記での出力用）。 */
    public function cityName(string $prefectureCode, string $cityCode): ?string
    {
        if (!isset($this->cityNamesByCode[$cityCode])) {
            // 名前一覧を構築するキャッシュを温める（都道府県省略時の推測経由では未取得のことがある）。
            $this->citiesByPrefecture($prefectureCode);
        }
        return $this->cityNamesByCode[$cityCode] ?? null;
    }

    /**
     * 都道府県を問わず、全国の市区町村名から都道府県コード・市区町村コードを引く。
     * 同名の市区町村が複数の都道府県に存在する場合は、要素が2件以上になる
     * （都道府県名が省略された住所を解析する際、一意に決まる場合のみ使うための情報）。
     *
     * @return array<string,list<array{string,string}>> 市区町村名 => [[都道府県コード, 市区町村コード], ...]（名前が長い順）
     */
    public function citiesByName(): array
    {
        if ($this->citiesByName === null) {
            $rows = $this->pdo->query('SELECT prefecture_code, city_code, name FROM cities')->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $row) {
                $map[$row['name']][] = [$row['prefecture_code'], $row['city_code']];
            }
            uksort($map, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));
            $this->citiesByName = $map;
        }
        return $this->citiesByName;
    }

    /** @return list<string> 町名の一覧（重複無し、空文字列を除く） */
    public function townsByCity(string $cityCode): array
    {
        if (!isset($this->townsByCity[$cityCode])) {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT town FROM postal_codes WHERE city_code = ? AND town != ''"
            );
            $stmt->execute([$cityCode]);
            $this->townsByCity[$cityCode] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return $this->townsByCity[$cityCode];
    }

    /** @return list<string> 該当する市区町村・町名の郵便番号の一覧（重複無し） */
    public function postalCodesForTown(string $cityCode, string $town): array
    {
        $key = $cityCode . "\x00" . $town;
        if (!isset($this->postalCodesByTown[$key])) {
            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT postal_code FROM postal_codes WHERE city_code = ? AND town = ?'
            );
            $stmt->execute([$cityCode, $town]);
            $this->postalCodesByTown[$key] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return $this->postalCodesByTown[$key];
    }

    /** @return list<TownDetail> */
    public function details(string $cityCode, string $town): array
    {
        $key = $cityCode . "\x00" . $town;
        if (!isset($this->detailsCache[$key])) {
            $stmt = $this->pdo->prepare(
                "SELECT postal_code, detail FROM postal_codes WHERE city_code = ? AND town = ? AND detail != ''"
            );
            $stmt->execute([$cityCode, $town]);
            $this->detailsCache[$key] = array_map(
                static fn (array $row) => new TownDetail($row['postal_code'], $row['detail']),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }
        return $this->detailsCache[$key];
    }
}

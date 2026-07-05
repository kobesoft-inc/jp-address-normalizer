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

    /** @var array<string,array<string,string>> 都道府県コード => (市区町村名 => 市区町村コード) */
    private array $citiesByPrefecture = [];

    /** @var array<string,list<string>> 市区町村コード => 町名の一覧(空文字列を除く) */
    private array $townsByCity = [];

    /** @var array<string,list<string>> "市区町村コード\x00町名" => 郵便番号の一覧 */
    private array $postalCodesByTown = [];

    /** @var array<string,list<array{postal_code: string, detail: string, chome_from: ?int, chome_to: ?int}>> */
    private array $townDetails = [];

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

    /** @return array<string,string> 市区町村名 => 市区町村コード（名前が長い順） */
    public function citiesByPrefecture(string $prefectureCode): array
    {
        if (!isset($this->citiesByPrefecture[$prefectureCode])) {
            $stmt = $this->pdo->prepare('SELECT city_code, name FROM cities WHERE prefecture_code = ?');
            $stmt->execute([$prefectureCode]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[$row['name']] = $row['city_code'];
            }
            uksort($map, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));
            $this->citiesByPrefecture[$prefectureCode] = $map;
        }
        return $this->citiesByPrefecture[$prefectureCode];
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

    /**
     * 同じ町名が複数の郵便番号に分かれる場合の判別情報を返す。1つの郵便番号にしか
     * 対応しない町名の場合は空配列になる。
     *
     * @return list<array{postal_code: string, detail: string, chome_from: ?int, chome_to: ?int}>
     */
    public function townDetails(string $cityCode, string $town): array
    {
        $key = $cityCode . "\x00" . $town;
        if (!isset($this->townDetails[$key])) {
            $stmt = $this->pdo->prepare(
                'SELECT postal_code, detail, chome_from, chome_to FROM town_details WHERE city_code = ? AND town = ?'
            );
            $stmt->execute([$cityCode, $town]);
            $this->townDetails[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->townDetails[$key];
    }
}

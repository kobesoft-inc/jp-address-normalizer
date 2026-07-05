<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\PostalCodeRepository;
use JpAddressNormalizer\PostalCodeResolver;
use JpAddressNormalizer\Street;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostalCodeResolverTest extends TestCase
{
    private function makeResolver(array $postalCodes, array $details): PostalCodeResolver
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE postal_codes (postal_code TEXT, prefecture_code TEXT, city_code TEXT, town TEXT, detail TEXT DEFAULT '')");

        $insert = $pdo->prepare('INSERT INTO postal_codes (postal_code, prefecture_code, city_code, town, detail) VALUES (?, ?, ?, ?, ?)');
        foreach ($details as [$postalCode, $detail]) {
            $insert->execute([$postalCode, '01', '01101', '北一条西', $detail]);
        }
        $existingCodes = array_column($details, 0);
        foreach ($postalCodes as $postalCode) {
            if (!in_array($postalCode, $existingCodes, true)) {
                $insert->execute([$postalCode, '01', '01101', '北一条西', '']);
            }
        }

        return new PostalCodeResolver(PostalCodeRepository::fromPdo($pdo));
    }

    private static function street(?int $chome, ?int $banchi, ?int $banchiSub = null): Street
    {
        return new Street('', $chome, $banchi, $banchiSub, null);
    }

    public function testResolvesByBanchiRange(): void
    {
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '１〜１３１番地'],
                ['0600002', '１３２〜２９８番地'],
            ]
        );

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 50), '');
        $this->assertSame('0600001', $result->postalCode);

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 200), '');
        $this->assertSame('0600002', $result->postalCode);
    }

    public function testResolvesByBanchiListWithSubBranch(): void
    {
        // 「２７９４−１２」のような表記は、番地に枝番を付け足した特定の一点を指す（範囲ではない）。
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '５、９、２７９４−１２番地'],
                ['0600002', '１０、１１番地'],
            ]
        );

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 2794), '');
        $this->assertSame('0600001', $result->postalCode);

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 11), '');
        $this->assertSame('0600002', $result->postalCode);
    }

    public function testResolvesByCompoundRangeWithSubBranchOnBothEnds(): void
    {
        // 「６７−４〜１１３−７番地」のように、範囲の両端それぞれに枝番が付くケース。
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '６７−４〜１１３−７番地'],
                ['0600002', '１〜６６番地'],
            ]
        );

        // 範囲の内側(境界の枝番に関わらず含まれる)
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 90), '');
        $this->assertSame('0600001', $result->postalCode);

        // 下端(67)ちょうどだが、枝番が閾値(4)以上なので含まれる
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 67, 5), '');
        $this->assertSame('0600001', $result->postalCode);

        // 下端(67)ちょうどだが、枝番が閾値(4)未満なので範囲外。もう片方(1~66)にも含まれない。
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 67, 2), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);

        // 上端(113)ちょうどだが、枝番が閾値(7)を超えるので範囲外
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 113, 9), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }

    public function testCompoundRangeBoundaryWithoutSubStaysAmbiguous(): void
    {
        // 下端(67)ちょうどで枝番が不明な場合、範囲に含まれるかどうか確定できない。
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '６７−４〜１１３−７番地'],
                ['0600002', '１〜６６番地'],
            ]
        );

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 67), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }

    public function testResolvesByAsymmetricCompoundRange(): void
    {
        // 「７９９の１〜８６７番地」のように、片側だけに枝番が付くケース。
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '７９９の１〜８６７番地'],
                ['0600002', '１〜７９８番地'],
            ]
        );

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 800), '');
        $this->assertSame('0600001', $result->postalCode);

        // 下端(799)ちょうどで枝番が閾値(1)未満 -> 範囲外。もう片方(1~798)にも含まれない。
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 799, 0), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }

    public function testCatchAllFallbackUsesCompoundRangeExclusion(): void
    {
        // 「その他」への消去法でも、複合レンジの枝番境界を正しく使って除外できる。
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '６７−４〜１１３−７番地'],
                ['0600002', 'その他'],
            ]
        );

        // 200番地は範囲外と明確に判定できるため、消去法で「その他」を採用する。
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 200), '');
        $this->assertSame('0600002', $result->postalCode);

        // 境界(67)ちょうどで枝番不明の場合は、除外できないため決め打ちしない。
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 67), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }

    public function testBanchiOutsideAnyRangeStaysAmbiguous(): void
    {
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '１〜１３１番地'],
                ['0600002', '１３２〜２９８番地'],
            ]
        );

        $result = $resolver->resolve('01101', '北一条西', self::street(null, 9999), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }

    public function testFallsBackToCatchAllWhenOthersAreDefinitelyExcludedByChome(): void
    {
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '１〜１９丁目'],
                ['0600002', 'その他'],
            ]
        );

        // 丁目が20丁目は「1〜19丁目」の範囲外だと明確に判定できるため、消去法で「その他」を採用する。
        $result = $resolver->resolve('01101', '北一条西', self::street(20, 1), '');
        $this->assertSame('0600002', $result->postalCode);
    }

    public function testDoesNotGuessCatchAllWhenChomeIsUnknown(): void
    {
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '１〜１９丁目'],
                ['0600002', 'その他'],
            ]
        );

        // 丁目が分からない住所では、「1〜19丁目」に該当しないと確認できないため決め打ちしない。
        $result = $resolver->resolve('01101', '北一条西', self::street(null, 1), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }

    public function testDoesNotGuessCatchAllWhenOtherCandidateIsFreeText(): void
    {
        $resolver = $this->makeResolver(
            ['0600001', '0600002'],
            [
                ['0600001', '南'],
                ['0600002', 'その他'],
            ]
        );

        // 「南」は自由記述で、住所文字列に含まれていないからといって該当しないとは確証できない。
        $result = $resolver->resolve('01101', '北一条西', self::street(1, 1), '');
        $this->assertNull($result->postalCode);
        $this->assertNotNull($result->unresolvedReason);
    }
}

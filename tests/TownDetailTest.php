<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Tests;

use JpAddressNormalizer\Internal\TownDetail;
use PHPUnit\Framework\TestCase;

final class TownDetailTest extends TestCase
{
    public function testIsCatchAll(): void
    {
        $this->assertTrue((new TownDetail('0600000', ''))->isCatchAll());
        $this->assertTrue((new TownDetail('0600000', 'その他'))->isCatchAll());
        $this->assertFalse((new TownDetail('0600000', '北原、その他'))->isCatchAll());
        $this->assertFalse((new TownDetail('0600000', '長尾山「その他」'))->isCatchAll());
        $this->assertFalse((new TownDetail('0600000', '南'))->isCatchAll());
    }

    public function testHasChomeRange(): void
    {
        $this->assertTrue((new TownDetail('0600000', '１〜１９丁目'))->hasChomeRange());
        $this->assertTrue((new TownDetail('0600000', '１、２丁目'))->hasChomeRange());
        $this->assertTrue((new TownDetail('0600000', '３丁目'))->hasChomeRange());
        $this->assertTrue((new TownDetail('0600000', '３５〜３８、４１、４２丁目'))->hasChomeRange());
        $this->assertTrue((new TownDetail('0600000', '２０〜２８丁目'))->hasChomeRange());
        $this->assertFalse((new TownDetail('0600000', 'その他'))->hasChomeRange());
        $this->assertFalse((new TownDetail('0600000', '１〜１３１番地'))->hasChomeRange());
        // 京都の通り名は丁目を含むがこのパターンではない
        $this->assertFalse((new TownDetail('0600000', '三条大橋東４丁目、三条大橋東入４丁目'))->hasChomeRange());
    }

    public function testMatchesChomeRange(): void
    {
        $detail = new TownDetail('0600001', '１〜１９丁目');
        $this->assertTrue($detail->matchesChome(1));
        $this->assertTrue($detail->matchesChome(19));
        $this->assertTrue($detail->matchesChome(10));
        $this->assertFalse($detail->matchesChome(20));
        $this->assertFalse($detail->matchesChome(0));
    }

    public function testMatchesChomeList(): void
    {
        $detail = new TownDetail('0600001', '１、２丁目');
        $this->assertTrue($detail->matchesChome(1));
        $this->assertTrue($detail->matchesChome(2));
        $this->assertFalse($detail->matchesChome(3));
    }

    public function testMatchesChomeSingle(): void
    {
        $detail = new TownDetail('0600001', '３丁目');
        $this->assertTrue($detail->matchesChome(3));
        $this->assertFalse($detail->matchesChome(1));
        $this->assertFalse($detail->matchesChome(4));
    }

    public function testMatchesChomeMixed(): void
    {
        // 「３５〜３８、４１、４２丁目」= 35, 36, 37, 38, 41, 42
        $detail = new TownDetail('0600001', '３５〜３８、４１、４２丁目');
        $this->assertTrue($detail->matchesChome(35));
        $this->assertTrue($detail->matchesChome(37));
        $this->assertTrue($detail->matchesChome(38));
        $this->assertTrue($detail->matchesChome(41));
        $this->assertTrue($detail->matchesChome(42));
        $this->assertFalse($detail->matchesChome(39));
        $this->assertFalse($detail->matchesChome(40));
        $this->assertFalse($detail->matchesChome(34));
    }

    public function testDescribesBanchi(): void
    {
        $this->assertTrue((new TownDetail('0600000', '１〜１３１番地'))->describesBanchi());
        $this->assertTrue((new TownDetail('0600000', '４５０番地以下'))->describesBanchi());
        $this->assertTrue((new TownDetail('0600000', '４５１番地以上'))->describesBanchi());
        $this->assertTrue((new TownDetail('0600000', '２００番以上'))->describesBanchi());
        $this->assertFalse((new TownDetail('0600000', '１〜１９丁目'))->describesBanchi());
        $this->assertFalse((new TownDetail('0600000', 'その他'))->describesBanchi());
        $this->assertFalse((new TownDetail('0600000', '南'))->describesBanchi());
    }

    public function testEvaluateBanchiAboveBelow(): void
    {
        $below = new TownDetail('6496413', '４５０番地以下');
        $this->assertTrue($below->evaluateBanchi(1, null));
        $this->assertTrue($below->evaluateBanchi(450, null));
        $this->assertFalse($below->evaluateBanchi(451, null));

        $above = new TownDetail('6496162', '４５１番地以上');
        $this->assertFalse($above->evaluateBanchi(450, null));
        $this->assertTrue($above->evaluateBanchi(451, null));
        $this->assertTrue($above->evaluateBanchi(9999, null));
    }

    public function testEvaluateBanchiAboveBelowWithBan(): void
    {
        // 「２００番以上」 (番 without 地)
        $above = new TownDetail('0600001', '２００番以上');
        $this->assertTrue($above->evaluateBanchi(200, null));
        $this->assertTrue($above->evaluateBanchi(999, null));
        $this->assertFalse($above->evaluateBanchi(199, null));
    }

    public function testMatchesTextWithComma(): void
    {
        $detail = new TownDetail('0600001', '上ノ山、下ノ山、中村');
        $this->assertTrue($detail->matchesText('上ノ山1-2'));
        $this->assertTrue($detail->matchesText('中村3'));
        $this->assertFalse($detail->matchesText('西村1'));
    }
}

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
            foreach ($variants as $variant) {
                $variantToTown[$variant] ??= $town;
                $variantToTown['字' . $variant] ??= $town;
                $variantToTown['大字' . $variant] ??= $town;
            }
        }

        uksort($variantToTown, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        return $this->candidatesByCity[$cityCode] = $variantToTown;
    }

    /**
     * $textの先頭から最長一致する町名を探す。
     *
     * @return array{town: string, matchedLength: int}|null 一致すれば漢数字表記に統一した町名と、
     *         $text中で一致した文字数（mb単位）。一致しなければnull。
     */
    public function match(string $cityCode, string $text): ?array
    {
        foreach ($this->candidatesForCity($cityCode) as $variant => $canonicalTown) {
            if (str_starts_with($text, $variant)) {
                return [
                    'town' => NumeralConverter::arabicToKanji($canonicalTown),
                    'matchedLength' => mb_strlen($variant),
                ];
            }
        }
        return null;
    }
}

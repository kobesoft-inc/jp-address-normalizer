<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 町名より後ろ、建物名より前の「番地部分」を表す。
 *
 * 元の表記（raw）は必ず保持する。丁目・番地・枝番・号として機械的に読み取れた場合のみ
 * 該当フィールドに数値が入る。「地割」「線」のような特殊な表記は、raw以外は全てnullになる
 * （番号の抽出はしない。地割・線であること自体はrawを見れば分かる）。
 *
 * 丁目は通常数字だが、区画整理の経緯等で「Ａ丁目」「Ｂ丁目」のようにアルファベットが
 * 使われている地域も実在するため、その場合は$chomeではなく$chomeLabelに格納する
 * （$chomeと$chomeLabelが同時にセットされることはない）。
 */
final class Street
{
    public function __construct(
        public readonly string $raw,
        public readonly ?int $chome,
        public readonly ?int $banchi,
        public readonly ?int $banchiSub,
        public readonly ?int $go,
        public readonly ?string $chomeLabel = null,
    ) {
    }

    /** rawをそのまま使った表記に戻す（区切り文字は正規化しない）。 */
    public function __toString(): string
    {
        return $this->raw;
    }

    /**
     * 丁目・番地・枝番・号を、統一的な表記（「N丁目M-K」「M-K」等）に組み立て直す。
     * 構造化できていない場合（chome・banchiが両方null）はrawをそのまま返す。
     */
    public function format(): string
    {
        if ($this->chome === null && $this->chomeLabel === null && $this->banchi === null) {
            return $this->raw;
        }

        $parts = [];
        if ($this->chome !== null) {
            $parts[] = "{$this->chome}丁目";
        } elseif ($this->chomeLabel !== null) {
            $parts[] = "{$this->chomeLabel}丁目";
        }
        if ($this->banchi !== null) {
            $number = (string) $this->banchi;
            if ($this->banchiSub !== null) {
                $number .= "-{$this->banchiSub}";
            }
            if ($this->go !== null) {
                $number .= "番{$this->go}号";
            }
            $parts[] = $number;
        }

        return implode('', $parts);
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'chome' => $this->chome,
            'chome_label' => $this->chomeLabel,
            'banchi' => $this->banchi,
            'banchi_sub' => $this->banchiSub,
            'go' => $this->go,
        ];
    }
}

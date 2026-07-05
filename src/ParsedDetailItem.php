<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * ParsedDetail内の1つのパターン要素。
 */
final class ParsedDetailItem
{
    public function __construct(
        public readonly DetailPattern $pattern,
        /** @var array<string, mixed> */
        public readonly array $args,
    ) {
    }
}

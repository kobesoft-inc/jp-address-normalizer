<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

use JpAddressNormalizer\UnresolvedReason;

final class PostalCodeResolveResult
{
    public function __construct(
        public readonly ?string $postalCode,
        public readonly ?UnresolvedReason $unresolvedReason = null,
    ) {
    }

    public static function resolved(string $postalCode): self
    {
        return new self($postalCode);
    }

    public static function unresolved(UnresolvedReason $reason): self
    {
        return new self(null, $reason);
    }
}

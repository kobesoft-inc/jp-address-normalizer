<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

/**
 * 郵便番号を一意に確定できなかった理由を表す列挙型。
 */
enum UnresolvedReason: string
{
    /** 町名が参照データに見つからない */
    case TownNotFound = 'town_not_found';

    /** 丁目情報が不足しているため範囲の判定ができない */
    case ChomeUnknown = 'chome_unknown';

    /** 番地情報が不足しているため範囲の判定ができない */
    case BanchiUnknown = 'banchi_unknown';

    /** 枝番情報が不足しているため境界の判定ができない */
    case SubBanchiUnknown = 'sub_banchi_unknown';

    /** テキストマッチで地区名の特定ができない */
    case DistrictUnmatched = 'district_unmatched';

    /** 複数候補があり絞り込めない */
    case Ambiguous = 'ambiguous';
}

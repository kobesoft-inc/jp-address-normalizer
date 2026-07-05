<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

enum MatchLevel: string
{
    /** 完全一致（建物名まで含めて同一） */
    case Exact = 'exact';

    /** 号まで一致（建物名が異なる or 片方に無い） */
    case Go = 'go';

    /** 番地まで一致（号が異なる or 片方に無い） */
    case Banchi = 'banchi';

    /** 丁目まで一致（番地以降が異なる） */
    case Chome = 'chome';

    /** 町名まで一致（丁目以降が異なる） */
    case Town = 'town';

    /** 市区町村まで一致（町名以降が異なる） */
    case City = 'city';

    /** 都道府県まで一致（市区町村以降が異なる） */
    case Prefecture = 'prefecture';

    /** 一致しない */
    case None = 'none';
}

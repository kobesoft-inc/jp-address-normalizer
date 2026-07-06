<?php

declare(strict_types=1);

namespace JpAddressNormalizer\Internal;

enum DetailPattern: string
{
    case CatchAll = 'catch_all';
    case ChomeRange = 'chome_range';
    case ChomeExistence = 'chome_existence';
    case ChomeAbsence = 'chome_absence';
    case ChomeBanchi = 'chome_banchi';
    case ChomeBanchiGo = 'chome_banchi_go';
    case BanchiRange = 'banchi_range';
    case BanchiBound = 'banchi_bound';
    case Floor = 'floor';
    case Text = 'text';
}

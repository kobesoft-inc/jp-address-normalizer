<?php

declare(strict_types=1);

namespace JpAddressNormalizer;

enum DetailPattern: string
{
    case CatchAll = 'catch_all';
    case ChomeRange = 'chome_range';
    case ChomeExistence = 'chome_existence';
    case BanchiRange = 'banchi_range';
    case BanchiBound = 'banchi_bound';
    case Floor = 'floor';
    case Text = 'text';
}

<?php

namespace App\Domain\Maps\Enums;

enum MapImportStatus: string
{
    case Reviewing = 'reviewing';
    case Published = 'published';
    case Discarded = 'discarded';
}

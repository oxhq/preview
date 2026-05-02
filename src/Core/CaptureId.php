<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core;

use Illuminate\Support\Str;

class CaptureId
{
    public function new(): string
    {
        return now()->format('YmdHisv').'-'.Str::lower(Str::random(8));
    }
}

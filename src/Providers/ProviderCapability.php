<?php

declare(strict_types=1);

namespace Oxhq\Preview\Providers;

enum ProviderCapability
{
    case VerifiesSignature;
    case ExtractsEventType;
    case ReSignsPayload;
    case GeneratesFixture;
    case GeneratesTest;
}

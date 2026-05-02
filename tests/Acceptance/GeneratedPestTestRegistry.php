<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Acceptance;

use Closure;

final class GeneratedPestTestRegistry
{
    private static ?Closure $test = null;

    public static function reset(): void
    {
        self::$test = null;
    }

    public static function register(string $description, Closure $test): void
    {
        self::$test = $test;
    }

    public static function test(): Closure
    {
        if (self::$test === null) {
            throw new \RuntimeException('Generated Pest test was not registered.');
        }

        return self::$test;
    }
}

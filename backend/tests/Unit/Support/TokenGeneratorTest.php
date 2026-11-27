<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Siiis\Support\TokenGenerator;

final class TokenGeneratorTest extends TestCase
{
    public function testGeneraTokensHexDe64CaracteresUnicos(): void
    {
        $a = TokenGenerator::generate();
        $b = TokenGenerator::generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        self::assertNotSame($a, $b);
    }

    public function testHashEsSha256Determinista(): void
    {
        self::assertSame(hash('sha256', 'abc'), TokenGenerator::hash('abc'));
        self::assertSame(64, strlen(TokenGenerator::hash('abc')));
        self::assertNotSame('abc', TokenGenerator::hash('abc'));
    }
}

<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost\Tests;

use CodeConjure\FoxPost\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testTheProductionBaseUrl(): void
    {
        self::assertSame('https://webapi.foxpost.hu', Environment::Production->baseUrl());
    }

    public function testTheSandboxBaseUrl(): void
    {
        self::assertSame('https://webapi-test.foxpost.hu', Environment::Sandbox->baseUrl());
    }

    public function testTheSandboxFlagFalseMeansProduction(): void
    {
        self::assertSame(Environment::Production, Environment::fromSandboxFlag(false));
    }

    public function testTheSandboxFlagTrueMeansSandbox(): void
    {
        self::assertSame(Environment::Sandbox, Environment::fromSandboxFlag(true));
    }
}

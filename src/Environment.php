<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost;

enum Environment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Production => 'https://webapi.foxpost.hu',
            self::Sandbox => 'https://webapi-test.foxpost.hu',
        };
    }

    public static function fromSandboxFlag(bool $sandbox): self
    {
        return $sandbox ? self::Sandbox : self::Production;
    }
}

<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost\Tests;

use CodeConjure\FoxPost\DeliveryKind;
use PHPUnit\Framework\TestCase;

final class DeliveryKindTest extends TestCase
{
    public function testTheSlugsMatchTheShopEnum(): void
    {
        self::assertSame('foxpost_home_delivery', DeliveryKind::HomeDelivery->value);
        self::assertSame('foxpost_parcel_locker', DeliveryKind::ParcelLocker->value);
    }

    public function testAForeignSlugIsNotOurs(): void
    {
        /** @var ?DeliveryKind $foreignSlug */
        $foreignSlug = DeliveryKind::tryFrom('packeta_pickup_point');
        self::assertNull($foreignSlug);

        /** @var ?DeliveryKind $emptySlug */
        $emptySlug = DeliveryKind::tryFrom('');
        self::assertNull($emptySlug);
    }
}

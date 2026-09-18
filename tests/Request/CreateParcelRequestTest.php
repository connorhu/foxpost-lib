<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost\Tests\Request;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\FoxPost\Request\CreateParcelRequest;
use PHPUnit\Framework\TestCase;

final class CreateParcelRequestTest extends TestCase
{
    public function testTheParcelLockerPayloadCarriesTheDestinationAndNoAddress(): void
    {
        $payload = (new CreateParcelRequest(
            recipientName: 'Teszt Elek',
            recipientPhone: '+36301234567',
            recipientEmail: 'teszt@example.com',
            deliveryKind: DeliveryKind::ParcelLocker,
            destinationLockerId: 'HU1234',
        ))->toApiPayload();

        self::assertSame('HU1234', $payload['destination']);
        self::assertArrayNotHasKey('recipientZip', $payload);
        self::assertArrayNotHasKey('recipientCity', $payload);
        self::assertArrayNotHasKey('recipientAddress', $payload);
    }

    public function testTheHomeDeliveryPayloadCarriesTheAddressAndNoDestination(): void
    {
        $payload = (new CreateParcelRequest(
            recipientName: 'Teszt Elek',
            recipientPhone: '+36301234567',
            recipientEmail: 'teszt@example.com',
            deliveryKind: DeliveryKind::HomeDelivery,
            recipientZip: '1011',
            recipientCity: 'Budapest',
            recipientAddress: 'Fő utca 1.',
        ))->toApiPayload();

        self::assertSame('1011', $payload['recipientZip']);
        self::assertSame('Budapest', $payload['recipientCity']);
        self::assertSame('Fő utca 1.', $payload['recipientAddress']);
        self::assertSame('HU', $payload['recipientCountry']);
        self::assertArrayNotHasKey('destination', $payload);
    }

    /**
     * A deliveryNote és a fragile CSAK házhozszállításnál kerül a payloadba —
     * ez MAI viselkedés, és nem szabad elveszteni.
     */
    public function testDeliveryNoteAndFragileAreHomeDeliveryOnly(): void
    {
        $locker = (new CreateParcelRequest(
            recipientName: 'Teszt Elek',
            recipientPhone: '+36301234567',
            recipientEmail: 'teszt@example.com',
            deliveryKind: DeliveryKind::ParcelLocker,
            destinationLockerId: 'HU1234',
            deliveryNote: 'Csöngess',
            fragile: true,
        ))->toApiPayload();

        self::assertArrayNotHasKey('deliveryNote', $locker);
        self::assertArrayNotHasKey('fragile', $locker);
    }

    public function testAnEmptyRecipientNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipientName');

        new CreateParcelRequest(
            recipientName: '',
            recipientPhone: '+36301234567',
            recipientEmail: 'teszt@example.com',
            deliveryKind: DeliveryKind::ParcelLocker,
        );
    }

    public function testAnInvalidSizeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size');

        new CreateParcelRequest(
            recipientName: 'Teszt Elek',
            recipientPhone: '+36301234567',
            recipientEmail: 'teszt@example.com',
            deliveryKind: DeliveryKind::ParcelLocker,
            size: 'xxl',
        );
    }
}

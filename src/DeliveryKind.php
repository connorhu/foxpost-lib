<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost;

/**
 * A FoxPost két átvételi módja. A backing érték a HOST slugja — a bolt
 * ShippingDeliveryKindEnum::getSlug()-ja pontosan ezeket adja.
 *
 * A mag így nem ismeri a host enumját: a plugin tryFrom()-mal fordít, és
 * idegen slugra (pl. a #182 Packetáéra) null jön vissza.
 */
enum DeliveryKind: string
{
    case HomeDelivery = 'foxpost_home_delivery';
    case ParcelLocker = 'foxpost_parcel_locker';
}

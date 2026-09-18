<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost\Request;

use CodeConjure\FoxPost\DeliveryKind;

final class CreateParcelRequest
{
    private const array VALID_SIZES = ['xs', 's', 'm', 'l', 'xl'];

    public function __construct(
        public string $recipientName,
        public string $recipientPhone,
        public string $recipientEmail,
        public DeliveryKind $deliveryKind,

        // APM only
        public ?string $destinationLockerId = null,

        // HD only
        public ?string $recipientZip = null,
        public ?string $recipientCity = null,
        public ?string $recipientAddress = null,
        public string $recipientCountry = 'HU',

        // Common optional
        public ?string $size = null,
        public ?int $cod = null,
        public ?string $refCode = null,
        public ?string $comment = null,
        public ?string $deliveryNote = null,
        public bool $fragile = false,
    ) {
        $this->guardNotBlank('recipientName', $recipientName, 150);
        $this->guardNotBlank('recipientPhone', $recipientPhone, 30);
        $this->guardNotBlank('recipientEmail', $recipientEmail, null);

        if (filter_var($recipientEmail, \FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A recipientEmail nem érvényes e-mail cím.');
        }

        $this->guardMaxLength('recipientCity', $recipientCity, 25);
        $this->guardMaxLength('recipientAddress', $recipientAddress, 150);
        $this->guardMaxLength('refCode', $refCode, 30);
        $this->guardMaxLength('comment', $comment, 50);
        $this->guardMaxLength('deliveryNote', $deliveryNote, 50);

        if ($size !== null && !in_array($size, self::VALID_SIZES, true)) {
            throw new \InvalidArgumentException(
                sprintf('A size csak ez lehet: %s — kapott: "%s".', implode(', ', self::VALID_SIZES), $size),
            );
        }

        if ($cod !== null && ($cod < 0 || $cod > 1000000)) {
            throw new \InvalidArgumentException(sprintf('A cod 0 és 1000000 közé kell essen — kapott: %d.', $cod));
        }
    }

    /** @return array<string, mixed> ready to send to FoxPost API */
    public function toApiPayload(): array
    {
        $payload = [
            'recipientName' => $this->recipientName,
            'recipientPhone' => $this->recipientPhone,
            'recipientEmail' => $this->recipientEmail,
        ];

        if ($this->deliveryKind === DeliveryKind::ParcelLocker) {
            $payload['destination'] = $this->destinationLockerId;
        }

        if ($this->deliveryKind === DeliveryKind::HomeDelivery) {
            $payload['recipientZip'] = $this->recipientZip;
            $payload['recipientCity'] = $this->recipientCity;
            $payload['recipientAddress'] = $this->recipientAddress;
            $payload['recipientCountry'] = $this->recipientCountry;
        }

        if ($this->size !== null) {
            $payload['size'] = $this->size;
        }
        if ($this->cod !== null) {
            $payload['cod'] = $this->cod;
        }
        if ($this->refCode !== null) {
            $payload['refCode'] = $this->refCode;
        }
        if ($this->comment !== null) {
            $payload['comment'] = $this->comment;
        }

        if ($this->deliveryKind === DeliveryKind::HomeDelivery) {
            if ($this->deliveryNote !== null) {
                $payload['deliveryNote'] = $this->deliveryNote;
            }
            if ($this->fragile) {
                $payload['fragile'] = true;
            }
        }

        return $payload;
    }

    private function guardNotBlank(string $field, string $value, ?int $maxLength): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('A %s nem lehet üres.', $field));
        }

        $this->guardMaxLength($field, $value, $maxLength);
    }

    private function guardMaxLength(string $field, ?string $value, ?int $maxLength): void
    {
        if ($value === null || $maxLength === null) {
            return;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(
                sprintf('A %s legfeljebb %d karakter lehet — kapott: %d.', $field, $maxLength, mb_strlen($value)),
            );
        }
    }
}

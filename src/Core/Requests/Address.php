<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * A postal address, used for billing.address, shipping.address and the statement descriptor.
 * $country is an ISO 3166-1 alpha-3 code.
 */
final readonly class Address
{
    public function __construct(
        public ?string $street = null,
        public ?string $street2 = null,
        public ?string $city = null,
        public ?string $stateProvince = null,
        public ?string $stateProvinceCode = null,
        public ?string $postcodeZip = null,
        public ?string $country = null,
        public ?string $company = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'street2' => $this->street2,
            'city' => $this->city,
            'stateProvince' => $this->stateProvince,
            'stateProvinceCode' => $this->stateProvinceCode,
            'postcodeZip' => $this->postcodeZip,
            'country' => $this->country,
            'company' => $this->company,
        ];
    }
}

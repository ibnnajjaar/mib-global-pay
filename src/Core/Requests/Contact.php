<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * A person, used for customer.* and shipping.contact.*. The gateway uses customer.email for
 * Click to Pay and for payer email notifications.
 */
final readonly class Contact
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public ?string $mobilePhone = null,
    ) {
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidRequest('Contact email must be a valid email address');
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'phone' => $this->phone,
            'mobilePhone' => $this->mobilePhone,
        ];
    }
}

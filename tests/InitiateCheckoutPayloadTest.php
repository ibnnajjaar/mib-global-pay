<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Support\Amount;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Requests\CreateTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Support\Constants\InteractionOperation;

class InitiateCheckoutPayloadTest extends TestCase
{
    private function payload(OrderData $order): array
    {
        return (new CreateTransactionRequest($order))->getData();
    }

    private function assertNoNullsOrEmptyArrays(array $data, string $path = '')
    {
        foreach ($data as $key => $value) {
            $this->assertNotNull($value, "Null value at {$path}{$key}");
            if (is_array($value)) {
                $this->assertNotEmpty($value, "Empty array at {$path}{$key}");
                $this->assertNoNullsOrEmptyArrays($value, "{$path}{$key}.");
            }
        }
    }

    public function test_minimal_payload_has_no_nulls_or_empty_arrays()
    {
        $data = $this->payload(OrderData::make('ORDER-1', 100.50));

        $this->assertNoNullsOrEmptyArrays($data);
        $this->assertSame([
            'apiOperation' => 'INITIATE_CHECKOUT',
            'order' => [
                'id' => 'ORDER-1',
                'amount' => '100.50',
                'currency' => 'MVR',
            ],
            'interaction' => [
                'operation' => 'PURCHASE',
                'retryAttemptCount' => 1,
            ],
        ], $data);
    }

    public function test_amount_is_a_two_decimal_string()
    {
        $this->assertSame('100.50', $this->payload(OrderData::make('A', 100.5))['order']['amount']);
        $this->assertSame('100.00', $this->payload(OrderData::make('A', 100))['order']['amount']);
        $this->assertSame('0.30', $this->payload(OrderData::make('A', 0.1 + 0.2))['order']['amount']);
    }

    public function test_amount_format()
    {
        $this->assertSame('100.50', Amount::format('100.50'));
        $this->assertSame('100.50', Amount::format('100.5'));
        $this->assertSame('10.00', Amount::format(10));
        $this->assertSame('0.30', Amount::format(0.1 + 0.2));
    }

    /**
     * @dataProvider invalidAmounts
     */
    public function test_invalid_amounts_throw($amount)
    {
        $this->expectException(\InvalidArgumentException::class);
        Amount::format($amount);
    }

    public function invalidAmounts(): array
    {
        return [[0], [-1], ['0.00'], ['10.123'], [10.123], ['abc'], ['1e3'], [null], [''], [[]]];
    }

    public function test_interaction_operation()
    {
        $order = OrderData::make('A', 10);
        $this->assertSame(InteractionOperation::PURCHASE, $order->getInteractionOperation());
        $this->assertSame('PURCHASE', $this->payload($order)['interaction']['operation']);

        $order->setInteractionOperation(InteractionOperation::AUTHORIZE);
        $this->assertSame('AUTHORIZE', $this->payload($order)['interaction']['operation']);

        $order->setInteractionOperation('verify');
        $this->assertSame('VERIFY', $this->payload($order)['interaction']['operation']);

        $order = OrderData::fromArray(['order_id' => 'A', 'amount' => 10, 'interaction_operation' => 'authorize']);
        $this->assertSame('AUTHORIZE', $order->getInteractionOperation());
    }

    public function test_invalid_interaction_operation_throws()
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderData::make('A', 10)->setInteractionOperation('CAPTURE');
    }

    public function test_field_limits()
    {
        $limits = [
            'order id' => function ($value) {
                OrderData::make($value, 10);
            },
            'description' => function ($value) {
                OrderData::make('A', 10)->setOrderDescription($value);
            },
            'reference' => function ($value) {
                OrderData::make('A', 10)->setOrderReference($value);
            },
            'merchant name' => function ($value) {
                OrderData::make('A', 10)->setMerchantName($value);
            },
        ];
        $max = ['order id' => 40, 'description' => 127, 'reference' => 40, 'merchant name' => 40];

        foreach ($limits as $field => $set) {
            $set(str_repeat('a', $max[$field]));

            try {
                $set(str_repeat('a', $max[$field] + 1));
                $this->fail("Expected {$field} longer than {$max[$field]} to throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('cannot exceed', $e->getMessage());
            }
        }
    }

    public function test_javascript_url_throws()
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderData::make('A', 10)->setReturnUrl('javascript://example.com/%0Aalert(1)');
    }

    public function test_optional_fields()
    {
        $order = OrderData::make('A', 10)
            ->setCustomField('salesRegion', 'North')
            ->setCustomField('cartId', '42')
            ->setThreeDSecureAction('bypass')
            ->setCardSecurityCodeDisplay('OPTIONAL')
            ->setLocale('en_US')
            ->setTransactionSource('MOTO')
            ->setTransactionReference('TXN-REF-1');

        $data = $this->payload($order);

        $this->assertSame(['salesRegion' => 'North', 'cartId' => '42'], $data['order']['custom']);
        $this->assertSame(['3DSecure' => 'BYPASS'], $data['interaction']['action']);
        $this->assertSame(['cardSecurityCode' => 'OPTIONAL'], $data['interaction']['displayControl']);
        $this->assertSame('en_US', $data['interaction']['locale']);
        $this->assertSame(['reference' => 'TXN-REF-1', 'source' => 'MOTO'], $data['transaction']);
    }

    public function test_optional_fields_from_array()
    {
        $order = OrderData::fromArray([
            'order_id' => 'A',
            'amount' => '10.50',
            'currency' => 'usd',
            'custom_fields' => ['salesRegion' => 'North'],
            'three_d_secure_action' => 'MANDATORY',
            'card_security_code_display' => 'MANDATORY',
            'locale' => 'fr-CA',
            'transaction_source' => 'INTERNET',
            'transaction_reference' => 'REF',
            'extra_fields' => ['order' => ['customerNote' => 'Hi']],
        ]);

        $this->assertSame('USD', $order->getOrderCurrency());
        $this->assertSame(10.5, $order->getOrderAmount());
        $this->assertSame(['salesRegion' => 'North'], $order->getCustomFields());
        $this->assertSame('MANDATORY', $order->getThreeDSecureAction());
        $this->assertSame('MANDATORY', $order->getCardSecurityCodeDisplay());
        $this->assertSame('fr-CA', $order->getLocale());
        $this->assertSame('INTERNET', $order->getTransactionSource());
        $this->assertSame('REF', $order->getTransactionReference());
        $this->assertSame('Hi', $this->payload($order)['order']['customerNote']);
    }

    /**
     * @dataProvider invalidOptionalFields
     */
    public function test_invalid_optional_fields_throw(callable $set)
    {
        $this->expectException(\InvalidArgumentException::class);
        $set(OrderData::make('A', 10));
    }

    public function invalidOptionalFields(): array
    {
        return [
            'custom key' => [function (OrderData $o) {
                $o->setCustomField('sales-region', 'x');
            }],
            'custom value' => [function (OrderData $o) {
                $o->setCustomField('key', str_repeat('a', 251));
            }],
            '3ds' => [function (OrderData $o) {
                $o->setThreeDSecureAction('SKIP');
            }],
            'csc' => [function (OrderData $o) {
                $o->setCardSecurityCodeDisplay('HIDDEN');
            }],
            'locale' => [function (OrderData $o) {
                $o->setLocale('english');
            }],
            'source' => [function (OrderData $o) {
                $o->setTransactionSource('ONLINE');
            }],
            'reference' => [function (OrderData $o) {
                $o->setTransactionReference(str_repeat('a', 41));
            }],
        ];
    }

    public function test_custom_field_limit()
    {
        $order = OrderData::make('A', 10);
        for ($i = 0; $i < 50; $i++) {
            $order->setCustomField('f' . $i, 'v');
        }
        $order->setCustomField('f0', 'updated');

        $this->expectException(\InvalidArgumentException::class);
        $order->setCustomField('f50', 'v');
    }

    public function test_extra_fields_deep_merge_and_override()
    {
        $order = OrderData::make('A', 10)
            ->setMerchantName('SDK Name')
            ->setMerchantEmail('shop@example.mv')
            ->setExtraFields([
                'interaction' => [
                    'merchant' => ['name' => 'Override Name'],
                    'displayControl' => ['billingAddress' => 'HIDE'],
                ],
                'order' => ['currency' => null],
            ]);

        $data = $this->payload($order);

        $this->assertSame('Override Name', $data['interaction']['merchant']['name']);
        $this->assertSame('shop@example.mv', $data['interaction']['merchant']['email']);
        $this->assertSame(['billingAddress' => 'HIDE'], $data['interaction']['displayControl']);
        $this->assertArrayNotHasKey('currency', $data['order']);
        $this->assertSame('PURCHASE', $data['interaction']['operation']);
    }

    public function test_from_array_without_order_id_throws()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order_id is required');
        OrderData::fromArray(['amount' => 10]);
    }

    public function test_from_array_without_amount_throws()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount is required');
        OrderData::fromArray(['order_id' => 'A']);
    }
}

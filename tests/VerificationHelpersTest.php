<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\WebhookResponseData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\HostedCheckoutReturnData;

class VerificationHelpersTest extends TestCase
{
    public function test_matches_success_indicator()
    {
        $data = HostedCheckoutReturnData::fromArray(['resultIndicator' => 'abc123']);

        $this->assertTrue($data->matchesSuccessIndicator('abc123'));
        $this->assertFalse($data->matchesSuccessIndicator('abc124'));
        $this->assertFalse($data->matchesSuccessIndicator(null));
        $this->assertFalse($data->matchesSuccessIndicator(''));
    }

    public function test_missing_result_indicator_never_matches()
    {
        $this->assertFalse(HostedCheckoutReturnData::fromArray([])->matchesSuccessIndicator('abc123'));
        $this->assertFalse(HostedCheckoutReturnData::fromArray(['resultIndicator' => ''])->matchesSuccessIndicator(''));
    }

    public function test_verify_secret_with_getallheaders_style_headers()
    {
        $webhook = WebhookResponseData::fromArray([], ['X-Notification-Secret' => 'super-secret']);

        $this->assertTrue($webhook->verifySecret('super-secret'));
        $this->assertFalse($webhook->verifySecret('s'));
        $this->assertFalse($webhook->verifySecret(null));
        $this->assertFalse($webhook->verifySecret(''));
    }

    public function test_verify_secret_with_laravel_style_headers()
    {
        $webhook = WebhookResponseData::fromArray([], ['x-notification-secret' => ['super-secret']]);

        $this->assertTrue($webhook->verifySecret('super-secret'));
    }

    public function test_verify_secret_without_header()
    {
        $webhook = WebhookResponseData::fromArray([]);

        $this->assertFalse($webhook->verifySecret('super-secret'));
        $this->assertFalse($webhook->verifySecret(''));
    }

    public function test_assert_valid_secret()
    {
        $webhook = WebhookResponseData::fromArray([], ['X-Notification-Secret' => 'super-secret']);
        $webhook->assertValidSecret('super-secret');

        $this->expectException(MIBGlobalPayException::class);
        $this->expectExceptionMessage('Invalid webhook notification secret');
        $webhook->assertValidSecret('wrong');
    }
}

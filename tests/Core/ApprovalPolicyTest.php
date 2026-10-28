<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy;

it('approves only APPROVED and APPROVED_PENDING_SETTLEMENT by default', function () {
    $policy = new ApprovalPolicy();

    expect($policy->approvedCodes())->toBe([GatewayCode::Approved, GatewayCode::ApprovedPendingSettlement])
        ->and($policy->acceptApprovedAuto)->toBeFalse();

    foreach (GatewayCode::cases() as $code) {
        $expected = in_array($code, [GatewayCode::Approved, GatewayCode::ApprovedPendingSettlement], true);
        expect($policy->isApprovedCode($code))->toBe($expected, $code->name);
    }
});

it('accepts APPROVED_AUTO only when opted in', function () {
    expect((new ApprovalPolicy())->isApprovedCode(GatewayCode::ApprovedAuto))->toBeFalse()
        ->and((new ApprovalPolicy(acceptApprovedAuto: true))->isApprovedCode(GatewayCode::ApprovedAuto))->toBeTrue();
});

it('never approves PARTIALLY_APPROVED, pending or unrecognized codes', function () {
    $policy = new ApprovalPolicy(acceptApprovedAuto: true);

    foreach ([GatewayCode::PartiallyApproved, GatewayCode::Pending, GatewayCode::Submitted, GatewayCode::Unknown, GatewayCode::Unrecognized] as $code) {
        expect($policy->isApproved(Result::Success, $code))->toBeFalse($code->name);
    }
});

it('requires result SUCCESS', function (Result $result) {
    expect((new ApprovalPolicy())->isApproved($result, GatewayCode::Approved))->toBeFalse();
})->with([Result::Failure, Result::Pending, Result::Unknown, Result::Error, Result::Unrecognized]);

it('approves SUCCESS with an approved code', function () {
    expect((new ApprovalPolicy())->isApproved(Result::Success, GatewayCode::ApprovedPendingSettlement))->toBeTrue();
});

it('does not prefix-match new APPROVED_* codes', function () {
    expect((new ApprovalPolicy())->isApproved(Result::Success, GatewayCode::fromGateway('APPROVED_SOMETHING_NEW')))->toBeFalse();
});

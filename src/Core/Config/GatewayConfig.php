<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Config;

use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * Immutable, validated gateway configuration.
 *
 * The host defaults to the environment's preset. Production has no preset yet,
 * so a Production config must set the host explicitly.
 */
final readonly class GatewayConfig
{
    public const DEFAULT_API_VERSION = 100;

    public const DEFAULT_TIMEOUT = 30;

    public string $host;

    /** @var non-empty-list<Currency> */
    public array $currencies;

    /**
     * @param list<Currency> $currencies the allow-list of currencies the merchant may use
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public string $merchantId,
        #[\SensitiveParameter]
        public string $apiPassword,
        public string $orderPrefix,
        public Environment $environment = Environment::Test,
        ?string $host = null,
        public int $apiVersion = self::DEFAULT_API_VERSION,
        public int $timeout = self::DEFAULT_TIMEOUT,
        array $currencies = [Currency::Mvr],
        public Currency $defaultCurrency = Currency::Mvr,
        public ApprovalPolicy $approvalPolicy = new ApprovalPolicy(),
        public bool $allowInsecureUrls = false,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $merchantId) !== 1) {
            throw new ConfigurationException('Merchant ID is required: 1-40 characters from A-Z, a-z, 0-9, "-" and "_"');
        }

        if ($apiPassword === '') {
            throw new ConfigurationException('API password is required');
        }

        if (preg_match('/^[A-Za-z0-9]{1,10}$/', $orderPrefix) !== 1) {
            throw new ConfigurationException('Order prefix (MIB_ORDER_PREFIX) is required: 1-10 characters from A-Z, a-z and 0-9');
        }

        if ($apiVersion < 1) {
            throw new ConfigurationException('API version must be a positive integer');
        }

        if ($timeout < 1) {
            throw new ConfigurationException('Timeout must be at least 1 second');
        }

        $this->host = self::resolveHost($host, $environment);
        $this->currencies = self::validateCurrencies($currencies, $defaultCurrency);
    }

    /**
     * Build from a plain array shaped like config/mib-global-pay.php.
     *
     * Keys: merchant_id, api_password, order_prefix (required); environment, host,
     * api_version, timeout, currencies (list or comma-separated string), currency,
     * approval.accept_approved_auto, allow_insecure_urls (optional).
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $approval = $config['approval'] ?? [];

        return new self(
            merchantId: self::stringValue($config, 'merchant_id') ?? '',
            apiPassword: self::stringValue($config, 'api_password') ?? '',
            orderPrefix: self::stringValue($config, 'order_prefix') ?? '',
            environment: Environment::fromConfig(self::stringValue($config, 'environment') ?? Environment::Test->value),
            host: self::stringValue($config, 'host'),
            apiVersion: self::intValue($config, 'api_version') ?? self::DEFAULT_API_VERSION,
            timeout: self::intValue($config, 'timeout') ?? self::DEFAULT_TIMEOUT,
            currencies: self::currencyList($config['currencies'] ?? null),
            defaultCurrency: self::currency(self::stringValue($config, 'currency') ?? Currency::Mvr->value),
            approvalPolicy: new ApprovalPolicy(
                acceptApprovedAuto: is_array($approval) && self::boolValue($approval, 'accept_approved_auto'),
            ),
            allowInsecureUrls: self::boolValue($config, 'allow_insecure_urls'),
        );
    }

    /** e.g. https://test-mib.mtf.gateway.mastercard.com/api/rest/version/100/ */
    public function apiBaseUrl(): string
    {
        return sprintf('https://%s/api/rest/version/%d/', $this->host, $this->apiVersion);
    }

    /** e.g. https://test-mib.mtf.gateway.mastercard.com/api/rest/version/100/merchant/TEST123/ */
    public function baseUrl(): string
    {
        return $this->apiBaseUrl() . 'merchant/' . rawurlencode($this->merchantId) . '/';
    }

    public function allowsCurrency(Currency $currency): bool
    {
        return in_array($currency, $this->currencies, true);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'merchantId' => $this->merchantId,
            'apiPassword' => '********',
            'orderPrefix' => $this->orderPrefix,
            'environment' => $this->environment,
            'host' => $this->host,
            'apiVersion' => $this->apiVersion,
            'timeout' => $this->timeout,
            'currencies' => $this->currencies,
            'defaultCurrency' => $this->defaultCurrency,
            'approvalPolicy' => $this->approvalPolicy,
            'allowInsecureUrls' => $this->allowInsecureUrls,
        ];
    }

    /**
     * @throws ConfigurationException
     */
    private static function resolveHost(?string $host, Environment $environment): string
    {
        $host = $host === null ? '' : trim($host);

        if ($host === '') {
            return $environment->presetHost()
                ?? throw new ConfigurationException(sprintf(
                    'The %s environment has no preset host; set the gateway host (MIB_GATEWAY_URL) explicitly',
                    $environment->value,
                ));
        }

        if (preg_match('#^http://#i', $host) === 1) {
            throw new ConfigurationException('The gateway host must use HTTPS');
        }

        $host = rtrim((string) preg_replace('#^https://#i', '', $host), '/');

        if (preg_match('/^(?=.{1,253}$)([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) !== 1) {
            throw new ConfigurationException("Invalid gateway host \"{$host}\"");
        }

        return strtolower($host);
    }

    /**
     * @param list<Currency> $currencies
     *
     * @return non-empty-list<Currency>
     *
     * @throws ConfigurationException
     */
    private static function validateCurrencies(array $currencies, Currency $default): array
    {
        $unique = [];
        foreach ($currencies as $currency) {
            if (! $currency->isRecognized()) {
                throw new ConfigurationException('The currency allow-list contains an unrecognized currency');
            }
            $unique[$currency->value] = $currency;
        }

        if ($unique === []) {
            throw new ConfigurationException('The currency allow-list must not be empty');
        }

        if (! isset($unique[$default->value])) {
            throw new ConfigurationException("The default currency {$default->value} is not in the currency allow-list");
        }

        return array_values($unique);
    }

    /**
     * @return list<Currency>
     *
     * @throws ConfigurationException
     */
    private static function currencyList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [Currency::Mvr];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            throw new ConfigurationException('currencies must be a list or a comma-separated string');
        }

        $list = [];
        foreach ($value as $code) {
            if ($code instanceof Currency) {
                $list[] = $code;
            } elseif (is_string($code) && trim($code) !== '') {
                $list[] = self::currency($code);
            } else {
                throw new ConfigurationException('currencies must contain currency codes');
            }
        }

        return $list;
    }

    /**
     * @throws ConfigurationException
     */
    private static function currency(string $code): Currency
    {
        try {
            return Currency::fromCode($code);
        } catch (UnsupportedCurrency $e) {
            throw new ConfigurationException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws ConfigurationException
     */
    private static function stringValue(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw new ConfigurationException("{$key} must be a string");
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws ConfigurationException
     */
    private static function intValue(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new ConfigurationException("{$key} must be an integer");
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws ConfigurationException
     */
    private static function boolValue(array $config, string $key): bool
    {
        $value = $config[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        $parsed = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        return $parsed ?? throw new ConfigurationException("{$key} must be a boolean");
    }
}

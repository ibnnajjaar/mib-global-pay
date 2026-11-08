<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Checkout;

use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Renders all the Checkout.js a page needs, so hosts write no JavaScript.
 *
 *     echo CheckoutJs::for($config, $session->sessionId)->page();
 *
 * $callbacks maps error, cancel, complete, timeout, beforeRedirect or afterRedirect to the name of
 * a global JavaScript function. All are optional. Without an error callback, the payer sees
 * $errorMessage. cancel works with the Payment Page only.
 */
final readonly class CheckoutJs
{
    private const array CALLBACKS = ['error', 'cancel', 'complete', 'timeout', 'beforeRedirect', 'afterRedirect'];

    private const string FUNCTION_NAME = '/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/';

    private const string DEFAULT_ERROR = 'mibGlobalPayError';

    /**
     * @param array<string, string|null> $callbacks
     *
     * @throws InvalidRequest
     */
    public function __construct(
        public string $sessionId,
        public string $scriptUrl,
        public CheckoutDisplay $display = CheckoutDisplay::Payment,
        public string $targetId = 'mib-global-pay-checkout',
        public array $callbacks = [],
        public string $errorMessage = 'The payment page could not be loaded. Please try again.',
    ) {
        if ($sessionId === '') {
            throw new InvalidRequest('Session ID must not be empty');
        }

        foreach ($callbacks as $callback => $function) {
            if (! in_array($callback, self::CALLBACKS, true)) {
                throw new InvalidRequest("Unknown Checkout.js callback \"{$callback}\"");
            }

            if ($function !== null && preg_match(self::FUNCTION_NAME, $function) !== 1) {
                throw new InvalidRequest("The {$callback} callback must name a global JavaScript function");
            }
        }

        if ($display !== CheckoutDisplay::Payment && ($callbacks['cancel'] ?? null) !== null) {
            throw new InvalidRequest('The cancel callback works with the Payment Page only, not the Embedded Page');
        }
    }

    /**
     * @param array<string, string|null> $callbacks
     *
     * @throws InvalidRequest
     */
    public static function for(
        GatewayConfig $config,
        string $sessionId,
        CheckoutDisplay $display = CheckoutDisplay::Payment,
        array $callbacks = [],
        string $targetId = 'mib-global-pay-checkout',
    ): self {
        return new self($sessionId, self::defaultScriptUrl($config), $display, $targetId, $callbacks);
    }

    public static function defaultScriptUrl(GatewayConfig $config): string
    {
        return 'https://' . $config->host . '/static/checkout/checkout.min.js';
    }

    /** A complete HTML page that starts the payment. */
    public function page(string $title = 'Payment', string $message = 'Loading the payment page…'): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::html($title) . '</title></head><body>'
            . $this->markup($message)
            . $this->scripts()
            . '</body></html>';
    }

    /** The element the payment page appears in, with a dialog and reopen button for a modal. */
    public function markup(string $message = 'Loading the payment page…'): string
    {
        $target = '<div id="' . self::html($this->targetId) . '">' . self::html($message) . '</div>';

        return $this->display === CheckoutDisplay::Modal
            ? '<button type="button" id="' . self::html($this->targetId) . '-open">Pay now</button>'
                . '<dialog id="' . self::html($this->targetId) . '-dialog">' . $target . '</dialog>'
            : $target;
    }

    /** The script tags. Place them after markup(). */
    public function scripts(): string
    {
        return '<script src="' . self::html($this->scriptUrl) . '"' . $this->callbackAttributes() . '></script>'
            . '<script>' . $this->script() . '</script>';
    }

    private function callbackAttributes(): string
    {
        $attributes = '';

        foreach (['error' => self::DEFAULT_ERROR] + array_filter($this->callbacks, fn (?string $name): bool => $name !== null) as $callback => $function) {
            $attributes .= ' data-' . $callback . '="' . self::html($this->callbacks[$callback] ?? $function) . '"';
        }

        return $attributes;
    }

    private function script(): string
    {
        $target = self::js($this->targetId);

        return 'function ' . self::DEFAULT_ERROR . '(error){console.error("MIB Checkout error",error);'
            . 'var element=document.getElementById(' . $target . ');'
            . 'if(element){element.textContent=' . self::js($this->errorMessage) . ';}}'
            . 'if(typeof Checkout==="undefined"){' . self::DEFAULT_ERROR . '("checkout.min.js did not load");}else{'
            . 'Checkout.configure({session:{id:' . self::js($this->sessionId) . '}});'
            . $this->start($target)
            . '}';
    }

    private function start(string $target): string
    {
        return match ($this->display) {
            CheckoutDisplay::Payment => 'Checkout.showPaymentPage();',
            CheckoutDisplay::Embedded => 'Checkout.showEmbeddedPage("#"+CSS.escape(' . $target . '));',
            CheckoutDisplay::Modal => 'var dialog=document.getElementById(' . self::js($this->targetId . '-dialog') . ');'
                . 'var openDialog=function(){if(!dialog.open){dialog.showModal();}};'
                . 'dialog.addEventListener("close",function(){sessionStorage.clear();});'
                . 'var opener=document.getElementById(' . self::js($this->targetId . '-open') . ');'
                . 'if(opener){opener.addEventListener("click",openDialog);}'
                . 'openDialog();'
                . 'Checkout.showEmbeddedPage("#"+CSS.escape(' . $target . '),openDialog);',
        };
    }

    private static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A JavaScript string that is safe inside a script tag. */
    private static function js(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}

<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Support\Traits\HasUrl;

class HasUrlTest extends TestCase
{
    use HasUrl;

    /**
     * Test that validateUrl does not throw exception for valid URLs when not required
     */
    public function test_validate_url_accepts_valid_url_when_not_required()
    {
        $valid_url = 'https://example.com';
        $field_name = 'test_url';

        // Should not throw any exception
        $this->validateUrl($valid_url, $field_name, false);

        // If we reach this point, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test that validateUrl does not throw exception for valid URLs when required
     */
    public function test_validate_url_accepts_valid_url_when_required()
    {
        $valid_url = 'https://example.com';
        $field_name = 'test_url';

        // Should not throw any exception
        $this->validateUrl($valid_url, $field_name, true);

        // If we reach this point, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test that validateUrl accepts null/empty URL when not required
     */
    public function test_validate_url_accepts_null_when_not_required()
    {
        $field_name = 'test_url';

        // Should not throw any exception for null
        $this->validateUrl(null, $field_name, false);

        // Should not throw any exception for empty string
        $this->validateUrl('', $field_name, false);

        // If we reach this point, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test that validateUrl throws exception for null URL when required
     */
    public function test_validate_url_throws_exception_for_null_when_required()
    {
        $field_name = 'test_url';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("$field_name is required");

        $this->validateUrl(null, $field_name, true);
    }

    /**
     * Test that validateUrl throws exception for empty URL when required
     */
    public function test_validate_url_throws_exception_for_empty_string_when_required()
    {
        $field_name = 'test_url';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("$field_name is required");

        $this->validateUrl('', $field_name, true);
    }

    /**
     * Test that validateUrl throws exception for invalid URL format
     */
    public function test_validate_url_throws_exception_for_invalid_url_format()
    {
        $invalid_url = 'not-a-valid-url';
        $field_name = 'test_url';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("$field_name must be a valid URL");

        $this->validateUrl($invalid_url, $field_name, false);
    }

    /**
     * Test that validateUrl throws exception for invalid URL format when required
     */
    public function test_validate_url_throws_exception_for_invalid_url_format_when_required()
    {
        $invalid_url = 'invalid.url.format';
        $field_name = 'test_url';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("$field_name must be a valid URL");

        $this->validateUrl($invalid_url, $field_name, true);
    }

    /**
     * Test validateUrl with various valid URL formats
     */
    public function test_validate_url_accepts_various_valid_url_formats()
    {
        $valid_urls = [
            'https://example.com',
            'http://example.com',
            'https://www.example.com',
            'https://subdomain.example.com',
            'https://example.com/path',
            'https://example.com/path?query=value',
            'https://example.com:8080',
            'https://example.com/path#fragment',
            'ftp://example.com',
            'https://127.0.0.1',
            'https://localhost:3000'
        ];

        $field_name = 'test_url';

        foreach ($valid_urls as $valid_url) {
            // Should not throw any exception
            $this->validateUrl($valid_url, $field_name, true);
        }

        // If we reach this point, all URLs were valid
        $this->assertTrue(true);
    }

    /**
     * Test validateUrl with various invalid URL formats
     */
    public function test_validate_url_rejects_various_invalid_url_formats()
    {
        $invalid_urls = [
            'not-a-url',
            'just-text',
            'www.example.com', // Missing protocol
            '//example.com',   // Missing protocol
            'example',
            'example.',
            '.example.com',
            'http://',
            'https://',
            ' https://example.com', // Leading space
            'https://example.com ', // Trailing space
        ];

        $field_name = 'test_url';

        foreach ($invalid_urls as $invalid_url) {
            try {
                $this->validateUrl($invalid_url, $field_name, false);
                $this->fail("Expected InvalidArgumentException for URL: $invalid_url");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("$field_name must be a valid URL", $e->getMessage());
            }
        }
    }

    /**
     * Test that validateUrl uses the correct field name in error messages
     */
    public function test_validate_url_uses_correct_field_name_in_error_messages()
    {
        $custom_field_name = 'return_url';

        // Test required field error message
        try {
            $this->validateUrl('', $custom_field_name, true);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals("$custom_field_name is required", $e->getMessage());
        }

        // Test invalid URL error message
        try {
            $this->validateUrl('invalid-url', $custom_field_name, false);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals("$custom_field_name must be a valid URL", $e->getMessage());
        }
    }

    /**
     * Test validateUrl with edge cases
     */
    public function test_validate_url_handles_edge_cases()
    {
        $field_name = 'test_url';

        // Test with very long valid URL
        $long_url = 'https://example.com/' . str_repeat('a', 1000);
        $this->validateUrl($long_url, $field_name, true);

        // Test with URL containing special characters
        $special_char_url = 'https://example.com/path?param=value&other=test%20data';
        $this->validateUrl($special_char_url, $field_name, true);

        // Test with international domain
        $international_url = 'https://xn--nxasmq6b.xn--j6w193g'; // Chinese domain
        $this->validateUrl($international_url, $field_name, true);

        // If we reach this point, all edge cases passed
        $this->assertTrue(true);
    }
}

<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\GraphQLException;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use PHPUnit\Framework\TestCase;

class GraphQLExceptionTest extends TestCase
{
    public function test_implements_client_aware_interface(): void
    {
        $exception = new GraphQLException('Test error', 'TEST_CODE');

        $this->assertInstanceOf(ClientAware::class, $exception);
    }

    public function test_implements_provides_extensions_interface(): void
    {
        $exception = new GraphQLException('Test error', 'TEST_CODE');

        $this->assertInstanceOf(ProvidesExtensions::class, $exception);
    }

    public function test_is_client_safe_returns_true(): void
    {
        $exception = new GraphQLException('Test error', 'TEST_CODE');

        $this->assertTrue($exception->isClientSafe());
    }

    public function test_stores_message(): void
    {
        $exception = new GraphQLException('Site not found', 'SITE_NOT_FOUND');

        $this->assertSame('Site not found', $exception->getMessage());
    }

    public function test_extensions_contain_custom_code(): void
    {
        $exception = new GraphQLException('Site not found', 'SITE_NOT_FOUND');

        $extensions = $exception->getExtensions();

        $this->assertSame('SITE_NOT_FOUND', $extensions['code']);
        $this->assertSame('custom', $extensions['category']);
    }

    public function test_extensions_with_site_suspended_code(): void
    {
        $exception = new GraphQLException('Site is suspended', 'SITE_SUSPENDED');

        $extensions = $exception->getExtensions();

        $this->assertSame('SITE_SUSPENDED', $extensions['code']);
        $this->assertSame('custom', $extensions['category']);
    }

    public function test_extensions_with_page_not_found_code(): void
    {
        $exception = new GraphQLException('Page not found', 'PAGE_NOT_FOUND');

        $extensions = $exception->getExtensions();

        $this->assertSame('PAGE_NOT_FOUND', $extensions['code']);
        $this->assertSame('custom', $extensions['category']);
    }

    public function test_defaults_to_internal_code(): void
    {
        $exception = new GraphQLException('Something went wrong');

        $extensions = $exception->getExtensions();

        $this->assertSame('INTERNAL', $extensions['code']);
    }

    public function test_get_error_code_returns_code_string(): void
    {
        $exception = new GraphQLException('Error', 'SITE_NOT_FOUND');

        $this->assertSame('SITE_NOT_FOUND', $exception->getErrorCode());
    }

    public function test_extends_exception(): void
    {
        $exception = new GraphQLException('Error', 'TEST');

        $this->assertInstanceOf(\Exception::class, $exception);
    }
}

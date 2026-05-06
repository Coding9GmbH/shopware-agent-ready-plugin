<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Hardening;

use Coding9\AgentReady\Http\CorsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CorsResolverTest extends TestCase
{
    public function testEmptyAllowlistReturnsNull(): void
    {
        $request = Request::create('/');
        $request->headers->set('Origin', 'https://anywhere.example');

        self::assertNull(CorsResolver::resolve($request, []));
    }

    public function testWildcardReturnsStar(): void
    {
        $request = Request::create('/');
        $request->headers->set('Origin', 'https://anywhere.example');

        self::assertSame('*', CorsResolver::resolve($request, ['*']));
    }

    public function testMatchingOriginIsEchoedBack(): void
    {
        $request = Request::create('/');
        $request->headers->set('Origin', 'https://claude.ai');

        self::assertSame(
            'https://claude.ai',
            CorsResolver::resolve($request, ['https://claude.ai', 'https://chatgpt.com']),
        );
    }

    public function testNonMatchingOriginReturnsNull(): void
    {
        $request = Request::create('/');
        $request->headers->set('Origin', 'https://attacker.example');

        self::assertNull(CorsResolver::resolve($request, ['https://claude.ai']));
    }

    public function testMissingOriginHeaderReturnsNull(): void
    {
        // Server-to-server callers don't send Origin; they don't need CORS either.
        $request = Request::create('/');

        self::assertNull(CorsResolver::resolve($request, ['https://claude.ai']));
    }
}

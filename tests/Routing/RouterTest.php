<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Routing;

use PhpAiGuard\Context;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Request;
use PhpAiGuard\Routing\Router;
use PhpAiGuard\Routing\Target;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router(
            ['cheap' => 'groq:openai/gpt-oss-20b', 'standard' => ['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash'], 'smart' => ['anthropic:claude-sonnet-5-5']],
            ['classify' => 'cheap', 'draft' => 'smart']
        );
    }

    /** @return list<string> */
    private static function chain(Router $router, Request $request): array
    {
        return array_map('strval', $router->resolve($request));
    }

    public function testResolution(): void
    {
        $router = $this->router();
        self::assertSame(['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash'], self::chain($router, Request::fromPrompt('x')), 'default tier is "standard"');
        self::assertSame(['groq:openai/gpt-oss-20b'], self::chain($router, Request::fromOptions('x', ['tier' => 'cheap'])));
        self::assertSame(['anthropic:claude-sonnet-5-5'], self::chain($router, Request::fromOptions('x', [], new Context(null, null, 'draft'))));
        self::assertSame(['ollama:llama3.2:3b'], self::chain($router, Request::fromOptions('x', ['model' => 'ollama:llama3.2:3b'])));
        self::assertSame(['groq:openai/gpt-oss-20b'], self::chain($router, Request::fromOptions('x', ['tier' => 'cheap', 'feature' => 'draft'])), 'explicit tier wins over feature');
    }

    public function testTargetParsingKeepsColonsInModel(): void
    {
        $target = Target::parse('ollama:qwen3:14b');
        self::assertSame('ollama', $target->provider);
        self::assertSame('qwen3:14b', $target->model);
    }

    public function testErrors(): void
    {
        try {
            $this->router()->resolve(Request::fromOptions('x', ['tier' => 'turbo']));
            self::fail('Expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('Unknown tier "turbo"', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        new Router(['cheap' => 'groq:x'], ['classify' => 'missing']);
    }
}

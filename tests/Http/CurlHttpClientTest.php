<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Http;

use PhpAiGuard\Exception\ProviderNetworkException;
use PhpAiGuard\Exception\ProviderTimeoutException;
use PhpAiGuard\Http\CurlHttpClient;
use PhpAiGuard\Http\HttpRequest;
use PhpAiGuard\Tests\Storage\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real cURL transport against PHP's built-in web server on
 * localhost (no internet access needed).
 *
 * @requires extension curl
 */
final class CurlHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $dir = TempDir::create();
        file_put_contents($dir . '/index.php', <<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] === '/slow') { sleep(3); }
            http_response_code($_SERVER['REQUEST_URI'] === '/error' ? 503 : 200);
            header('Content-Type: application/json');
            header('Retry-After: 4');
            echo json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'auth' => $_SERVER['HTTP_X_API_KEY'] ?? null,
                'body' => file_get_contents('php://input'),
            ]);
            PHP);
        for ($try = 0; $try < 5 && self::$server === null; $try++) {
            $port = random_int(20000, 60000);
            $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                continue;
            }
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port);
                if ($socket !== false) {
                    fclose($socket);
                    self::$server = $process;
                    self::$port = $port;
                    break;
                }
                usleep(100000);
            }
            if (self::$server === null) {
                proc_terminate($process);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            self::markTestSkipped('Could not start the PHP built-in web server.');
        }
    }

    public function testSendsRequestAndParsesResponse(): void
    {
        $response = (new CurlHttpClient())->send(new HttpRequest('POST', $this->url('/'), ['x-api-key' => 'secret', 'Content-Type' => 'application/json'], '{"a":1}', 5.0));

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(['method' => 'POST', 'auth' => 'secret', 'body' => '{"a":1}'], $response->json());
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        $response = (new CurlHttpClient())->send(new HttpRequest('POST', $this->url('/error'), [], '{}', 5.0));

        self::assertSame(503, $response->status);
        self::assertSame('4', $response->header('retry-after'));
    }

    public function testTimeout(): void
    {
        $this->expectException(ProviderTimeoutException::class);
        (new CurlHttpClient())->send(new HttpRequest('POST', $this->url('/slow'), [], '{}', 0.5));
    }

    public function testConnectionFailure(): void
    {
        $this->expectException(ProviderNetworkException::class);
        (new CurlHttpClient())->send(new HttpRequest('POST', 'http://127.0.0.1:1/', [], '{}', 2.0, 1.0));
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }
}

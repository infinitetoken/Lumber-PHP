<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InfiniteToken\Lumber\Laravel\LumberChannel;
use InfiniteToken\Lumber\Laravel\LumberServiceProvider;
use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Tests\Support\ContractFiles;
use InfiniteToken\Lumber\Tests\Support\Server;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;

/**
 * The service provider and `lumber` channel in a Laravel app, sending to a stand-in for Lumber's API.
 */
final class LaravelTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
        parent::setUp();
        Server::clear();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LumberServiceProvider::class];
    }

    /**
     * The settings .env gives config/lumber.php.
     *
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('lumber.api_key', 'laravel-token.' . base64_encode($this->key));
        $app['config']->set('lumber.endpoint', Server::url());
    }

    /**
     * @param Application $app
     */
    protected function warningsOnly($app): void
    {
        $app['config']->set('lumber.level', 'warning');
    }

    /**
     * @param Application $app
     */
    protected function anAppChannel($app): void
    {
        $app['config']->set('logging.channels.lumber', ['driver' => 'single', 'path' => '/dev/null']);
    }

    public function testAddsTheLumberChannelAndOneClient(): void
    {
        self::assertSame(['driver' => 'custom', 'via' => LumberChannel::class], config('logging.channels.lumber'));
        self::assertSame($this->app->make(Lumber::class), $this->app->make(Lumber::class));
    }

    #[DefineEnvironment('anAppChannel')]
    public function testLeavesAChannelTheAppDefinesAlone(): void
    {
        self::assertSame('single', config('logging.channels.lumber.driver'));
    }

    public function testSendsTheChannelsLogsWhenTheRequestEnds(): void
    {
        Log::channel('lumber')->info('Hello {name}', ['name' => 'Zoë', 'request_id' => 'req-123']);
        self::assertSame([], Server::requests(), 'nothing is sent while the request runs');

        $this->app->terminate();

        [$payload] = $this->sentPayloads();
        self::assertSame('Hello Zoë', $payload->message);
        self::assertSame('req-123', $payload->requestId);
        self::assertSame('Zoë', $payload->metadata->name);
        self::assertSame('testing', $payload->context->env, "Laravel's environment, as LUMBER_ENVIRONMENT isn't set");
        self::assertSame('laravel', $payload->context->channel);
        self::assertSame(getmypid(), $payload->context->pid);
    }

    public function testSendsAQueueWorkersLogsAfterEachJob(): void
    {
        Log::channel('lumber')->error('Job failed');
        Event::dispatch(new Looping('redis', 'default'));

        self::assertSame(['Job failed'], array_map(static fn(\stdClass $payload): mixed => $payload->message, $this->sentPayloads()));
    }

    public function testSendsLaravelsContext(): void
    {
        Context::add('trace_id', 'trace-abc');
        Context::add('tenant', 'acme');
        Log::channel('lumber')->warning('Slow query');
        $this->app->make(Lumber::class)->flush();

        [$payload] = $this->sentPayloads();
        self::assertSame('trace-abc', $payload->traceId);
        self::assertSame('acme', $payload->metadata->tenant);
    }

    #[DefineEnvironment('warningsOnly')]
    public function testSendsOnlyFromLumberLevel(): void
    {
        Log::channel('lumber')->info('Below');
        Log::channel('lumber')->warning('At');
        $this->app->terminate();

        self::assertSame(['At'], array_map(static fn(\stdClass $payload): mixed => $payload->message, $this->sentPayloads()));
    }

    /**
     * @return list<\stdClass>
     */
    private function sentPayloads(): array
    {
        return array_map(function (array $request): \stdClass {
            self::assertSame('Bearer laravel-token', $request['headers']['authorization']);
            $body = json_decode($request['body'], false, 512, \JSON_THROW_ON_ERROR);
            ContractFiles::assertConforms('LogIngestBody', $body);
            $payload = ContractFiles::decrypt($body->encrypted, $this->key);
            self::assertInstanceOf(\stdClass::class, $payload);
            ContractFiles::assertConforms('LogPayload', $payload);

            return $payload;
        }, Server::requests());
    }
}

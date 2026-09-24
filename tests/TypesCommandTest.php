<?php

namespace Flyo\Laravel\Tests;

use Flyo\Generator\ExitCode;
use Flyo\Generator\Source;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Psr\Http\Message\RequestInterface;
use stdClass;

/**
 * The generator itself is tested in flyo/nitro-php, these tests cover what the command adds: the
 * token of the configuration, the application namespace and the exit code of the generator.
 */
class TypesCommandTest extends TestCase
{
    /**
     * @var array<int, array{request: RequestInterface}>
     */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(app_path('Flyo'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Flyo'));

        parent::tearDown();
    }

    private function fakeApi(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));

        $this->app->instance(Source::class, new Source(new Client(['handler' => $stack])));
    }

    private static function schemas(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Schemas', 'version' => 'edge'],
            'paths' => new stdClass,
            'components' => [
                'schemas' => [
                    'BlockHero' => [
                        'type' => 'object',
                        'x-schema-type' => 'block',
                        'properties' => [
                            'identifier' => ['type' => 'string', 'enum' => ['hero']],
                            'component' => ['type' => 'string', 'enum' => ['Hero']],
                            'content' => [
                                'type' => 'object',
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
    }

    public function test_the_classes_are_generated_into_the_application_namespace(): void
    {
        $this->fakeApi(self::schemas());

        $status = Artisan::call('flyo:types');

        $this->assertSame(ExitCode::OK, $status);
        $this->assertStringContainsString('wrote: Blocks/BlockHero.php', Artisan::output());

        $class = (string) file_get_contents(app_path('Flyo/Blocks/BlockHero.php'));

        $this->assertStringContainsString('namespace App\Flyo\Blocks;', $class);
        $this->assertStringContainsString('class BlockHero extends \Flyo\Model\Block', $class);
    }

    public function test_the_schemas_are_requested_with_the_token_of_the_configuration(): void
    {
        $this->fakeApi(self::schemas());

        Artisan::call('flyo:types');

        $this->assertCount(1, $this->requests);

        $uri = $this->requests[0]['request']->getUri();
        parse_str($uri->getQuery(), $query);

        $this->assertSame('api.flyo.cloud', $uri->getHost());
        $this->assertSame('/nitro/v1/openapi/schemas', $uri->getPath());
        $this->assertSame('test-token', $query['token'] ?? null);
        $this->assertStringNotContainsString('test-token', Artisan::output());
    }

    public function test_the_token_of_the_configuration_wins_over_the_environment_of_the_shell(): void
    {
        $this->fakeApi(self::schemas());

        putenv('FLYO_TOKEN=token-of-the-shell');

        try {
            Artisan::call('flyo:types');
        } finally {
            putenv('FLYO_TOKEN');
        }

        parse_str($this->requests[0]['request']->getUri()->getQuery(), $query);

        $this->assertSame('test-token', $query['token'] ?? null);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $this->fakeApi(self::schemas());

        $status = Artisan::call('flyo:types', ['--dry-run' => true]);

        $this->assertSame(ExitCode::OK, $status);
        $this->assertStringContainsString('would write: Blocks/BlockHero.php', Artisan::output());
        $this->assertDirectoryDoesNotExist(app_path('Flyo'));
    }

    public function test_the_check_exits_with_the_drift_code_when_the_classes_are_out_of_date(): void
    {
        $this->fakeApi(self::schemas());

        $status = Artisan::call('flyo:types', ['--check' => true]);

        $this->assertSame(ExitCode::DRIFT, $status);
        $this->assertStringContainsString('the target is out of date', Artisan::output());
        $this->assertDirectoryDoesNotExist(app_path('Flyo'));
    }

    public function test_the_check_passes_once_the_classes_are_generated(): void
    {
        $this->fakeApi(self::schemas(), self::schemas());

        Artisan::call('flyo:types');
        $status = Artisan::call('flyo:types', ['--check' => true]);

        $this->assertSame(ExitCode::OK, $status);
    }

    public function test_the_command_fails_without_a_request_when_no_token_is_configured(): void
    {
        $this->fakeApi();
        $this->app['config']->set('flyo.token', null);

        $status = Artisan::call('flyo:types');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('The Flyo token is not set.', Artisan::output());
        $this->assertCount(0, $this->requests);
    }

    public function test_a_rejected_token_is_reported_with_the_exit_code_of_the_generator(): void
    {
        $this->fakeApi(new Response(401));

        $status = Artisan::call('flyo:types');
        $output = Artisan::output();

        $this->assertSame(ExitCode::FETCH, $status);
        $this->assertStringContainsString('HTTP 401', $output);
        $this->assertStringContainsString('token=***', $output);
        $this->assertStringNotContainsString('test-token', $output);
    }
}

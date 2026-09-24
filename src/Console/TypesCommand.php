<?php

namespace Flyo\Laravel\Console;

use Flyo\Generator\Cli;
use Flyo\Generator\Source;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates the typed schema classes of the Flyo integration into the application namespace,
 * `App\Flyo\Blocks`, `App\Flyo\Containers` and `App\Flyo\Entities` in `app/Flyo` for a standard
 * Laravel project, see the "Typed Schemas" section of the flyo/nitro-php readme.
 *
 * The generator of flyo/nitro-php runs in this process and gets the token of `config/flyo.php`
 * through its environment instead of a `--token` argument. Composer does not read the `.env` of
 * the application, and this way the token never shows up in the process list, a shell history
 * or a composer script.
 */
class TypesCommand extends Command
{
    /**
     * The typed schemas only exist on the authenticated endpoint, the public one has none.
     */
    public const SCHEMA_URL = 'https://api.flyo.cloud/nitro/v1/openapi/schemas';

    protected $signature = 'flyo:types
        {--check : Write nothing and exit with code 6 when the generated classes are out of date}
        {--dry-run : Report what would change without writing any file}';

    protected $description = 'Generate the typed Flyo schema classes (blocks, containers, entities) into app/Flyo';

    public function handle(ConfigRepository $configRepository): int
    {
        $token = $configRepository->get('flyo.token');

        if (empty($token)) {
            $this->error('The Flyo token is not set. Please set the FLYO_TOKEN environment variable or add it to the config/flyo.php file.');

            return self::FAILURE;
        }

        $arguments = [
            'flyo-generate-types',
            self::SCHEMA_URL,
            $this->laravel->getNamespace().'Flyo',
            app_path('Flyo'),
        ];

        foreach (['check', 'dry-run'] as $flag) {
            if ($this->option($flag)) {
                $arguments[] = '--'.$flag;
            }
        }

        // The process environment is handed on as it is, so the proxy variables the generator
        // reads keep working, only the token is taken from the configuration.
        $env = [...getenv(), 'FLYO_TOKEN' => (string) $token];

        // Binding a Source replaces the http client of the generator, the tests use it to answer
        // without a network. It is never built from the container otherwise, as a Guzzle client
        // bound by the application could log the url, which carries the token.
        $source = $this->laravel->bound(Source::class) ? $this->laravel->make(Source::class) : null;

        // The generator writes to streams instead of the command output, both are buffered and
        // relayed, so the output also reaches Artisan::call() and the tests.
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        if (! $stdout || ! $stderr) {
            throw new RuntimeException('Could not open a memory stream for the output of the generator.');
        }

        try {
            $status = Cli::main($arguments, $env, $stdout, $stderr, $source);

            // Warnings and errors are written before the report, relaying stderr first keeps
            // the order in which the generator printed them.
            $this->output->getErrorStyle()->write((string) stream_get_contents($stderr, null, 0), false, OutputInterface::OUTPUT_RAW);
            $this->output->write((string) stream_get_contents($stdout, null, 0), false, OutputInterface::OUTPUT_RAW);
        } finally {
            fclose($stdout);
            fclose($stderr);
        }

        return $status;
    }
}

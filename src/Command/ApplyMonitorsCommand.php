<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use Nowo\UptimeMonitorBundle\Apply\ApplyError;
use Nowo\UptimeMonitorBundle\Apply\ApplyFailedException;
use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\ExternalPushTokens;
use Nowo\UptimeMonitorBundle\Apply\MonitorApplier;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileSpec;
use Nowo\UptimeMonitorBundle\Apply\PushTokenFileReader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const STDIN;

/**
 * Console command `nowo:uptime:apply-monitors` (since 1.18): applies push monitors defined as code.
 *
 * Idempotent (each entry has a stable key), all or nothing (one transaction; any refusal applies nothing), and never
 * prints a secret: the stdout JSON lists created / updated / unchanged / pruned entries by key, and every refusal is a
 * JSON pointer plus a code. Push tokens arrive only through `--token-file` (a 0600 file, or `-` for stdin), never on
 * the command line, and only their SHA-256 is stored.
 */
#[AsCommand(
    name: 'nowo:uptime:apply-monitors',
    description: 'Create, update or prune push monitors from a monitors file (monitors as code)',
)]
final class ApplyMonitorsCommand extends Command
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Creates the command.
     *
     * @param LoggerInterface $logger Receives start, done (counts), refusal (codes) and failure (class) records
     */
    public function __construct(
        private readonly MonitorFileParser $parser,
        private readonly PushTokenFileReader $tokenReader,
        private readonly MonitorApplier $applier,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'The monitors file (JSON, schema apply-monitors.v1.json)')
            ->addOption('token-file', null, InputOption::VALUE_REQUIRED, 'JSON map {"<entry key>": "<token>"}: a 0600 file, or - for stdin')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Apply (and prune) only this tenant of the file (a UUID)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and print the plan; write nothing')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete the file monitors of the listed tenants that the file no longer names')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json)', 'json')
            ->setHelp(
                <<<'HELP'
Applies the push monitors of a monitors file: each entry is created, updated or left unchanged, matched by its key.
Monitors created in the UI are never touched. With <comment>--prune</comment>, file monitors of the listed tenants that
the file no longer names are deleted (without it they are only listed as prune_candidates).

<info>php %command.full_name% --file=monitors.json --dry-run</info>
<info>php %command.full_name% --file=monitors.json --token-file=- < tokens.json</info>

Tokens are never accepted on the command line: --token-file names a regular file of mode 0600 (or 0400) owned by
you or root, or - for a non-interactive stdin, holding {"<entry key>": "<64+ lowercase hex characters>"}. Only the
SHA-256 is stored; a different token replaces (rotates) the stored one.

stdout is always one JSON document; exit codes: 0 ok, 1 failure (rolled back), 2 usage, 3 invalid file,
4 token input refused, 5 tenant not found, 6 conflict with the database or the tenant limits. A run that was
applied but whose MonitorsAppliedEvent listener failed exits 0 with an "event_listener_failed" entry in "warnings".
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $prune  = (bool) $input->getOption('prune');
        $spec   = null;

        try {
            [$file, $tokenFile, $tenant] = $this->options($input);
            [$full, $tokens]             = $this->readInputs($input, $file, $tokenFile, $tenant);
            $spec                        = $tenant !== null ? $full->onlyTenant($tenant) : $full;

            $this->logger->info('Uptime monitors apply started: {entries} entries', $this->context($spec, $dryRun, $prune) + ['entries' => $spec->count()]);
            $result = $this->applier->apply($spec, $tokens, $dryRun, $prune);
        } catch (ApplyRefusedException $refused) {
            $codes = array_values(array_unique(array_map(static fn (ApplyError $error): string => $error->code, $refused->getErrors())));
            $this->logger->warning('Uptime monitors apply refused (exit {exit_code})', ['bundle' => 'NowoUptimeMonitorBundle', 'exit_code' => $refused->getExitCode(), 'error_codes' => $codes]);

            return $this->fail($output, $spec, $dryRun, $refused->getErrors(), $refused->getExitCode());
        } catch (Throwable $failure) {
            $class = $failure instanceof ApplyFailedException ? $failure->getCauseClass() : $failure::class;
            $this->logger->error('Uptime monitors apply failed: {error}', ['bundle' => 'NowoUptimeMonitorBundle', 'error' => $class]);

            return $this->fail($output, $spec, $dryRun, [new ApplyError('', 'apply_failed', 1)], Command::FAILURE);
        }

        $this->logger->info('Uptime monitors applied', $this->context($spec, $dryRun, $prune) + [
            'created'        => count($result->created),
            'updated'        => count($result->updated),
            'unchanged'      => count($result->unchanged),
            'pruned'         => count($result->pruned),
            'tokens_changed' => $result->tokensChanged(),
        ]);
        foreach ($result->warnings as $warning) {
            $this->logger->warning('Uptime monitors applied with a warning: {code} ({error})', ['bundle' => 'NowoUptimeMonitorBundle', 'tenant' => $warning['tenant'], 'code' => $warning['code'], 'error' => $warning['error']]);
        }
        $this->write($output, $result->toArray($spec->sha256));
        $this->human($output, sprintf(
            '%s: %d created, %d updated, %d unchanged, %d pruned.%s',
            $dryRun ? 'Dry run' : 'Applied',
            count($result->created),
            count($result->updated),
            count($result->unchanged),
            count($result->pruned),
            $result->warnings !== [] ? sprintf(' %d warning(s): see "warnings".', count($result->warnings)) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Runs the command; an option Symfony cannot bind (an unknown option such as `--token`, an argument) is a usage
     * error (exit 2) reported as the same JSON document, and its message (which may quote the input) is not printed.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleExceptionInterface) {
            $this->logger->warning('Uptime monitors apply refused (exit {exit_code})', ['bundle' => 'NowoUptimeMonitorBundle', 'exit_code' => ApplyError::EXIT_USAGE, 'error_codes' => ['invalid_usage']]);

            return $this->fail($output, null, false, [new ApplyError('', 'invalid_usage', ApplyError::EXIT_USAGE)], ApplyError::EXIT_USAGE);
        }
    }

    /**
     * Reads the monitors file and the token input. Both are read even when the other is refused, so that one run
     * reports every file and token error (the exit code is the lowest class, §4.7).
     *
     * @throws ApplyRefusedException exit 2–4
     *
     * @return array{0: MonitorFileSpec, 1: ExternalPushTokens}
     */
    private function readInputs(InputInterface $input, string $file, ?string $tokenFile, ?string $tenant): array
    {
        $errors = [];
        $full   = null;
        try {
            $full = $this->parser->parseFile($file);
            if ($tenant !== null && !$full->hasTenant($tenant)) {
                $errors[] = new ApplyError('--tenant', 'tenant_not_in_file', ApplyError::EXIT_USAGE);
            }
        } catch (ApplyRefusedException $refused) {
            $errors = $refused->getErrors();
        }

        $tokens = new ExternalPushTokens();
        if ($tokenFile !== null) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            try {
                $tokens = $this->tokenReader->read($tokenFile, $full, $stream ?? STDIN);
            } catch (ApplyRefusedException $refused) {
                $errors = [...$errors, ...$refused->getErrors()];
            }
        }
        if ($errors !== [] || !$full instanceof MonitorFileSpec) {
            throw new ApplyRefusedException($errors);
        }

        return [$full, $tokens];
    }

    /**
     * @throws ApplyRefusedException exit 2
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function options(InputInterface $input): array
    {
        $file      = $input->getOption('file');
        $tokenFile = $input->getOption('token-file');
        $tenant    = $input->getOption('tenant');
        $errors    = [];
        if (!is_string($file) || $file === '') {
            $errors[] = new ApplyError('--file', 'missing_option', ApplyError::EXIT_USAGE);
        }
        if ($tokenFile !== null && (!is_string($tokenFile) || $tokenFile === '')) {
            $errors[] = new ApplyError('--token-file', 'invalid_option', ApplyError::EXIT_USAGE);
        }
        if ($tenant !== null && (!is_string($tenant) || preg_match(self::UUID, $tenant) !== 1)) {
            $errors[] = new ApplyError('--tenant', 'invalid_option', ApplyError::EXIT_USAGE);
        }
        if ($input->getOption('format') !== 'json') {
            $errors[] = new ApplyError('--format', 'invalid_option', ApplyError::EXIT_USAGE);
        }
        if ($errors !== []) {
            throw new ApplyRefusedException($errors);
        }

        /* @var array{0: string, 1: string|null, 2: string|null} */
        return [$file, $tokenFile, $tenant];
    }

    /**
     * @param list<ApplyError> $errors
     */
    private function fail(OutputInterface $output, ?MonitorFileSpec $spec, bool $dryRun, array $errors, int $exitCode): int
    {
        $this->write($output, [
            'ok'               => false,
            'dry_run'          => $dryRun,
            'file_sha256'      => $spec?->sha256,
            'tenants'          => [],
            'created'          => [],
            'updated'          => [],
            'unchanged'        => [],
            'pruned'           => [],
            'prune_candidates' => [],
            'errors'           => array_map(static fn (ApplyError $error): array => $error->toArray(), $errors),
        ]);
        $this->human($output, sprintf('Nothing applied (exit %d): %d error(s).', $exitCode, count($errors)));

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function write(OutputInterface $output, array $document): void
    {
        $output->writeln(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    /**
     * One line for humans on stderr (only when stderr is separate, so stdout stays one JSON document).
     */
    private function human(OutputInterface $output, string $line): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(MonitorFileSpec $spec, bool $dryRun, bool $prune): array
    {
        return [
            'bundle'      => 'NowoUptimeMonitorBundle',
            'tenants'     => array_keys($spec->tenants),
            'dry_run'     => $dryRun,
            'prune'       => $prune,
            'file_sha256' => $spec->sha256,
        ];
    }
}

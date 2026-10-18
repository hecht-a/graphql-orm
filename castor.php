<?php

declare(strict_types=1);

// Keeps the current working directory behaviour, and silences the Castor 1.8 deprecation asking to choose it.
defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\capture;
use function Castor\context;
use function Castor\io;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\run;

/** The PHP versions the bundle supports, each one tested in a container of .docker/qa. */
const QA_PHP_VERSIONS = ['8.4', '8.5'];

/** The compose project of the GraphQL servers of the integration tests. */
const INTEGRATION_PROJECT = 'graphql-orm-it';

/** The endpoint of each server, reached by the tests from the network of the compose project. */
const INTEGRATION_ENDPOINTS = [
    'dab' => ['GRAPHQL_ORM_DAB_ENDPOINT', 'http://dab:5000/graphql'],
    'hasura' => ['GRAPHQL_ORM_HASURA_ENDPOINT', 'http://hasura:8080/v1/graphql'],
    'postgraphile' => ['GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT', 'http://postgraphile:5000/graphql'],
];

/** The version of Infection installed by qa:infection. */
const INFECTION_VERSION = '^0.35';

/** Seconds the servers are given to answer a query once started. */
const INTEGRATION_READY_TIMEOUT = 180;

/**
 * Polls each endpoint given as argument with a GraphQL query until it answers, or the timeout.
 */
const INTEGRATION_WAIT_SCRIPT = <<<'PHP'
    $deadline = time() + %TIMEOUT%;
    foreach (array_slice($argv, 1) as $endpoint) {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => '{"query":"{ __typename }"}', 'timeout' => 5, 'ignore_errors' => true]]);
        while (!str_contains((string) @file_get_contents($endpoint, false, $context), '__typename')) {
            if (time() > $deadline) {
                fwrite(STDERR, "$endpoint does not answer\n");
                exit(1);
            }
            sleep(2);
        }
        echo "$endpoint answers\n";
    }
    PHP;

#[AsTask('cs:check', namespace: 'qa', description: 'Check for coding standards without fixing them')]
function qa_cs_check()
{
    php_cs_fixer(['fix', '--config', __DIR__ . '/.php-cs-fixer.php', '--dry-run', '--diff'], '3.92.3', [
        'kubawerlos/php-cs-fixer-custom-fixers' => '^3.21',
    ]);
}

#[AsTask('cs:fix', namespace: 'qa', description: 'Fix all coding standards', aliases: ['cs'])]
function qa_cs_fix()
{
    php_cs_fixer(['fix', '--config', __DIR__ . '/.php-cs-fixer.php', '-v'], '3.92.3', [
        'kubawerlos/php-cs-fixer-custom-fixers' => '^3.21',
    ]);
}

#[AsTask('phpstan', namespace: 'qa', description: 'Run PHPStan for static analysis', aliases: ['phpstan'])]
function qa_phpstan(bool $generateBaseline = false)
{
    // --debug runs the analysis in a single process: PHPStan starts its parallel workers with PHP_BINARY, which is empty
    // in the PHP embedded in the Castor binary, so they cannot start.
    $params = ['analyse', '--configuration', __DIR__ . '/phpstan.neon', '--memory-limit=-1', '--debug', '--no-progress'];
    if ($generateBaseline) {
        $params[] = '--generate-baseline';
    }

    phpstan($params, '2.2.16');
}

#[AsTask('tests', namespace: 'qa', description: 'Run the unit tests with every supported PHP version, in containers', aliases: ['tests'])]
function qa_tests(
    #[AsOption(description: 'One PHP version, 8.4 or 8.5; every supported one when omitted')]
    ?string $php = null,
    #[AsOption(description: 'Only the tests matching this PHPUnit filter')]
    ?string $filter = null,
): int {
    $failed = [];

    foreach ($php !== null ? [$php] : QA_PHP_VERSIONS as $version) {
        io()->section(sprintf('Unit tests with PHP %s', $version));

        if (!qa_run($version, ['vendor/bin/phpunit', ...($filter !== null ? ['--filter', $filter] : [])])) {
            $failed[] = $version;
        }
    }

    if ($failed !== []) {
        io()->error(sprintf('The unit tests fail with PHP %s.', implode(' and ', $failed)));

        return 1;
    }

    return 0;
}

#[AsTask('integration', namespace: 'qa', description: 'Run the integration tests against the GraphQL servers of tests/Integration, in containers')]
function qa_integration(
    #[AsOption(description: 'The PHP version of the tests')]
    string $php = '8.5',
    #[AsOption(description: 'Leave the servers running afterwards')]
    bool $keep = false,
): int {
    // The Hasura engine does not start emulated on Apple Silicon: the CI runs its tests.
    $servers = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? ['dab', 'postgraphile'] : array_keys(INTEGRATION_ENDPOINTS);

    if (!in_array('hasura', $servers, true)) {
        io()->note('Hasura is left out on an ARM host, where its engine does not start emulated: the CI runs its tests.');
    }

    // No port published: the tests reach the servers on the network of the project, whatever the host already uses.
    $compose = ['docker', 'compose', '-p', INTEGRATION_PROJECT, '-f', __DIR__ . '/tests/Integration/compose.yaml'];
    $ports = context()->withEnvironment(['GRAPHQL_ORM_DAB_PORT' => '0', 'GRAPHQL_ORM_HASURA_PORT' => '0', 'GRAPHQL_ORM_POSTGRAPHILE_PORT' => '0']);
    $network = INTEGRATION_PROJECT . '_default';
    $environment = [];

    foreach ($servers as $server) {
        [$variable, $endpoint] = INTEGRATION_ENDPOINTS[$server];
        $environment[$variable] = $endpoint;
    }

    io()->section('Starting the GraphQL servers');
    run([...$compose, 'up', '--detach', 'database', ...$servers], context: $ports->withTimeout(null));

    try {
        io()->section('Waiting for the servers to answer');

        if (!qa_run($php, ['php', '-r', INTEGRATION_WAIT_SCRIPT, ...array_values($environment)], $network)) {
            io()->error('The GraphQL servers do not answer: see docker compose -p ' . INTEGRATION_PROJECT . ' logs.');

            return 1;
        }

        io()->section(sprintf('Integration tests with PHP %s', $php));

        return qa_run($php, ['vendor/bin/phpunit', '--group', 'integration'], $network, $environment) ? 0 : 1;
    } finally {
        if (!$keep) {
            run([...$compose, 'down'], context: $ports->withTimeout(null));
        }
    }
}

#[AsTask('infection', namespace: 'qa', description: 'Run the mutation tests with Infection: the changes of src/ that no unit test notices')]
function qa_infection(
    #[AsOption(description: 'The PHP version of the tests')]
    string $php = '8.5',
    #[AsOption(description: 'Only the mutations of the files matching this filter, e.g. src/Query')]
    ?string $filter = null,
): int {
    // Installed with the Composer of the tests, apart from the dependencies of the bundle: the PHP of Castor lacks ext-dom.
    $tool = '.castor/vendor/.tools/infection';
    $install = ['composer', 'update', '--working-dir=' . $tool, '--no-interaction', '--no-progress'];
    $command = [
        'php', $tool . '/vendor/bin/infection',
        '--threads=max',
        '--no-progress',
        // pcov is installed but disabled, as it slows the other tests down
        '--initial-tests-php-options=-dpcov.enabled=1',
        ...($filter !== null ? ['--filter=' . $filter] : []),
    ];
    // The CI runs it with the PHP of the runner, pcov installed by setup-php.
    $ci = getenv('CI') === 'true';
    $execute = static fn (array $command): bool => $ci
        ? run($command, context: context()->withTimeout(null)->withAllowFailure())->isSuccessful()
        : qa_run($php, $command);

    if (!is_file(__DIR__ . '/' . $tool . '/vendor/bin/infection')) {
        if (!is_dir(__DIR__ . '/' . $tool)) {
            mkdir(__DIR__ . '/' . $tool, 0o755, true);
        }

        file_put_contents(__DIR__ . '/' . $tool . '/composer.json', json_encode([
            'require' => ['infection/infection' => INFECTION_VERSION],
            'config' => ['allow-plugins' => ['infection/extension-installer' => true]],
        ], \JSON_PRETTY_PRINT));

        if (!$execute($install)) {
            return 1;
        }
    }

    return $execute($command) ? 0 : 1;
}

#[AsTask('all', namespace: 'qa', description: 'Run every quality check: coding standards, PHPStan, unit tests with each PHP version, integration tests')]
function qa_all(): int
{
    $failed = [];

    foreach (['coding standards' => qa_cs_check(...), 'PHPStan' => qa_phpstan(...), 'unit tests' => qa_tests(...), 'integration tests' => qa_integration(...)] as $check => $task) {
        try {
            if (($task() ?? 0) !== 0) {
                $failed[] = $check;
            }
        } catch (Throwable) {
            $failed[] = $check;
        }
    }

    if ($failed !== []) {
        io()->error('Failed: ' . implode(', ', $failed) . '.');

        return 1;
    }

    io()->success('Every quality check passes.');

    return 0;
}

/**
 * Runs a command in the container of a PHP version, the project mounted as the working directory and owned by the user
 * of the host; the image is built on first use. Installs the dependencies first when they are missing.
 *
 * @param list<string>          $command
 * @param array<string, string> $environment
 */
function qa_run(string $php, array $command, ?string $network = null, array $environment = []): bool
{
    // The image is built again whenever its Dockerfile changes.
    $image = 'graphql-orm-qa:php' . $php . '-' . substr((string) md5_file(__DIR__ . '/.docker/qa/Dockerfile'), 0, 8);
    $quiet = context()->withQuiet()->withAllowFailure();

    if (!run(['docker', 'image', 'inspect', $image], context: $quiet)->isSuccessful()) {
        run(['docker', 'build', '--build-arg', 'PHP_VERSION=' . $php, '-t', $image, __DIR__ . '/.docker/qa'], context: context()->withTimeout(null));
    }

    $docker = ['docker', 'run', '--rm', '-u', posix_getuid() . ':' . posix_getgid(), '-v', __DIR__ . ':/app', '-w', '/app'];

    if ($network !== null) {
        $docker = [...$docker, '--network', $network];
    }

    foreach ($environment as $name => $value) {
        $docker = [...$docker, '-e', $name . '=' . $value];
    }

    if (!is_dir(__DIR__ . '/vendor')) {
        run([...$docker, $image, 'composer', 'install', '--no-interaction', '--no-progress'], context: context()->withTimeout(null));
    }

    $command = array_map(static fn (string $argument) => str_replace('%TIMEOUT%', (string) INTEGRATION_READY_TIMEOUT, $argument), $command);

    return run([...$docker, $image, ...$command], context: context()->withTimeout(null)->withAllowFailure())->isSuccessful();
}

#[AsTask('release', description: 'Release a version: date its CHANGELOG section and commit it; the tag is pushed by hand')]
function release(
    #[AsArgument(description: 'The version, e.g. 5.8.0')]
    string $version,
    #[AsOption(description: 'Check everything without writing nor committing')]
    bool $dryRun = false,
): int {
    $changelog = __DIR__ . '/CHANGELOG.md';
    $content = (string) file_get_contents($changelog);
    $quiet = context()->withQuiet()->withAllowFailure();
    $errors = [];

    if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        $errors[] = sprintf('"%s" is not a version: x.y.z.', $version);
    }

    if (trim(capture(['git', 'status', '--porcelain'], context: $quiet)) !== '') {
        $errors[] = 'The working tree has changes: commit or stash them first.';
    }

    $branch = trim(capture(['git', 'branch', '--show-current'], context: $quiet));

    if ($branch === '') {
        $errors[] = 'HEAD is detached: release from a branch, main or 4.x.';
    }

    if (trim(capture(['git', 'tag', '--list', 'v' . $version], context: $quiet)) !== '') {
        $errors[] = sprintf('The tag v%s exists already.', $version);
    }

    if (preg_match('/^## \[Unreleased\]\n(.*?)(?=^## \[)/ms', $content, $unreleased) !== 1 || trim($unreleased[1]) === '') {
        $errors[] = 'CHANGELOG.md has no [Unreleased] section with changes to release.';
    }

    if ($errors !== []) {
        io()->error($errors);

        return 1;
    }

    $heading = sprintf('## [%s] — %s', $version, date('Y-m-d'));
    io()->writeln(sprintf('<info>CHANGELOG.md</info>: ## [Unreleased] becomes %s', $heading));

    if ($dryRun) {
        io()->note('Dry run: nothing written nor committed.');

        return 0;
    }

    file_put_contents($changelog, preg_replace('/^## \[Unreleased\]$/m', $heading, $content, 1));

    if (!run(['bash', __DIR__ . '/.github/scripts/changelog-section.sh', $version], context: $quiet)->isSuccessful()) {
        run(['git', 'checkout', '--', 'CHANGELOG.md'], context: $quiet);
        io()->error(sprintf('The release notes of %s cannot be read from CHANGELOG.md: nothing committed.', $version));

        return 1;
    }

    run(['git', 'commit', '--quiet', '-m', 'chore: release ' . $version, '--', 'CHANGELOG.md']);
    io()->success(sprintf('%s committed. Tag it and push from your terminal:', $version));
    io()->writeln(sprintf('    git tag -a v%1$s -m "%1$s" && git push origin %2$s v%1$s', $version, trim(capture(['git', 'branch', '--show-current']))));

    return 0;
}

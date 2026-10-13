<?php

declare(strict_types=1);

// Keeps the current working directory behaviour, and silences the Castor 1.8 deprecation asking to choose it.
defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

use Castor\Attribute\AsTask;

use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;

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

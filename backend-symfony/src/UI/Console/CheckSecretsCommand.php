<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Security\SecretPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fail the boot when a security-sensitive secret still holds a known-default or
 * obviously-weak value in production.
 *
 * The prod entrypoint runs this after materialising the environment and before
 * migrations, so a misconfigured instance aborts with a non-zero exit code
 * instead of signing cookies / the audit chain / 2FA seeds with public keys.
 *
 * Outside production it is a deliberate no-op (returns success): dev/test/e2e
 * keep booting on the documented .env.dist defaults.
 */
#[AsCommand(
    name: 'app:security:check-secrets',
    description: 'Refuse known-default or weak secrets in production (fail-fast guardrail)',
)]
final class CheckSecretsCommand extends Command
{
    /**
     * Security-sensitive variables whose value must not be a public default in prod.
     *
     * @var list<string>
     */
    private const CHECKED = [
        'APP_SECRET',
        'TOTP_ENCRYPTION_KEY',
        'AUDIT_HMAC_KEY',
        'JWT_PASSPHRASE',
        'N8N_ENCRYPTION_KEY',
        'N8N_DEFAULT_USER_PASSWORD',
        'ADMIN_PASSWORD',
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
        private readonly SecretPolicy $policy = new SecretPolicy(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isProd = $this->appEnv === 'prod';

        $secrets = [];

        foreach (self::CHECKED as $name) {
            $secrets[$name] = $this->readEnv($name);
        }

        $violations = $this->policy->validate($secrets, $isProd);

        if ($violations === []) {
            $io->success($isProd
                ? 'Secrets check passed: no known-default or weak values.'
                : sprintf('Secrets check skipped (APP_ENV=%s, only enforced in prod).', $this->appEnv));

            return Command::SUCCESS;
        }

        $io->error('Refusing to boot: insecure secret values detected.');

        foreach ($violations as $name => $reason) {
            $io->writeln(sprintf('  - <error>%s</error> %s.', $name, $reason));
        }
        $io->writeln('Generate strong values (e.g. <info>openssl rand -hex 32</info>) and set them before booting.');

        return Command::FAILURE;
    }

    /**
     * Reads a variable from the process/Dotenv environment. Returns null when
     * absent everywhere (presence is enforced by the entrypoint), and preserves an
     * explicit empty string so the policy can flag it.
     */
    private function readEnv(string $name): ?string
    {
        if (\array_key_exists($name, $_SERVER) && \is_string($_SERVER[$name])) {
            return $_SERVER[$name];
        }

        if (\array_key_exists($name, $_ENV) && \is_string($_ENV[$name])) {
            return $_ENV[$name];
        }

        $raw = getenv($name);

        return $raw === false ? null : $raw;
    }
}

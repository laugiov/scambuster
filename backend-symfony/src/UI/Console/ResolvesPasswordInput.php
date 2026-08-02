<?php

declare(strict_types=1);

namespace App\UI\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared password resolution for the user-management commands.
 *
 * Precedence: --password, then --generate (random, printed once), then an
 * interactive hidden prompt; non-interactive with none of those is an error.
 * A minimum length is enforced to match the production entrypoint guardrail.
 */
trait ResolvesPasswordInput
{
    private const MIN_PASSWORD_LENGTH = 12;

    /**
     * @return array{0: ?string, 1: ?string} [password, errorMessage] — exactly one is non-null
     */
    private function resolvePassword(InputInterface $input, SymfonyStyle $io, OutputInterface $output): array
    {
        $password = $input->getOption('password');
        $generate = (bool) $input->getOption('generate');

        if (\is_string($password) && $password !== '') {
            $chosen = $password;
        } elseif ($generate) {
            $chosen = bin2hex(random_bytes(12)); // 24 hex chars
            $output->writeln('Generated password: ' . $chosen);
        } elseif ($input->isInteractive()) {
            // Hidden input with the visible fallback DISABLED: on a terminal that
            // cannot mask input we fail loudly rather than echo the password.
            $question = (new Question(sprintf('Password (min %d characters)', self::MIN_PASSWORD_LENGTH)))
                ->setHidden(true)
                ->setHiddenFallback(false);

            try {
                $answer = $io->askQuestion($question);
            } catch (\RuntimeException) {
                return [null, 'Cannot read a hidden password on this terminal. Use --generate or --password instead.'];
            }

            if (!\is_string($answer) || $answer === '') {
                return [null, 'A password is required.'];
            }
            $chosen = $answer;
        } else {
            return [null, 'A password is required: pass --password, --generate, or run interactively.'];
        }

        if (\strlen($chosen) < self::MIN_PASSWORD_LENGTH) {
            return [null, sprintf('Password too short (minimum %d characters).', self::MIN_PASSWORD_LENGTH)];
        }

        return [$chosen, null];
    }
}

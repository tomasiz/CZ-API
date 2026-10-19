<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Auth\PasswordResetService;
use Casamiza\Shared\Exception\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'user:reset-password', description: "Fixe un nouveau mot de passe (recours quand l'e-mail de réinitialisation n'est pas disponible)")]
final class UserResetPasswordCommand extends Command
{
    public function __construct(private readonly PasswordResetService $resets)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail du compte');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email');
        $email = is_string($email) && $email !== '' ? $email : (string) $io->ask('E-mail du compte');

        // Jamais en option de ligne de commande : le mot de passe resterait dans l'historique du shell.
        $question = (new Question('Nouveau mot de passe (12 caractères minimum)'))->setHidden(true)->setHiddenFallback(false);
        $password = (string) $io->askQuestion($question);

        try {
            $this->resets->resetByAdministrator($email, $password);
        } catch (ApiException $e) {
            $io->error([$e->errorCode(), ...array_map(
                static fn(string $field, array $codes): string => $field . ': ' . implode(', ', $codes),
                array_keys($e->errors()),
                $e->errors(),
            )]);

            return Command::FAILURE;
        }

        $io->success("Mot de passe de {$email} modifié, toutes ses sessions sont fermées.");

        return Command::SUCCESS;
    }
}

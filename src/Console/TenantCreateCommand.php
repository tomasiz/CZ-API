<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Tenant\TenantService;
use Casamiza\Shared\Exception\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:create', description: 'Crée un tenant et son premier administrateur')]
final class TenantCreateCommand extends Command
{
    public function __construct(private readonly TenantService $tenants)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom du tenant (la conciergerie)')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, "Prénom de l'administrateur")
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, "Nom de l'administrateur")
            ->addOption('email', null, InputOption::VALUE_REQUIRED, "E-mail de l'administrateur");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $this->ask($io, $input, 'name', 'Nom du tenant');
        $firstName = $this->ask($io, $input, 'first-name', "Prénom de l'administrateur");
        $lastName = $this->ask($io, $input, 'last-name', "Nom de l'administrateur");
        $email = $this->ask($io, $input, 'email', "E-mail de l'administrateur");

        // Jamais en option de ligne de commande : le mot de passe resterait dans l'historique du shell.
        $question = (new Question('Mot de passe (12 caractères minimum)'))->setHidden(true)->setHiddenFallback(false);
        $password = (string) $io->askQuestion($question);

        try {
            $tenant = $this->tenants->createWithAdmin($name, $firstName, $lastName, $email, $password);
        } catch (ApiException $e) {
            $io->error([$e->errorCode(), ...array_map(
                static fn(string $field, array $codes): string => $field . ': ' . implode(', ', $codes),
                array_keys($e->errors()),
                $e->errors(),
            )]);

            return Command::FAILURE;
        }

        $io->success("Tenant « {$tenant->name} » créé ({$tenant->id}), administrateur : {$email}");

        return Command::SUCCESS;
    }

    private function ask(SymfonyStyle $io, InputInterface $input, string $option, string $label): string
    {
        $value = $input->getOption($option);

        return is_string($value) && $value !== '' ? $value : (string) $io->ask($label);
    }
}

<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Storage\UserStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seed the web UI's allowlist from the server.
 *
 * Day-to-day user management happens in the UI, but the very first address has
 * to come from somewhere trusted: the UI deliberately has no "first sign-in
 * becomes admin" bootstrap, because it is reachable from the internet and that
 * would let a stranger claim the account.
 */
#[AsCommand(name: 'users:add', description: 'Allow an email address to sign in to the web UI')]
final class UsersCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Address to allow; omit to list current users')
            ->addArgument('role', InputArgument::OPTIONAL, 'Role to assign (see config/roles.php)', UserStore::DEFAULT_ROLE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->app->userStore();
        $email = (string) ($input->getArgument('email') ?? '');
        $role  = (string) $input->getArgument('role');

        if ($email !== '') {
            if (!$users->add($email, $role)) {
                $output->writeln("<error>Refusé : adresse invalide, rôle inconnu, ou déjà présente ({$email}).</error>");

                return Command::FAILURE;
            }
            $output->writeln("<info>{$email}</info> ({$role}) peut maintenant se connecter.");
        }

        if ($users->isEmpty()) {
            $output->writeln('Aucun utilisateur enregistré — personne ne peut accéder à l\'interface.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['email', 'rôle', 'statut']);
        foreach ($users->all() as $user) {
            $table->addRow([$user['email'], $user['role'], $user['status']]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}

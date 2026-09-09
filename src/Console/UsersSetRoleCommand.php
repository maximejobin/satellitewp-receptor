<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Console;

use SatelliteWP\Xtractor\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Change an already-listed user's role from the server.
 *
 * `users:add` only ever adds — pointed at an email already in the file it
 * just refuses ("already present"), so there was no CLI way to fix a role
 * once set (2026-09-03: a real operator got stuck exactly this way — added
 * themselves via `users:add` with the default `maintenance` role, which
 * (correctly) has no `user_edit` capability, so the web UI's own "change my
 * role" path was itself gated by the role that needed changing). This command
 * is the server-trusted way out of that, same trust level as `users:add` —
 * both assume whoever has shell access on the box is already trusted with
 * the whole allowlist.
 */
#[AsCommand(name: 'users:set-role', description: "Change an already-listed user's role")]
final class UsersSetRoleCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Address to change')
            ->addArgument('role', InputArgument::REQUIRED, 'New role (see config/roles.php)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->app->userStore();
        $email = (string) $input->getArgument('email');
        $role  = (string) $input->getArgument('role');

        $current = $users->get($email);
        if ($current === null) {
            $output->writeln("<error>Adresse inconnue : {$email}. bin/xtractor users:add pour l'ajouter d'abord.</error>");

            return Command::FAILURE;
        }

        if (!$users->updateUser($email, $email, $current['first_name'], $current['last_name'], $role)) {
            $output->writeln(
                "<error>Refusé : rôle inconnu ({$role}), ou {$email} est le dernier administrateur actif.</error>"
            );

            return Command::FAILURE;
        }

        $output->writeln("<info>{$email}</info> a maintenant le rôle <info>{$role}</info>.");

        return Command::SUCCESS;
    }
}

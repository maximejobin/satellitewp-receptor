<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http\Controller;

use SatelliteWP\Xtractor\Storage\UserStore;

/** /users (admin allowlist management) and /profile (self-service). */
final class UserController extends Controller
{
    /** @param array<string, string> $params */
    public function list(array $params): void
    {
        if (!$this->requireCapability('user_view')) {
            return;
        }

        $me = $this->currentUser();

        $this->render('users', [
            'title'  => 'Users',
            'nav'    => 'users',
            'users'  => $this->app->userStore()->all(),
            'roles'  => $this->app->roleCapabilities()->roles(),
            'me'     => $me,
            // Same rule as POST /users, so a control shows exactly when it would be accepted.
            'can'    => $this->managementCapabilities($me),
            'csrf'   => $this->csrfToken(),
            'notice' => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    /**
     * POST /users. Needs a real signed-in identity before any capability:
     * without one (Basic auth / open dev) there is no roster to check against,
     * and this list stays blocked rather than wide open.
     *
     * @param array<string, string> $params
     */
    public function save(array $params): void
    {
        $users = $this->app->userStore();
        $me    = $this->currentUser();

        if ($me === null) {
            $this->forbidden('Only a signed-in administrator can manage users.');

            return;
        }

        $action     = (string) ($_POST['action'] ?? '');
        $capability = match ($action) {
            'add'                   => 'user_add',
            'edit'                  => 'user_edit',
            'suspend', 'reactivate' => 'user_suspend',
            'remove'                => 'user_remove',
            default                 => null,
        };
        $role = $users->roleOf($me);
        if ($capability === null || $role === null || !$this->app->roleCapabilities()->can($role, $capability)) {
            $this->forbidden('You do not have permission to do this.');

            return;
        }

        $email  = (string) ($_POST['email'] ?? '');
        $notice = match ($action) {
            'add' => $users->add(
                $email,
                (string) ($_POST['role'] ?? UserStore::DEFAULT_ROLE),
                (string) ($_POST['first_name'] ?? ''),
                (string) ($_POST['last_name'] ?? '')
            ) ? 'added' : 'add-failed',
            'edit' => $users->updateUser(
                $email,
                (string) ($_POST['new_email'] ?? ''),
                (string) ($_POST['first_name'] ?? ''),
                (string) ($_POST['last_name'] ?? ''),
                (string) ($_POST['role'] ?? UserStore::DEFAULT_ROLE)
            ) ? 'edited' : 'edit-failed',
            'suspend'    => $users->setStatus($email, UserStore::STATUS_SUSPENDED) ? 'suspended' : 'suspend-failed',
            'reactivate' => $users->setStatus($email, UserStore::STATUS_ACTIVE) ? 'reactivated' : 'reactivate-failed',
            'remove'     => $users->remove($email) ? 'removed' : 'remove-failed',
        };

        $this->redirect('/users?notice=' . $notice);
    }

    /** @param array<string, string> $params */
    public function profile(array $params): void
    {
        $me   = $this->currentUser();
        $user = $me !== null ? $this->app->userStore()->get($me) : null;
        if ($user === null) {
            $this->notFound();

            return;
        }

        $this->render('profile', [
            'title'  => 'My profile',
            'nav'    => 'profile',
            'user'   => $user,
            'csrf'   => $this->csrfToken(),
            'notice' => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    /**
     * POST /profile — always the session's own record, never another
     * user's, so no capability is involved.
     *
     * @param array<string, string> $params
     */
    public function saveProfile(array $params): void
    {
        $me = $this->currentUser();
        if ($me === null) {
            $this->forbidden('You must be signed in to edit a profile.');

            return;
        }

        $rawData = trim((string) ($_POST['data'] ?? ''));
        $data    = null;
        if ($rawData !== '') {
            $decoded = json_decode($rawData, true);
            if (!is_array($decoded)) {
                $this->redirect('/profile?notice=invalid-json');

                return;
            }
            $data = $decoded;
        }

        $this->app->userStore()->updateProfile(
            $me,
            $data,
            (string) ($_POST['runcloud_api_key'] ?? ''),
            (string) ($_POST['public_ssh_key'] ?? '')
        );

        $this->redirect('/profile?notice=saved');
    }

    /** @return array{add: bool, edit: bool, suspend: bool, remove: bool} */
    private function managementCapabilities(?string $me): array
    {
        $role = $me !== null ? $this->app->userStore()->roleOf($me) : null;
        $can  = fn (string $capability): bool => $role !== null && $this->app->roleCapabilities()->can($role, $capability);

        return ['add' => $can('user_add'), 'edit' => $can('user_edit'), 'suspend' => $can('user_suspend'), 'remove' => $can('user_remove')];
    }
}

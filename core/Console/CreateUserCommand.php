<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Support\Services;

/**
 * php bin/create-user.php --email=... --name="..." --group-role=super_admin|group_viewer
 * php bin/create-user.php --email=... --name="..." --org=<slug> --role=admin|manager|staff|cashier
 *
 * Password: --password-env=NAME reads it from an environment variable; --generate-password makes a random one and
 * prints it once; with neither, the account is created "pending" and gets its password through a reset link.
 * A password on the command line is deliberately not supported, because it would stay in shell history.
 */
final class CreateUserCommand extends Command
{
    public function run(array $argv): int
    {
        try {
            $options = Arguments::parse($argv, ['email', 'name', 'group-role', 'org', 'role', 'password-env', 'generate-password', 'help']);
        } catch (\InvalidArgumentException $exception) {
            $this->err($exception->getMessage());
            $this->usage();

            return 2;
        }

        foreach (['email', 'name', 'group-role', 'org', 'role', 'password-env'] as $name) {
            if (isset($options[$name]) && !is_string($options[$name])) {
                $this->err(sprintf('Option --%s needs a value: --%s=...', $name, $name));

                return 2;
            }
        }

        $isGroup = isset($options['group-role']);
        $isMember = isset($options['org']) || isset($options['role']);

        if (isset($options['help']) || !isset($options['email'], $options['name']) || $isGroup === $isMember
            || ($isMember && !(isset($options['org'], $options['role'])))) {
            $this->usage();

            return isset($options['help']) ? 0 : 2;
        }

        $password = null;
        $generated = false;
        if (isset($options['generate-password'])) {
            $password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'Aa'), '=');
            $generated = true;
        } elseif (isset($options['password-env'])) {
            $value = getenv((string) $options['password-env']);
            if (!is_string($value) || $value === '') {
                $this->err(sprintf('The environment variable %s is empty or not set.', (string) $options['password-env']));

                return 2;
            }
            $password = $value;
        }

        try {
            $services = new Services($this->app, true);
            $actor = StaffUser::system();

            if ($isGroup) {
                $result = $services->accounts()->createGroupAccount($actor, (string) $options['email'], (string) $options['name'], (string) $options['group-role'], $password);
                $where = 'group role ' . $options['group-role'];
            } else {
                $organization = $services->organizations()->findBySlug((string) $options['org']);
                if ($organization === null) {
                    $this->err(sprintf('No organization with the slug "%s" is registered.', (string) $options['org']));

                    return 1;
                }
                $result = $services->accounts()->createStaffAccount($actor, $organization, (string) $options['email'], (string) $options['name'], (string) $options['role'], $password);
                $where = sprintf('%s in %s', $options['role'], $organization->slug);
            }
        } catch (\Throwable $exception) {
            $message = $exception instanceof \Gfh\Core\Http\HttpException && $exception->fields() !== []
                ? $exception->getMessage() . ' ' . implode(' ', $exception->fields())
                : $exception->getMessage();
            $this->err('Could not create the account: ' . $message);

            return 1;
        }

        $this->out(sprintf('Created %s (%s).', (string) $options['email'], $where));
        if ($generated) {
            $this->out('Generated password (shown once, store it safely): ' . $password);
        } elseif ($password === null) {
            $this->out('No password set: the account is pending. Generate a reset link for it in the Admin app.');
        }

        return 0;
    }

    private function usage(): void
    {
        $this->out('Usage:');
        $this->out('  php bin/create-user.php --email=<email> --name="<Full name>" --group-role=super_admin|group_viewer [password option]');
        $this->out('  php bin/create-user.php --email=<email> --name="<Full name>" --org=<slug> --role=admin|manager|staff|cashier [password option]');
        $this->out('Password options: --generate-password | --password-env=<VARIABLE NAME> | (none: pending, set through a reset link)');
    }
}

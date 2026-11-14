<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for the sign-in attempt log used by the lockout rule. */
final class LoginAttemptRepository
{
    public function __construct(private readonly \PDO $core)
    {
    }

    public function record(string $scope, string $identifierHash, string $ip, bool $succeeded): void
    {
        $statement = $this->core->prepare(
            'INSERT INTO login_attempts (scope, identifier_hash, ip, succeeded) VALUES (:scope, :hash, :ip, :succeeded)',
        );
        $statement->bindValue('scope', $scope);
        $statement->bindValue('hash', $identifierHash);
        $statement->bindValue('ip', $ip);
        $statement->bindValue('succeeded', $succeeded, \PDO::PARAM_BOOL);
        $statement->execute();
    }

    /**
     * Failed attempts for one identifier since the window opened, ignoring failures before its last success.
     *
     * @return array{0: int, 1: ?\DateTimeImmutable} the count and the time of the oldest counted failure
     */
    public function failuresForIdentifier(string $scope, string $identifierHash, \DateTimeImmutable $since): array
    {
        $statement = $this->core->prepare(
            'SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest FROM login_attempts '
            . 'WHERE scope = :scope AND identifier_hash = :hash AND succeeded = false '
            . 'AND attempted_at > GREATEST(CAST(:since AS timestamptz), COALESCE('
            . "(SELECT MAX(attempted_at) FROM login_attempts WHERE scope = :scope_ok AND identifier_hash = :hash_ok AND succeeded = true), "
            . "'-infinity'::timestamptz))",
        );
        $statement->execute([
            'scope' => $scope,
            'hash' => $identifierHash,
            'since' => $since->format('Y-m-d H:i:sP'),
            'scope_ok' => $scope,
            'hash_ok' => $identifierHash,
        ]);
        $row = $statement->fetch();

        $oldest = ($row !== false && $row['oldest'] !== null) ? new \DateTimeImmutable((string) $row['oldest']) : null;

        return [(int) ($row['failures'] ?? 0), $oldest];
    }

    public function failuresForIp(string $ip, \DateTimeImmutable $since): int
    {
        $statement = $this->core->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND succeeded = false AND attempted_at > CAST(:since AS timestamptz)',
        );
        $statement->execute(['ip' => $ip, 'since' => $since->format('Y-m-d H:i:sP')]);

        return (int) $statement->fetchColumn();
    }
}

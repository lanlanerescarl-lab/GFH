<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for one-time password reset tokens. Only a hash of the token is ever stored. */
final class PasswordResetRepository
{
    public function __construct(private readonly \PDO $core)
    {
    }

    /** Retires every unused token of the subject, so only the newest link works. */
    public function supersedeUnused(string $subjectType, string $subjectId): void
    {
        $statement = $this->core->prepare(
            'UPDATE password_reset_tokens SET used_at = now() WHERE subject_type = :type AND subject_id = :id AND used_at IS NULL',
        );
        $statement->execute(['type' => $subjectType, 'id' => $subjectId]);
    }

    public function create(string $subjectType, string $subjectId, string $tokenHash, \DateTimeImmutable $expiresAt, ?string $issuedBy): void
    {
        $statement = $this->core->prepare(
            'INSERT INTO password_reset_tokens (subject_type, subject_id, token_hash, expires_at, issued_by) '
            . 'VALUES (:type, :id, :hash, CAST(:expires AS timestamptz), :issued_by)',
        );
        $statement->execute([
            'type' => $subjectType,
            'id' => $subjectId,
            'hash' => $tokenHash,
            'expires' => $expiresAt->format('Y-m-d H:i:sP'),
            'issued_by' => $issuedBy,
        ]);
    }

    /** @return array{id: string, subject_type: string, subject_id: string}|null */
    public function findValid(string $tokenHash): ?array
    {
        $statement = $this->core->prepare(
            'SELECT id, subject_type, subject_id FROM password_reset_tokens '
            . 'WHERE token_hash = :hash AND used_at IS NULL AND expires_at > now()',
        );
        $statement->execute(['hash' => $tokenHash]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Marks a token used. Returns false when someone else used it first, which makes a link single-use even under a race. */
    public function markUsed(string $id): bool
    {
        $statement = $this->core->prepare('UPDATE password_reset_tokens SET used_at = now() WHERE id = :id AND used_at IS NULL');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }
}

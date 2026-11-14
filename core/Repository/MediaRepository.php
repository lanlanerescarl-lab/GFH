<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** All SQL for the media table: one row per uploaded file. */
final class MediaRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(string $path, ?string $thumbPath, string $originalName, string $mime, int $bytes, int $width, int $height, ?string $altText, ?string $uploadedBy): string
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO media (path, thumb_path, original_name, mime, bytes, width, height, alt_text, uploaded_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
        );
        $statement->execute([$path, $thumbPath, $originalName, $mime, $bytes, $width, $height, $altText, $uploadedBy]);

        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, path, thumb_path, original_name, mime, bytes, width, height, alt_text FROM media WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}

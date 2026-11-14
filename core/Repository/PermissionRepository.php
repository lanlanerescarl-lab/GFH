<?php

declare(strict_types=1);

namespace Gfh\Core\Repository;

/** Reads the permission matrix: the default for a role, replaced by an organization's own row where one exists. */
final class PermissionRepository
{
    public function __construct(private readonly \PDO $core)
    {
    }

    /**
     * @return array<string, array{view: bool, edit: bool, delete: bool}> keyed by module
     */
    public function matrix(string $organizationId, string $role): array
    {
        $statement = $this->core->prepare(
            'SELECT d.module, '
            . 'COALESCE(o.can_view, d.can_view) AS can_view, '
            . 'COALESCE(o.can_edit, d.can_edit) AS can_edit, '
            . 'COALESCE(o.can_delete, d.can_delete) AS can_delete '
            . 'FROM role_permission_defaults d '
            . 'LEFT JOIN organization_role_permissions o '
            . 'ON o.organization_id = :organization_id AND o.role = d.role AND o.module = d.module '
            . 'WHERE d.role = :role',
        );
        $statement->execute(['organization_id' => $organizationId, 'role' => $role]);

        $matrix = [];
        foreach ($statement->fetchAll() as $row) {
            $matrix[(string) $row['module']] = [
                'view' => (bool) $row['can_view'],
                'edit' => (bool) $row['can_edit'],
                'delete' => (bool) $row['can_delete'],
            ];
        }

        return $matrix;
    }
}

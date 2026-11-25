<?php

declare(strict_types=1);

namespace Gfh\Seeds\Demo;

/**
 * The demo history seed needs stock movements dated in the past, but every service stamps a movement with the
 * moment it writes it, and stock_movements is append-only (nothing can be updated afterwards). So for the length of
 * the seed this class installs one BEFORE INSERT trigger on stock_movements that replaces occurred_at with the time set
 * through set(). It is created and dropped inside the seed's own transaction. It does not touch the guard against
 * UPDATE, DELETE and TRUNCATE, which stays in force. Only the owner of the table (the migrator role) can create it.
 */
final class DemoSeedClock
{
    public const NAME = 'gfh_seed_clock';

    private ?\PDOStatement $setter = null;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** True when the connected role owns stock_movements, which is the migrator role and never the application role. */
    public static function connectedAsOwner(\PDO $pdo): bool
    {
        return $pdo->query("SELECT 1 FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE c.relname = 'stock_movements' AND c.relnamespace = 'public'::regnamespace AND r.rolname = current_user")->fetchColumn() !== false;
    }

    /** True when the trigger or its function exists. */
    public static function exists(\PDO $pdo): bool
    {
        $name = $pdo->quote(self::NAME);

        return $pdo->query("SELECT 1 FROM pg_trigger WHERE tgname = $name AND NOT tgisinternal UNION ALL SELECT 1 FROM pg_proc WHERE proname = $name")->fetchColumn() !== false;
    }

    public function install(): void
    {
        if (!self::connectedAsOwner($this->pdo)) {
            throw new \RuntimeException('The demo history seed only runs as the migrator role (the owner of stock_movements).');
        }
        $this->pdo->exec(
            'CREATE FUNCTION ' . self::NAME . "() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
             DECLARE stamp text := NULLIF(current_setting('gfh.seed_clock', true), '');
             BEGIN
               IF stamp IS NOT NULL THEN NEW.occurred_at := stamp::timestamptz; END IF;
               RETURN NEW;
             END \$fn\$;
             CREATE TRIGGER " . self::NAME . ' BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION ' . self::NAME . '()',
        );
    }

    /** Movements written from now on carry this time. */
    public function set(\DateTimeInterface $at): void
    {
        $this->setter ??= $this->pdo->prepare("SELECT set_config('gfh.seed_clock', :stamp, true)");
        $this->setter->execute(['stamp' => $at->format('Y-m-d H:i:sP')]);
    }

    public function remove(): void
    {
        $this->pdo->exec("SELECT set_config('gfh.seed_clock', '', true)");
        $this->pdo->exec('DROP TRIGGER IF EXISTS ' . self::NAME . ' ON stock_movements; DROP FUNCTION IF EXISTS ' . self::NAME . '()');
    }
}

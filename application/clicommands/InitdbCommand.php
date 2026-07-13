<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Common\Database;
use RuntimeException;

class InitdbCommand extends Command
{
    use Database;

    public function indexAction()
    {
        $db = $this->getDb();
        $driver = $this->detectDriver($db);
        $file = __DIR__ . '/../../etc/schema/' . ($driver === 'pgsql' ? 'postgresql.sql' : 'sqlite.sql');

        if (!file_exists($file)) {
            $this->fail("Schema file not found: $file");
        }

        $sql = file_get_contents($file);
        $statements = preg_split('/;\s*(\n|$)/', $sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $db->prepexec($statement);
        }
    }

    public function migrateAction()
    {
        $db = $this->getDb();
        $driver = $this->detectDriver($db);

        if ($driver === 'pgsql') {
            $db->prepexec('ALTER TABLE proactiveha_mapping ADD COLUMN IF NOT EXISTS cluster_id INTEGER REFERENCES proactiveha_cluster(id) ON DELETE SET NULL');
        } else {
            $columns = $db->fetchAll("PRAGMA table_info(proactiveha_mapping)");
            $hasClusterId = false;
            foreach ($columns as $column) {
                if ($column->name === 'cluster_id') {
                    $hasClusterId = true;
                    break;
                }
            }

            if (!$hasClusterId) {
                $db->prepexec('ALTER TABLE proactiveha_mapping ADD COLUMN cluster_id INTEGER REFERENCES proactiveha_cluster(id) ON DELETE SET NULL');
            }
        }

        echo "Migration complete.\n";
    }

    private function detectDriver($db)
    {
        if (method_exists($db, 'getConfig')) {
            $config = $db->getConfig();
            $dbType = $config['db'] ?? $config->db ?? null;
            if ($dbType) {
                return $dbType;
            }
        }

        $pdo = null;
        if (method_exists($db, 'getPdo')) {
            $pdo = $db->getPdo();
        } elseif (method_exists($db, 'getAdapter') && method_exists($db->getAdapter(), 'getConnection')) {
            $pdo = $db->getAdapter()->getConnection();
        }

        if ($pdo !== null) {
            return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        }

        return 'sqlite';
    }
}

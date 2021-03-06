<?php

namespace TwentyFifth\Migrations\Manager;

use Symfony\Component\Console;
use TwentyFifth\Migrations\Exception\RuntimeException;
use TwentyFifth\Migrations\Manager\ConfigManager\ConfigInterface;

class SchemaManager
{
    /** @var ConfigInterface */
    private $configManager;

    /** @var string */
    private $migration_table_name = 'migrations';

    /** @var */
    private $pg_connection;

    public function __construct(ConfigInterface $configManager)
    {
        $this->configManager = $configManager;

        $this->ensureMigrationsTableExists();
    }

    public function getMigrationTableName()
    {
        return $this->migration_table_name;
    }

    protected function getConnection()
    {
        if (!isset($this->pg_connection)) {
            $connectionString = $this->getConnectionString();
            $this->pg_connection = pg_connect($connectionString);

            if (!$this->pg_connection) {
                throw new \Exception(sprintf('Cannot connect to Database using connection string "%s"', $connectionString));
            }
        }

        return $this->pg_connection;
    }

    public function doesMigrationsTableExist()
    {
        $result = pg_query_params(
            $this->getConnection(),
            'SELECT count(relname) as count FROM pg_class WHERE relname=$1',
            array($this->getMigrationTableName())
        );

        return (bool)pg_fetch_result($result, 0, 0);
    }

    public function ensureMigrationsTableExists()
    {
        if (!$this->doesMigrationsTableExist()) {
            $create_sql = sprintf(
                'CREATE TABLE %s (
					mig_title text NOT NULL PRIMARY KEY,
					mig_applied timestamp NOT NULL DEFAULT NOW()
				);
			', $this->getMigrationTableName());

            $create_sql .= sprintf(
                'COMMENT ON TABLE %s IS \'Database migration information\'',
                $this->getMigrationTableName()
            );

            pg_query($this->getConnection(), 'BEGIN');
            pg_query($this->getConnection(), $create_sql);
            pg_query($this->getConnection(), 'COMMIT');
        }
    }

    public function getNotAppliedMigrations($migration_list)
    {
        $sql = '
			SELECT *
			FROM %s;
		';

        $result = pg_query($this->getConnection(), sprintf($sql, $this->getMigrationTableName()));

        if (!$result) {
            throw new RuntimeException(pg_last_error());
        }

        while ($line = pg_fetch_assoc($result)) {
            unset($migration_list[$line['mig_title']]);
        }

        return $migration_list;
    }

    public function executeMigration($name, $sql, Console\Output\OutputInterface $output)
    {
        if (empty($sql)) {
            throw new RuntimeException(sprintf('Migration "%s" has no content', $name));
        }

        $output->writeln('Starting '.$name);
        pg_query($this->getConnection(), 'BEGIN');

        try {
            $this->executeSQL($sql);
            $this->markMigration($name);
            pg_query($this->getConnection(), 'COMMIT');

            $output->writeln($name.' is committed');
        } catch (\Exception $e) {
            pg_query($this->getConnection(), 'ROLLBACK');
            $output->writeln('Failed: '.$e->getMessage());
            throw new RuntimeException($e->getMessage());
        }
    }

    public function executeSQL($sql)
    {
        if (!self::hasCopyFromStdin($sql)) {
            $conn = $this->getConnection();
            $success = @pg_query($conn, $sql);
            if (!$success) {
                throw new \Exception(pg_last_error($this->getConnection()));
            }
            return;
        }

        throw new RuntimeException("Copy is not yet implemented, use pg_dump --inserts if possible");
    }

    public function markMigration($name)
    {
        $insert_sql = sprintf('INSERT INTO %s (mig_title) VALUES ($1)', $this->getMigrationTableName());
        pg_query_params($this->getConnection(), $insert_sql, array($name));
    }

    public static function hasCopyFromStdin($string)
    {
        // for perfomance issues, don't go further if not found anyway
        if (stripos($string, 'copy') === false) {
            return false;
        }
        
        return preg_match('/COPY .* FROM stdin;/i', $string) ? true : false;
    }

    /**
     * Creates the connection string used by pg_connect to connect to the database
     * @return string
     */
    protected function getConnectionString()
    {
        return sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $this->escapeParameter($this->configManager->getHost()),
            $this->escapeParameter($this->configManager->getPort()),
            $this->escapeParameter($this->configManager->getDatabase()),
            $this->escapeParameter($this->configManager->getUsername()),
            $this->escapeParameter($this->configManager->getPassword())
        );
    }

    private function escapeParameter($parameter)
    {
        $escaped = str_replace('\\', '\\\\', $parameter);
        return str_replace("'", "\\'", $escaped);
    }

}

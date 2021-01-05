<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube\Db;

use PDOStatement;
use rcube_db;
use Psr\Log\LoggerInterface;

/**
 * Access module for the roundcube database.
 *
 * The purpose of this class is to decouple all access to the roundcube database from the rest of the plugin. The main
 * purpose of this is to set a ground for testing, where the actual access to the database (this class) could be
 * replaced by mocks. The methods of this class should be able to satisfy all query needs of the plugin without the need
 * to have SQL queries directly inside the plugin, as these would be difficult to parse in a test mock.
 */
class Database extends AbstractDatabase
{
    /** @var string[] DBTABLES_WITHOUT_ID List of table names that have no single ID column. */
    private const DBTABLES_WITHOUT_ID = ['group_user'];

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var rcube_db $dbHandle The roundcube database handle */
    private $dbHandle;

    /** @var bool $inTransaction Indicates whether we are currently inside a transaction */
    private $inTransaction = false;

    /**
     * Initializes a Database instance.
     *
     * @param rcube_db $dbh The roundcube database handle
     */
    public function __construct(LoggerInterface $logger, rcube_db $dbh)
    {
        $this->logger   = $logger;
        $this->dbHandle = $dbh;
    }

    public function startTransaction(bool $readonly = true): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        if ($this->inTransaction) {
            throw new \Exception("Cannot start nested transaction");
        } else {
            // SQLite3 always has Serializable isolation of transactions, and does not support
            // the SET TRANSACTION command.
            $level = $readonly ? 'REPEATABLE READ' : 'SERIALIZABLE';
            $mode  = $readonly ? "READ ONLY" : "READ WRITE";

            switch ($dbh->db_provider) {
                case "mysql":
                    $ret = $dbh->query("SET TRANSACTION ISOLATION LEVEL $level, $mode");
                    break;
                case "sqlite":
                    $ret = true;
                    break;
                case "postgres":
                    $ret = $dbh->query("SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL $level, $mode");
                    break;
                default:
                    $logger->critical("Unsupported database backend: " . $dbh->db_provider);
                    return;
            }

            if ($ret !== false) {
                $ret = $dbh->startTransaction();
            }

            if ($ret === false) {
                $logger->error(__METHOD__ . " ERROR: " . $dbh->is_error());
                throw new DatabaseException($dbh->is_error());
            }

            $this->inTransaction = true;
        }
    }

    public function endTransaction(): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        if ($this->inTransaction) {
            $this->inTransaction = false;

            if ($dbh->endTransaction() === false) {
                $logger->error("Database::endTransaction ERROR: " . $dbh->is_error());
                throw new DatabaseException($dbh->is_error());
            }

            $this->resetTransactionSettings();
        } else {
            throw new \Exception("Attempt to commit a transaction while not within a transaction");
        }
    }

    public function rollbackTransaction(): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        if ($this->inTransaction) {
            $this->inTransaction = false;
            if ($dbh->rollbackTransaction() === false) {
                $logger->error("Database::rollbackTransaction ERROR: " . $dbh->is_error());
                throw new \Exception($dbh->is_error());
            }

            $this->resetTransactionSettings();
        } else {
            // not throwing an exception here facilitates usage of the interface at caller side. The caller
            // can issue rollback without having to keep track whether an error occurred before/after a
            // transaction was started/ended.
            $logger->notice("Ignored request to rollback a transaction while not within a transaction");
        }
    }

    /**
     * Resets database transaction settings to defaults that should apply to autocommit transactions.
     */
    private function resetTransactionSettings(): void
    {
        $logger = $this->logger;
        $dbh = $this->dbHandle;
        switch ($dbh->db_provider) {
            case "postgres":
                $ret = $dbh->query(
                    "SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ WRITE"
                );
                break;
            default:
                return;
        }

        // reset default session characteristics to read/write for autocommit single-statement transactions
        if ($ret === false) {
            $logger->warning(__METHOD__ . " ERROR: " . $dbh->is_error());
        }
    }

    /**
     * {@inheritDoc}
     *
     * If this function encounters an error, it will abort execution of the migrations. The database will be
     * in a potentially broken state, causing further errors when the plugin is executed. Unfortunately, I do not see a
     * better way to handle errors. Throwing an exception would result in Roundcube not being usable at all for the user
     * in case of errors.
     */
    public function checkMigrations(string $dbPrefix, string $scriptDir): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        // We only support the non-commercial database types supported by roundcube, so quit with an error
        switch ($dbh->db_provider) {
            case "mysql":
                $db_backend = "mysql";
                break;
            case "sqlite":
                $db_backend = "sqlite3";
                break;
            case "postgres":
                $db_backend = "postgres";
                break;
            default:
                $logger->critical("Unsupported database backend: " . $dbh->db_provider);
                return;
        }

        // (1) Determine which migration scripts are available, in alphabetical ascending order
        $migrationsAvailable = [];
        foreach (scandir($scriptDir, SCANDIR_SORT_ASCENDING) as $migrationDir) {
            if (preg_match("/^\d{4}-/", $migrationDir)) {
                $migrationsAvailable[] = $migrationDir;
            }
        }

        // (2) Determine which migration scripts have already been executed. This must handle the initial case that
        //     the plugin's database tables to not exist yet, in which case they will be initialized.
        $migrationsDone = [];
        $dbh->set_option('ignore_key_errors', true);
        $sql_result = $dbh->query('SELECT filename FROM ' . $dbh->table_name('carddav_migrations')) ?: [];
        while ($processed = $dbh->fetch_assoc($sql_result)) {
            $migrationsDone[$processed['filename']] = true;
        }
        $dbh->set_option('ignore_key_errors', null);

        // (3) Execute the migration scripts that have not been executed before
        foreach ($migrationsAvailable as $migration) {
            // skip migrations that have already been done
            if (key_exists($migration, $migrationsDone)) {
                continue;
            }

            $logger->notice("In migration: $migration");

            $phpMigrationScript = "$scriptDir/$migration/migrate.php";
            $sqlMigrationScript = "$scriptDir/$migration/$db_backend.sql";

            if (file_exists($phpMigrationScript)) {
                include $phpMigrationScript;
                $migrationClass = "\MStilkerich\CardDavAddressbook4Roundcube\DBMigrations\Migration"
                    . substr($migration, 0, 4); // the 4-digit number

                /**
                 * @psalm-suppress InvalidStringClass
                 * @var DBMigrationInterface $migrationObj
                 */
                $migrationObj = new $migrationClass();
                if ($migrationObj->migrate($dbh, $logger) === false) {
                    return; // error already logged
                }
            } elseif (file_exists($sqlMigrationScript)) {
                if ($this->performSqlMigration($sqlMigrationScript, $dbPrefix) === false) {
                    return; // error already logged
                }
            } else {
                $logger->warning("No migration script found for: $migration");
                // do not continue with other scripts that may depend on this one
                return;
            }

            $dbh->query(
                "INSERT INTO " . $dbh->table_name("carddav_migrations") . " (filename) VALUES (?)",
                $migration
            );

            if ($dbh->is_error()) {
                $logger->error("Recording exec of migration $migration failed: " . $dbh->is_error());
                return;
            }
        }
    }

    /**
     * Executes an SQL migration script.
     *
     * @param string $migrationScript The path to the migration script.
     * @param string $dbPrefix The optional prefix to all database table names as configured in Roundcube.
     */
    private function performSqlMigration(string $migrationScript, string $dbPrefix): bool
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;
        $queries_raw = file_get_contents($migrationScript);

        if ($queries_raw === false) {
            $logger->error("Failed to read migration script: $migrationScript - aborting");
            return false;
        }

        $queryCount = preg_match_all('/.+?;/s', $queries_raw, $queries);
        $logger->info("Found $queryCount queries in $migrationScript");
        if ($queryCount > 0) {
            foreach ($queries[0] as $query) {
                $query = str_replace("TABLE_PREFIX", $dbPrefix, $query);
                $dbh->query($query);

                if ($dbh->is_error()) {
                    $logger->error("Migration query ($query) failed: " . $dbh->is_error());
                    return false;
                }
            }
        }

        return true;
    }

    public function insert(string $table, array $cols, array $rows): string
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        // check parameters
        $numCols = count($cols);
        if (empty($rows)) {
            throw new \Exception("Database::insert on $table called without rows to insert");
        }
        foreach ($rows as $row) {
            if (count($row) != $numCols) {
                throw new \Exception("Database::insert on $table: row given that does not match $numCols columns");
            }
        }

        // build / execute query
        $sqlRowPlaceholders = '(?' . str_repeat(',?', $numCols - 1) . ')';

        $sql = 'INSERT INTO ' . $dbh->table_name("carddav_$table") .
            '(' . implode(",", $cols)  . ') ' .
            'VALUES ' .
            implode(', ', array_fill(0, count($rows), $sqlRowPlaceholders));

        $dbh->query($sql, call_user_func_array('array_merge', $rows));

        // return ID of last inserted row
        if (in_array($table, self::DBTABLES_WITHOUT_ID)) {
            $dbid = "";
        } else {
            $dbid = $dbh->insert_id("carddav_$table");
            $dbid = is_bool($dbid) ? "" /* error thrown below */ : (string) $dbid;
        }
        $logger->debug("INSERT $table ($sql) -> $dbid");

        if ($dbh->is_error()) {
            $logger->error("Database::insert ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $dbid;
    }

    public function update(
        $conditions,
        array $cols,
        array $vals,
        string $table = 'contacts'
    ): int {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = 'UPDATE ' . $dbh->table_name("carddav_$table") . ' SET ' . implode("=?,", $cols) . '=? ';

        // WHERE clause
        $sql .= $this->getConditionsQuery($conditions);

        $logger->debug("UPDATE $table ($sql)");
        $sql_result = $dbh->query($sql, $vals);

        if ($dbh->is_error()) {
            $logger->error("Database::update ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $dbh->affected_rows($sql_result);
    }

    public function get($conditions, string $cols = '*', string $table = 'contacts', array $options = []): array
    {
        $dbh = $this->dbHandle;
        $sql_result = $this->internalGet($conditions, $cols, $table, $options);

        $ret = [];
        while ($row = $dbh->fetch_assoc($sql_result)) {
            $ret[] = $row;
        }
        return $ret;
    }

    public function lookup($conditions, string $cols = '*', string $table = 'contacts'): array
    {
        $dbh = $this->dbHandle;
        $sql_result = $this->internalGet($conditions, $cols, $table);

        $ret = $dbh->fetch_assoc($sql_result);
        if ($ret === false) {
            throw new \Exception("Single-row query ({$sql_result->queryString}) without result/with error");
        }
        return $ret;
    }

    /**
     * Like {@see get()}, but returns the unfetched PDOStatement result.
     *
     * @param string|(?string|string[])[]|DbAndCondition[] $conditions
     */
    private function internalGet(
        $conditions,
        string $cols = '*',
        string $table = 'contacts',
        array $options = []
    ): PDOStatement {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = "SELECT ";
        if ($options['count'] ?? false) {
            $columns = preg_split("/\s*,\s*/", $cols);
            $columns = array_map(
                function (string $col) use ($dbh): string {
                    return "COUNT($col) as " . $dbh->quote_identifier($col); // quoting needed for "*"
                },
                $columns
            );
            $sql .= implode(", ", $columns);
        } else {
            $sql .= $cols;
        }
        $sql .= " FROM " . $dbh->table_name("carddav_$table");

        // WHERE clause
        $sql .= $this->getConditionsQuery($conditions);

        // ORDER BY clause
        if (is_array($options['order'] ?? null)) {
            $orderClauses = [];

            foreach ($options['order'] as $col) {
                $order = 'ASC';
                if ($col[0] === "!") {
                    $col = substr($col, 1);
                    $order = 'DESC';
                }

                $orderClauses[] = $dbh->quote_identifier($col) . " $order";
            }

            if (!empty($orderClauses)) {
                $sql .= ' ORDER BY ' . implode(", ", $orderClauses);
            }
        }

        $sql_result = false;
        $logger->debug("internalGet query: $sql");
        if (isset($options['limit'])) {
            $l = $options['limit'];
            $err = false;
            if (is_array($l) && count($l) == 2) {
                [ $offset, $limit ] = $l;
                if (is_int($offset) && is_int($limit) && $offset >= 0 && $limit > 0) {
                    $sql_result = $dbh->limitquery($sql, $offset, $limit);
                } else {
                    $err = true;
                }
            } else {
                $err = true;
            }

            if ($err) {
                $msg = "The limit option needs an array parameter of two unsigned integers [offset,limit]; got: ";
                $msg .= print_r($l, true);
                throw new \Exception($msg);
            }
        } else {
            $sql_result = $dbh->query($sql);
        }

        if ($sql_result === false || $dbh->is_error()) {
            $logger->error("Database::get ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $sql_result;
    }

    public function delete($conditions, string $table = 'contacts'): int
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = "DELETE FROM " . $dbh->table_name("carddav_$table");

        // WHERE clause
        $sql .= $this->getConditionsQuery($conditions);

        $logger->debug("Database::delete $sql");

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            $logger->error("Database::delete ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $dbh->affected_rows($sql_result);
    }

    /**
     * Creates a condition query on a database column to be used in an SQL WHERE clause.
     *
     * @see DbOrCondition For a description on the format of field/value specifiers.
     */
    private function getConditionQuery(DbOrCondition $orCond): string
    {
        $dbh = $this->dbHandle;
        $invertCondition = false;
        $ilike = false;

        $field = $orCond->fieldSpec;
        $value = $orCond->valueSpec;

        if ($field[0] === "!") {
            $field = substr($field, 1);
            $invertCondition = true;
        }
        if ($field[0] === "%") {
            $field = substr($field, 1);
            $ilike = true;
        }

        $sql = $dbh->quote_identifier($field);
        if (!isset($value)) {
            $sql .= $invertCondition ? ' IS NOT NULL' : ' IS NULL';
        } elseif (is_array($value)) {
            if (count($value) > 0) {
                if ($ilike) {
                    throw new \Exception("getConditionQuery $field - ILIKE match only supported for single pattern");
                }
                $quoted_values = array_map([ $dbh, 'quote' ], $value);
                $sql .= $invertCondition ? " NOT IN" : " IN";
                $sql .= " (" . implode(",", $quoted_values) . ")";
            } else {
                throw new \Exception("getConditionQuery $field - empty values array provided");
            }
        } else {
            if ($ilike) {
                if ($dbh->db_provider === "mysql") {
                    $sql .= " COLLATE utf8mb4_unicode_ci ";
                }
                $ilikecmd = ($dbh->db_provider === "postgres") ? "ILIKE" : "LIKE";
                $sql .= $invertCondition ? " NOT $ilikecmd " : " $ilikecmd ";
            } else {
                $sql .= $invertCondition ? " <> " : " = ";
            }
            $sql .= $dbh->quote($value);
        }

        return $sql;
    }

    /**
     * Produces the WHERE clause from a $conditions parameter.
     *
     * Normalized, the given $conditions parameter is a list of AND-conditions, i.e. a row must match all the
     * AND-conditions to be included in the result. Each AND-condition can be either a single condition, or a list of
     * OR-conditions, i.e. at least one of the OR-conditions must match for the AND-condition be considered a match.
     * Each individual OR-condition is a two-element array of a field specifier and a value specifier. See
     * getConditionQuery() for details on the interpretation of the field and value specifiers.
     *
     * If conditions is an empty array, no filtering is performed.
     *
     * @param string|(?string|string[])[]|DbAndCondition[] $conditions
     * @return string The WHERE clause, an empty string if no conditions were given.
     *
     * @see AbstractDatabase::normalizeConditions() for a description of $conditions
     */
    private function getConditionsQuery($conditions): string
    {
        $conditions = $this->normalizeConditions($conditions);

        $andCondSql = [];
        foreach ($conditions as $andCond) {
            $orCondSql = [];
            foreach ($andCond->orConditions as $orCond) {
                $orCondSql[] = $this->getConditionQuery($orCond);
            }

            $andCondSql[] = '(' . implode(') OR (', $orCondSql) . ')';
        }

        if (!empty($andCondSql)) {
            $sql = " WHERE (" . implode(') AND (', $andCondSql) . ')';
        }

        return $sql ?? "";
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

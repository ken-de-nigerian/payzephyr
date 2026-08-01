<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

/**
 * Allows a model's table name to be overridden via config, with validation
 * against SQL-injection-shaped values before trusting it.
 *
 * Consuming models must also use LogsToPaymentChannel (for the warning log on
 * an invalid configured name) and define configuredTableNameKey().
 */
trait HasConfigurableTableName
{
    /**
     * Dot-notation path into the 'payments' config array holding the
     * configurable table name, e.g. 'logging.table'.
     */
    abstract protected function configuredTableNameKey(): string;

    public function getTable(): string
    {
        $config = app('payments.config') ?? config('payments', []);
        $tableName = data_get($config, $this->configuredTableNameKey()) ?? $this->table;

        if (! $this->isValidTableName($tableName)) {
            $this->log('warning', 'Invalid table name in config, using default', [
                'attempted_table' => $tableName,
            ]);

            return $this->table;
        }

        return $tableName;
    }

    protected function isValidTableName(string $tableName): bool
    {
        if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $tableName) !== 1) {
            return false;
        }

        if (preg_match('/^\d/', $tableName) === 1) {
            return false;
        }

        return true;
    }
}

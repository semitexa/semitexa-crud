<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit\Relation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\OrmManager;

/**
 * The CRUD screens read pivots through the caller's transaction
 * (tk-getadapter-writes-escape-transactions). Read through getAdapter(), on a
 * pooled connection of its own, a pivot read missed every row the open
 * transaction had written: in the Explorer sandbox a record saved a moment
 * earlier showed no relations. Only a MySQL pool shows it; elsewhere this skips.
 */
final class PivotReadJoinsTransactionTest extends TestCase
{
    #[Test]
    public function a_pivot_row_written_in_the_open_transaction_is_read_back(): void
    {
        $this->requireMysql();
        $table = 'crud_pivot_probe_' . bin2hex(random_bytes(4));
        $seen = null;
        $failure = null;
        $hooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use ($table, &$seen, &$failure): void {
                $orm = new OrmManager();
                $created = false;
                try {
                    $orm->getQueryAdapter()->execute("CREATE TABLE `{$table}` (owner_id VARCHAR(36), related_id VARCHAR(36)) ENGINE=InnoDB");
                    $created = true;
                    $options = (new \ReflectionClass(RelationOptions::class))->newInstanceWithoutConstructor();
                    (new \ReflectionProperty(RelationOptions::class, 'orm'))->setValue($options, $orm);
                    $relation = new RelationMetadata('tags', RelationKind::ManyToMany, \stdClass::class, 'owner_id', $table, 'related_id');
                    $probe = new \RuntimeException('deliberate rollback');
                    try {
                        $orm->getTransactionManager()->run(static function () use ($orm, $options, $relation, $table, $probe, &$seen): void {
                            $orm->getQueryAdapter()->execute("INSERT INTO `{$table}` (owner_id, related_id) VALUES ('o1', 'r1')");
                            $seen = $options->pivotIds($relation, ['o1']);
                            throw $probe;
                        });
                    } catch (\RuntimeException $e) {
                        if ($e !== $probe) {
                            throw $e;
                        }
                    }
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    try {
                        if ($created) {
                            $orm->getQueryAdapter()->execute("DROP TABLE IF EXISTS `{$table}`");
                        }
                    } catch (\Throwable $e) {
                        $failure ??= $e;
                    } finally {
                        $orm->shutdown();
                    }
                }
            });
        } finally {
            // Hooks off outside a coroutine (on there, they broke PDO in later
            // tests); every later Co\run() hooking as it did before.
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame(['o1' => ['r1']], $seen, 'the pivot read sees the row the transaction wrote');
    }

    private function requireMysql(): void
    {
        $orm = new OrmManager();
        try {
            $cliHost = Environment::getEnvValue('DB_CLI_HOST');
            $host = !file_exists('/.dockerenv') && $cliHost !== null && $cliHost !== '' ? $cliHost : Environment::getEnvValue('DB_HOST');
            if ($orm->getDriver() !== 'mysql' || $host === null || $host === '') {
                self::markTestSkipped('needs the MySQL pool: the escape shows only on a second connection');
            }
            $orm->getAdapter()->execute('SELECT 1');
        } finally {
            $orm->shutdown();
        }
    }
}

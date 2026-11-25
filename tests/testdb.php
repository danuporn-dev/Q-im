<?php
declare(strict_types=1);

// Throw-away test database. The name is fixed: nothing else is ever created, written or dropped by tests.
// Include before anything reads the configuration.

use App\Support\Database;
use App\Support\Env;

final class TestDb
{
    public const NAME = 'qim_test';
    private const PROTECTED = 'qim_dev';

    private static ?PDO $server = null;
    private static string $before = '';
    private static bool $owned = false;

    /** Creates qim_test from nothing: all migrations, twice, then the seed, twice. */
    public static function begin(): void
    {
        // This process and every child process now see only the test database.
        putenv('DB_NAME=' . self::NAME);
        require_once dirname(__DIR__) . '/app/bootstrap.php';
        $refuse = match (true) {
            PHP_SAPI !== 'cli' => 'command line only',
            !Env::isDevelopment() => 'APP_ENV must be development',
            !in_array(Env::get('DB_HOST', '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true) => 'DB_HOST must be this computer',
            Database::name() !== self::NAME => 'the test database must be ' . self::NAME,
            default => null,
        };
        if ($refuse !== null) {
            self::stop("Refusing: $refuse.");
        }
        self::$server = Database::connect(false);
        if ((int) self::$server->query("SELECT GET_LOCK('qim_test_run', 0)")->fetchColumn() !== 1) {
            self::stop('Refusing: another test run is using ' . self::NAME . '.');
        }
        self::$before = self::fingerprint();
        self::$owned = true;
        register_shutdown_function([self::class, 'finish']);
        self::drop(); // left over from an interrupted run
        self::script('migrate.php');
        // Forget the history: every migration must also survive being run again on existing tables.
        Database::run('DELETE FROM schema_migrations');
        self::script('migrate.php');
        self::script('migrate.php');
        self::script('seed.php');
        self::script('seed.php');
        $versions = array_column(Database::rows('SELECT version FROM schema_migrations ORDER BY version'), 'version');
        echo 'Created ' . self::NAME . ' with ' . count($versions) . ' migrations (' . reset($versions) . ' to ' . end($versions) . ") and seed data, each run repeated\n";
    }

    /** Drops qim_test. Returns false when qim_dev changed during the run. */
    public static function finish(): bool
    {
        if (!self::$owned) {
            return true;
        }
        self::$owned = false;
        Servers::stopAll();
        self::drop();
        $same = self::$before === self::fingerprint();
        echo self::NAME . ' dropped; ' . self::PROTECTED . ' checksum ' . ($same ? 'unchanged' : 'CHANGED (tests never write to it; was the site used during the run?)') . "\n";
        return $same;
    }

    private static function drop(): void
    {
        self::$server->exec('DROP DATABASE IF EXISTS ' . self::NAME);
    }

    private static function fingerprint(): string
    {
        $sums = [];
        foreach (self::$server->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . self::PROTECTED . "' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $sums[$table] = self::$server->query('CHECKSUM TABLE `' . self::PROTECTED . "`.`$table`")->fetch()['Checksum'];
        }
        return hash('sha256', json_encode($sums, JSON_THROW_ON_ERROR));
    }

    private static function script(string $name): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . "/scripts/$name") . ' 2>&1', $out, $code);
        if ($code !== 0) {
            self::stop("$name failed on " . self::NAME . ': ' . implode("\n", $out));
        }
    }

    private static function stop(string $message): never
    {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

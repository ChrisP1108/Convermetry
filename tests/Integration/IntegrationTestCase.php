<?php
declare(strict_types=1);

namespace Convermetry\Tests\Integration;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Goals\GoalCompletions;
use Convermetry\Leads\LeadEvents;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Base for every integration test: a live connection, the plugin's own schema,
 * and empty tables per test.
 *
 * THE SCHEMA COMES FROM THE PLUGIN'S SOURCE, not from a copy maintained here.
 * Each table owner's CREATE TABLE is extracted from its own file and executed,
 * so these tests can never pass against a schema the plugin does not actually
 * ship — which is the failure a duplicated DDL invites.
 *
 * dbDelta is not involved. That is a genuine gap and is stated plainly in the
 * suite bootstrap: this proves the DDL does what the plugin claims, not that
 * dbDelta applies it correctly to a pre-existing table.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ?TestWpdb $db = null;

    /**
     * Opens the connection once and installs the schema.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$db ??= TestWpdb::fromEnvironment();

        if (self::$db === null) {
            return;
        }

        $GLOBALS['wpdb'] = self::$db;

        self::installSchema();
    }

    /**
     * Skips when no database is reachable, and empties the tables otherwise.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$db === null) {
            self::markTestSkipped(
                'No test database reachable. Set CVM_TEST_DB_HOST / CVM_TEST_DB_SOCKET (and friends) to run '
                . 'the integration suite; see tests/Integration/bootstrap.php.'
            );
        }

        $GLOBALS['wpdb']                = self::$db;
        $GLOBALS['cvm_test_options']    = [];
        $GLOBALS['cvm_test_transients'] = [];

        // Keyed by table name — iterate the KEYS. Iterating values here would
        // silently issue "TRUNCATE TABLE Convermetry\Database\DatabaseManager",
        // which fails, leaves every table populated, and makes each test see
        // the previous one's rows.
        foreach (array_keys(self::tables()) as $table) {
            if (self::$db->query('TRUNCATE TABLE ' . $table) === false) {
                self::fail('Could not truncate ' . $table . ': ' . self::$db->last_error);
            }
        }
    }

    /**
     * The tables this suite manages.
     *
     * @return array<string, class-string>
     */
    protected static function tables(): array
    {
        return [
            'wp_cvm_events'           => DatabaseManager::class,
            'wp_cvm_form_submissions' => FormSubmissions::class,
            'wp_cvm_goal_completions' => GoalCompletions::class,
            'wp_cvm_lead_events'      => LeadEvents::class,
        ];
    }

    /**
     * Creates every table from the plugin's own CREATE TABLE statements.
     *
     * @return void
     */
    protected static function installSchema(): void
    {
        foreach (self::tables() as $table => $owner) {
            self::$db->query('DROP TABLE IF EXISTS ' . $table);
            self::$db->query(self::ddlFor($owner, $table));
        }
    }

    /**
     * Extracts one owner's CREATE TABLE and resolves its interpolations.
     *
     * @param class-string $owner Table owner class.
     * @param string       $table Fully-prefixed table name.
     * @return string
     */
    protected static function ddlFor(string $owner, string $table): string
    {
        $source = (string) file_get_contents((string) (new ReflectionClass($owner))->getFileName());

        $start = strpos($source, '$sql = "CREATE TABLE');
        if ($start === false) {
            throw new \RuntimeException($owner . ' has no recognizable CREATE TABLE assignment.');
        }

        $start += strlen('$sql = "');
        $end    = strpos($source, '{$charset};"', $start);

        if ($end === false) {
            throw new \RuntimeException($owner . "'s CREATE TABLE has no recognizable end.");
        }

        $ddl = substr($source, $start, $end - $start);

        return str_replace(
            ['{$table}', '{$charset}'],
            [$table, self::$db->get_charset_collate()],
            $ddl
        ) . self::$db->get_charset_collate();
    }

    /**
     * Inserts one events row and returns its id.
     *
     * @param array<string, mixed> $row Column overrides.
     * @return int
     */
    protected function insertEvent(array $row): int
    {
        $defaults = [
            'event_type'   => 'pageview',
            'page_url'     => 'https://example.com/',
            'element_label' => '',
            'element_tag'  => '',
            'target_url'   => '',
            'event_value'  => '',
            'session_id'   => 'sess0001',
            'form_key'     => '',
            'channel'      => '',
            'utm_campaign' => '',
            'created_at'   => '2026-08-10 09:00:00',
        ];

        $row     = array_merge($defaults, $row);
        $columns = array_keys($row);

        $placeholders = implode(', ', array_fill(0, count($columns), '%s'));

        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_events (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')',
            array_values($row)
        ));

        return self::$db->insert_id;
    }

    /**
     * Inserts one submission row.
     *
     * @param array<string, mixed> $row Column overrides.
     * @return int
     */
    protected function insertSubmission(array $row): int
    {
        static $counter = 0;
        $counter++;

        $defaults = [
            'submission_id' => 'sub' . str_pad((string) $counter, 10, '0', STR_PAD_LEFT),
            'conversion_id' => 'conv' . str_pad((string) $counter, 10, '0', STR_PAD_LEFT),
            'session_id'    => 'sess0001',
            'provider'      => 'gravityforms',
            'form_key'      => 'gravityforms:7',
            'form_name'     => 'Contact',
            'page_url'      => 'https://example.com/contact/',
            'channel'       => 'Paid Search',
            'utm_campaign'  => 'spring',
            'utm_source'    => 'google',
            'utm_medium'    => 'cpc',
            'landing_page'  => 'https://example.com/land/',
            'lead_status'   => 'new',
            'lead_currency' => '',
            'created_at'    => '2026-08-10 09:00:00',
        ];

        $row     = array_merge($defaults, $row);
        $columns = array_keys($row);

        $placeholders = [];
        $values       = [];

        foreach ($columns as $column) {
            if ($row[$column] === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholders[] = '%s';
            $values[]       = $row[$column];
        }

        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_form_submissions (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', $placeholders) . ')',
            $values
        ));

        return self::$db->insert_id;
    }

    /**
     * The index names present on a table, excluding the primary key.
     *
     * @param string $table Table name.
     * @return list<string>
     */
    protected function indexesOn(string $table): array
    {
        $rows = self::$db->get_results(self::$db->prepare(
            'SELECT DISTINCT INDEX_NAME AS n FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME <> %s',
            $table,
            'PRIMARY'
        ));

        return array_map(static fn(array $row): string => (string) $row['n'], $rows);
    }

    /**
     * The column names present on a table.
     *
     * @param string $table Table name.
     * @return list<string>
     */
    protected function columnsOn(string $table): array
    {
        $rows = self::$db->get_results(self::$db->prepare(
            'SELECT COLUMN_NAME AS n FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        return array_map(static fn(array $row): string => (string) $row['n'], $rows);
    }
}

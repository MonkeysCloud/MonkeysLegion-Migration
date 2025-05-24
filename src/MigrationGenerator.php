<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration;

use DateTimeImmutable;
use MonkeysLegion\Database\MySQL\Connection;
use ReflectionClass;
use ReflectionProperty;
use MonkeysLegion\Entity\Attributes\Column as ColumnAttr;

final class MigrationGenerator
{
    public function __construct(private Connection $db) {}

    /**
     * Generate a migration class file under var/migrations/.
     *
     * @param ReflectionClass[] $entities Array of entity ReflectionClasses
     * @param array             $schema   Current DB schema [table => [col=>def]]
     * @param string            $name     Optional name suffix (e.g. 'create_users_table')
     * @return string                     Full path to the created PHP file
     */
    public function generate(array $entities, array $schema, string $name = 'migration'): string
    {
        // 1) Compose the UP SQL from your diff()
        $sqlUp = trim($this->diff($entities, $schema));

        // 2) Auto-reverse it for DOWN
        $sqlDown = $this->autoReverse($sqlUp);

        // 3) Build class & file name
        $ts    = (new DateTimeImmutable())->format('YmdHis');
        $slug  = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
        $class = "M{$ts}" . ucfirst($slug);
        $file  = base_path("var/migrations/{$class}.php");

        // 4) Render the migration stub
        $template = <<<PHP
<?php
declare(strict_types=1);

namespace App\\Migration;

use MonkeysLegion\\Database\\MySQL\\Connection;

final class {$class}
{
    public function up(Connection \$db): void
    {
        \$db->pdo()->exec(<<<'SQL'
{$sqlUp}
SQL);
    }

    public function down(Connection \$db): void
    {
        \$db->pdo()->exec(<<<'SQL'
{$sqlDown}
SQL);
    }
}
PHP;

        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $template);

        return $file;
    }

    /**
     * Compute the SQL needed to bring the DB *up* to match your entities.
     *
     * @param ReflectionClass[] $entities
     * @param array             $schema   [table => [column => definition]]
     * @return string
     */
    public function diff(array $entities, array $schema): string
    {
        $sql = '';

        foreach ($entities as $ref) {
            $table = strtolower($ref->getShortName()) . 's';

            // 1) Table doesn’t exist → full CREATE
            if (!isset($schema[$table])) {
                $sql .= $this->createTableSql($ref, $table) . "\n\n";
                continue;
            }

            // 2) Table exists → add new columns
            $existingCols = array_keys($schema[$table]);
            foreach ($this->getColumnDefinitions($ref) as $colName => $colDef) {
                if (!in_array($colName, $existingCols, true)) {
                    $sql .= "ALTER TABLE `{$table}` ADD COLUMN {$colDef};\n";
                }
            }

            $sql .= "\n";
        }

        return trim($sql);
    }

    private function createTableSql(ReflectionClass $ref, string $table): string
    {
        $cols = $this->getColumnDefinitions($ref);
        $defs = implode(",\n  ", $cols);
        return <<<SQL
CREATE TABLE `{$table}` (
  {$defs},
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
    }

    /**
     * @return array<string,string>  colName => full SQL fragment (e.g. "`name` VARCHAR(255) NOT NULL")
     */
    private function getColumnDefinitions(ReflectionClass $ref): array
    {
        $defs = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();

            // Map PHP type to SQL
            $type    = $prop->getType()?->getName() ?? 'string';
            $sqlType = match (strtolower($type)) {
                'int','integer'           => 'INT',
                'float','double'          => 'DOUBLE',
                'bool','boolean'          => 'TINYINT(1)',
                'datetimeimmutable',
                'datetime'                => 'TIMESTAMP NULL',
                'text'                    => 'TEXT',
                default                   => 'VARCHAR(255)',
            };

            // Override via #[Column] attribute?
            $colAttrs = $prop->getAttributes(ColumnAttr::class);
            if ($colAttrs) {
                /** @var ColumnAttr $attr */
                $attr     = $colAttrs[0]->newInstance();
                $sqlType  = strtoupper($attr->type ?? $sqlType);
                $length   = $attr->length ? "({$attr->length})" : '';
                $nullable = $attr->nullable ? ' NULL' : ' NOT NULL';
                $defs[$name] = "`{$name}` {$sqlType}{$length}{$nullable}";
                continue;
            }

            $nullable = str_contains($sqlType, 'NULL') ? '' : ' NOT NULL';
            $defs[$name] = "`{$name}` {$sqlType}{$nullable}";
        }

        return $defs;
    }

    /**
     * Attempt to auto-reverse common operations:
     *  - CREATE TABLE → DROP TABLE IF EXISTS
     *  - ALTER TABLE ADD COLUMN → ALTER TABLE DROP COLUMN
     * Otherwise emits a `-- TODO` marker for manual fix.
     */
    private function autoReverse(string $sql): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $sql)));
        $out   = [];

        foreach ($lines as $line) {
            if (preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?/', $line, $m)) {
                $out[] = "DROP TABLE IF EXISTS `{$m[1]}`;";
            } elseif (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/i', $line, $m)) {
                $out[] = "ALTER TABLE `{$m[1]}` DROP COLUMN `{$m[2]}`;";
            } else {
                $out[] = "-- TODO reverse: {$line}";
            }
        }

        return implode("\n", $out);
    }
}
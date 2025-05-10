<?php
declare(strict_types=1);

namespace MonkeysLegion\Migration;

use ReflectionClass;
use ReflectionProperty;
use MonkeysLegion\Entity\Attributes\Column as ColumnAttr;

final class MigrationGenerator
{
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

            // 2) Table exists → check each column
            $existingCols = array_keys($schema[$table]);
            foreach ($this->getColumnDefinitions($ref) as $colName => $colDef) {
                if (!in_array($colName, $existingCols, true)) {
                    $sql .= "ALTER TABLE `{$table}` ADD COLUMN {$colDef};\n";
                }
            }

            $sql .= "\n";
        }

        return $sql;
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
     * @return array<string,string>  colName => full SQL fragment
     */
    private function getColumnDefinitions(ReflectionClass $ref): array
    {
        $defs = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // skip readonly id if you want, else include
            $name = $prop->getName();

            // Determine SQL type
            $type = $prop->getType()?->getName() ?? 'string';
            $sqlType = match (strtolower($type)) {
                'int', 'integer'        => 'INT',
                'float', 'double'       => 'DOUBLE',
                'bool', 'boolean'       => 'TINYINT(1)',
                'datetimeimmutable',
                'datetime'              => 'TIMESTAMP NULL',
                'text'                  => 'TEXT',
                default                 => 'VARCHAR(255)',
            };

            // Check for a Column attribute override
            $colAttrs = $prop->getAttributes(ColumnAttr::class);
            if (!empty($colAttrs)) {
                /** @var ColumnAttr $attr */
                $attr        = $colAttrs[0]->newInstance();
                $sqlType     = strtoupper($attr->type ?? $sqlType);
                $length      = $attr->length ? "({$attr->length})" : '';
                $nullable    = $attr->nullable ? ' NULL' : ' NOT NULL';
                $sqlType    .= $length . $nullable;
                $defs[$name] = "`{$name}` {$sqlType}";
                continue;
            }

            // default NOT NULL except timestamp
            $nullable = str_contains($sqlType, 'NULL') ? '' : ' NOT NULL';
            $defs[$name] = "`{$name}` {$sqlType}{$nullable}";
        }

        return $defs;
    }
}
<?php

namespace bizley\migration;

use Yii;
use yii\base\InvalidParamException;
use yii\db\ColumnSchema;
use yii\db\Expression;
use yii\db\Schema;
use yii\db\TableSchema;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

/**
 * Migration file generator.
 *
 * @author Paweł Bizley Brzozowski
 * @version 2.1.2
 * @license Apache 2.0
 * https://github.com/bizley/yii2-migration
 */
class Generator extends Extractor
{
    /**
     * Generates migration content or echoes exception message.
     * @return string
     * @throws InvalidParamException
     */
    public function generateMigration()
    {
        $this->checkSchema();
        $pk = $this->getTablePrimaryKey();
        $comment = $this->getTableComment($this->tableName);
        $params = [
            'tableName' => $this->generateTableName($this->tableName),
            'tableComment' => $comment,
            'className' => $this->className,
            'columns' => $this->prepareColumnsDefinitions(count($pk) > 1),
            'primaryKey' => $pk,
            'foreignKeys' => $this->prepareForeignKeysDefinitions(),
            'uniqueIndexes' => $this->getTableUniqueIndexes(),
            'namespace' => !empty($this->namespace) ? FileHelper::normalizePath($this->namespace, '\\') : null,
            'indexKeyTypes' => $this->indexKeyTypes
        ];
        return $this->view->renderFile(Yii::getAlias($this->templateFile), $params);
    }

    public function generateFillMigration() {

        $this->checkSchema();
        $pk = $this->getTablePrimaryKey();

        $dbname = $this->getDBName();

        # Получаем имена столбцов
        $columns = new \yii\db\Query();
        $columns = $columns
            ->select('*')
            ->from('INFORMATION_SCHEMA.COLUMNS')
            ->where(['TABLE_NAME'=>$this->tableName,'TABLE_SCHEMA'=>$dbname])
            ->all();

        $cols = ArrayHelper::getColumn($columns,'COLUMN_NAME');

        # Генерим строку массива, экранируя лишь то, что за массив может выйти
        # остальное будет проэкранировано средствами самого Yii при установке миграции
        # т.к. передаём только массив
        # так же генерим запрос на анализ NULL значений
        $coldata = '';
        $select = [];
        foreach ($cols as $col) {
            $coldata .= "'".str_replace("'","\\'",$col)."',";
            $select[] = "`$col` is NULL as NULL".$col;
            $select[] = $col;
        }
        $coldata = "[".trim($coldata,",")."]";
        $select = trim(implode(",",$select),",");

        # Получаем список записей
        $query = new \yii\db\Query();
        $query=$query -> select($select)
               -> from($this->tableName)
               ->all();


        # Генерим верный формат массива
        $data = '';
        foreach($query as $row) {
            $linerow = "";
            $nextnull = false;
            foreach ($row as $key=>$item) {

                # Заменяем пустые строки на нули
                if(substr($key,0,4)==="NULL") {
                    $nextnull = $item=="1";
                }
                elseif ($nextnull  &&  $item===NULL) {
                    $linerow.="NULL,";
                }
                else
                    $linerow.="'".str_replace("'","\\'",$item)."',";

            }
            $data .= "[".trim($linerow,",")."],\r\n         ";
        }
        $data = "[".trim($data,",")."]";

        $params = [
            'rows' => $data,
            'columns' => $coldata,
            'namespace' => !empty($this->namespace) ? FileHelper::normalizePath($this->namespace, '\\') : null,
            'tableName' => $this->generateTableName($this->tableName),
            'className' => $this->className,
            'primaryKey' => $pk,
        ];

        return $this->view->renderFile(Yii::getAlias($this->templateFile), $params);

    }

    /**
     * Получить имя базы
     * @return null
     */
    public function getDBName() {
        $db = Yii::$app->getDb();
        return $this->getDsnAttribute('dbname', $db->dsn);
    }

    /**
     * Получить аттрибут DSN для извлечения имени базы
     * @param $name
     * @param $dsn
     * @return null
     */
    private function getDsnAttribute($name, $dsn)
    {
        if (preg_match('/' . $name . '=([^;]*)/', $dsn, $match)) {
            return $match[1];
        } else {
            return null;
        }
    }

    /**
     * Получить комментарий к таблице
     * @param $tableName
     * @return string
     */
    public function getTableComment($tableName) {

        # Получаем имена столбцов
        $comment = new \yii\db\Query();
        $comment = $comment
            ->select('table_comment')
            ->from('INFORMATION_SCHEMA.TABLES')
            ->where(['TABLE_NAME'=>$this->tableName,'TABLE_SCHEMA'=>$this->getDBName()])
            ->scalar();

        return $comment;

    }

    /**
     * Prepares definitions of columns.
     * @param bool $compositePk whether primary key is composite
     * @return array
     */
    public function prepareColumnsDefinitions($compositePk = false)
    {
        $columns = [];
        if ($this->tableSchema instanceof TableSchema) {
            foreach ($this->tableSchema->columns as $column) {
                $columns[$column->name] = $this->renderColumnDefinition($column, $compositePk);
            }
        }
        return $columns;
    }

    /**
     * Prepares definitions of foreign keys.
     * @return array
     */
    public function prepareForeignKeysDefinitions()
    {
        $keys = [];
        if ($this->tableSchema instanceof TableSchema) {
            foreach ($this->tableSchema->foreignKeys as $name => $key) {
                $keys[] = $this->renderKeyDefinition($name, $key);
            }
        }
        return $keys;
    }

    /**
     * Returns size value from ColumnSchema.
     * @param ColumnSchema $column
     * @return string
     */
    public function renderSize(ColumnSchema $column)
    {
        return empty($column->size) && !is_numeric($column->size) ? null : $column->size;
    }

    /**
     * Returns scale value from ColumnSchema.
     * @param ColumnSchema $column
     * @return string
     */
    public function renderScale(ColumnSchema $column)
    {
        return empty($column->scale) && !is_numeric($column->scale) ? null : $column->scale;
    }

    /**
     * Returns precision value from ColumnSchema.
     * @param ColumnSchema $column
     * @return string
     */
    public function renderPrecision(ColumnSchema $column)
    {
        return empty($column->precision) && !is_numeric($column->precision) ? null : $column->precision;
    }

    /**
     * Returns column definition based on ColumnSchema.
     * @param ColumnSchema $column
     * @param bool $compositePk whether primary key is composite
     * @return string
     */
    public function renderColumnDefinition(ColumnSchema $column, $compositePk = false)
    {
        $definition = '';
        $size = '';
        $checkPrimaryKey = true;
        $checkNotNull = true;
        $checkUnsigned = true;
        $schema = $this->db->schema;
        switch ($column->type) {
            case Schema::TYPE_PK:
            case Schema::TYPE_UPK:
            case Schema::TYPE_BIGPK:
            case Schema::TYPE_UBIGPK:
            case Schema::TYPE_CHAR:
            case Schema::TYPE_STRING:
            case Schema::TYPE_TEXT:
            case Schema::TYPE_SMALLINT:
            case Schema::TYPE_INTEGER:
            case Schema::TYPE_BIGINT:
            case Schema::TYPE_BINARY:
                $size = $this->renderSize($column);
                break;
            case Schema::TYPE_FLOAT:
            case Schema::TYPE_DOUBLE:
            case Schema::TYPE_DATETIME:
            case Schema::TYPE_TIMESTAMP:
            case Schema::TYPE_TIME:
                $size = $this->renderPrecision($column);
                break;
            case Schema::TYPE_DECIMAL:
            case Schema::TYPE_MONEY:
                $size = $this->renderPrecision($column) . ',' . $this->renderScale($column);
        }
        if ($this->generalSchema) {
            $size = '';
        }
        switch ($column->type) {
            case Schema::TYPE_UPK:
                if ($this->generalSchema) {
                    $checkUnsigned = false;
                    $definition .= '->unsigned()';
                }
                // no break
            case Schema::TYPE_PK:
                if ($this->generalSchema) {
                    $checkPrimaryKey = false;
                    if ($schema::className() !== 'yii\db\mssql\Schema') {
                        $checkNotNull = false;
                    }
                }
                $definition .= '->primaryKey(' . $size . ')';
                break;
            case Schema::TYPE_UBIGPK:
                if ($this->generalSchema) {
                    $checkUnsigned = false;
                    $definition .= '->unsigned()';
                }
                // no break
            case Schema::TYPE_BIGPK:
                if ($this->generalSchema) {
                    $checkPrimaryKey = false;
                    if ($schema::className() !== 'yii\db\mssql\Schema') {
                        $checkNotNull = false;
                    }
                }
                $definition .= '->bigPrimaryKey(' . $size . ')';
                break;
            case Schema::TYPE_CHAR:
                $definition .= '->char(' . $size . ')';
                break;
            case Schema::TYPE_STRING:
                $definition .= '->string(' . $size . ')';
                break;
            case Schema::TYPE_TEXT:
                $definition .= '->text(' . $size . ')';
                break;
            case Schema::TYPE_SMALLINT:
                $definition .= '->smallInteger(' . $size . ')';
                break;
            case Schema::TYPE_INTEGER:
                if ($this->generalSchema) {
                    if (!$compositePk && $column->isPrimaryKey) {
                        $checkPrimaryKey = false;
                        if ($schema::className() !== 'yii\db\mssql\Schema') {
                            $checkNotNull = false;
                        }
                        $definition .= '->primaryKey()';
                    } else {
                        $definition .= '->integer()';
                    }
                } else {
                    $definition .= '->integer(' . $size . ')';
                }
                break;
            case Schema::TYPE_BIGINT:
                if ($this->generalSchema) {
                    if (!$compositePk && $column->isPrimaryKey) {
                        $checkPrimaryKey = false;
                        if ($schema::className() !== 'yii\db\mssql\Schema') {
                            $checkNotNull = false;
                        }
                        $definition .= '->bigPrimaryKey()';
                    } else {
                        $definition .= '->bigInteger()';
                    }
                } else {
                    $definition .= '->bigInteger(' . $size . ')';
                }
                break;
            case Schema::TYPE_FLOAT:
                $definition .= '->float(' . $size . ')';
                break;
            case Schema::TYPE_DOUBLE:
                $definition .= '->double(' . $size . ')';
                break;
            case Schema::TYPE_DECIMAL:
                $definition .= '->decimal(' . $size . ')';
                break;
            case Schema::TYPE_DATETIME:
                $definition .= '->dateTime(' . $size . ')';
                break;
            case Schema::TYPE_TIMESTAMP:
                $definition .= '->timestamp(' . $size . ')';
                break;
            case Schema::TYPE_TIME:
                $definition .= '->time(' . $size . ')';
                break;
            case Schema::TYPE_DATE:
                $definition .= '->date()';
                break;
            case Schema::TYPE_BINARY:
                $definition .= '->binary(' . $size . ')';
                break;
            case Schema::TYPE_BOOLEAN:
                $definition .= '->boolean()';
                break;
            case Schema::TYPE_MONEY:
                $definition .= '->money(' . $size . ')';
                break;
        }
        if ($checkUnsigned && $column->unsigned) {
            $definition .= '->unsigned()';
        }
        if ($checkNotNull && !$column->allowNull) {
            $definition .= '->notNull()';
        }
        if ($column->defaultValue !== null) {
            if ($column->defaultValue instanceof Expression) {
                $definition .= '->defaultExpression(\'' . $column->defaultValue->expression . '\')';
            } else {
                $definition .= '->defaultValue(\'' . $column->defaultValue . '\')';
            }
        }
        if ($column->comment) {
            $definition .= '->comment(\'' . $column->comment . '\')';
        }
        if (!$compositePk && $checkPrimaryKey && $column->isPrimaryKey) {
            $definition .= '->append(\'' . $this->prepareSchemaAppend(true, $column->autoIncrement) . '\')';
        }

        return $definition;
    }

    /**
     * Returns foreign key definition based on key array.
     * Since 1.1 key name can be taken from tableSchema.
     * @param string $name key name
     * @param array $key key parameters
     * @return string
     */
    public function renderKeyDefinition($name, $key)
    {
        $refTable = ArrayHelper::remove($key, 0);
        $columns = [];
        $refColumns = [];
        foreach ($key as $column => $refColumn) {
            $columns[] = $column;
            $refColumns[] = $refColumn;
        }
        if (empty($name) || is_numeric($name)) {
            $name = $this->generateForeignKeyName($column);
        }

        return implode(', ', [
            "'$name'",
            "'{$this->generateTableName($this->tableName)}'",
            count($columns) === 1 ? '\'' . $columns[0] . '\'' : '[\'' . implode('\',\'', $columns) . '\']',
            "'{$this->generateTableName($refTable)}'",
            count($refColumns) === 1 ? '\'' . $refColumns[0] . '\'' : '[\'' . implode('\',\'', $refColumns) . '\']',
            "'CASCADE'",
            "'CASCADE'",
        ]);
    }

    /**
     * Returns foreign key name.
     * @param string $column
     * @return string
     */
    public function generateForeignKeyName($column)
    {
        return implode('-', ['fk', $this->tableName, $column]);
    }
}

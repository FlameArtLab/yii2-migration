<?php
/**
 * This is the template for generating the migration of a specified table.
 *
 * @var $tableName string full table name
 * @var $className string class name
 * @var $namespace string namespace
 * @var $columns array columns definitions
 * @var $primaryKey array primary key definition
 * @var $foreignKeys array foreign keys arrays
 * @var $uniqueIndexes array unique indexes arrays
 */

echo "<?php\n";
?>

<?php if ($namespace): ?>
namespace <?= $namespace ?>;
<?php echo "\n"; endif; ?>
use yii\db\Migration;

class <?= $className ?> extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        \Yii::$app->db->createCommand()->batchInsert(
            "<?=$tableName?>",
            <?=$columns?>,
            <?=$rows?>)
            ->execute();

    }

    public function safeDown()
    {
    }
}

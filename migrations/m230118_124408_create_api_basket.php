<?php

use yii\db\Migration;

class m230118_124408_create_api_basket extends Migration
{
    private $table = '{{%api_basket}}';
    private $tableToken = '{{%api_refresh_token}}';
    private $tableBasket = '{{%basket}}';

    public function safeUp()
    {
        $this->createTable($this->table, [
            'basket_id' => $this->integer()->notNull()->comment('ID корзины'),
            'identified_by' => $this->string(26)->notNull()->comment('Идентификатор токена в формате ULID'),
        ], 'CHARACTER SET utf8 COLLATE utf8_general_ci ENGINE=InnoDB COMMENT \'Связь токена и корзины для mobile-api\'');

        $this->addForeignKey(
            'FK-api_basket-basket',
            $this->table,
            'basket_id',
            $this->tableBasket,
            'id',
            'CASCADE',
        );

        $this->addForeignKey(
            'FK-api_basket-api_refresh_token',
            $this->table,
            'identified_by',
            $this->tableToken,
            'identified_by',
            'CASCADE',
        );

        $this->createIndex(
            'UNIQ-identified_by',
            $this->table,
            [
                'identified_by',
            ],
            true,
        );
    }

    public function safeDown()
    {
        $this->dropTable($this->table);
    }
}

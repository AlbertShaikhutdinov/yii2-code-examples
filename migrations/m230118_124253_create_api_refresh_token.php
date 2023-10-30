<?php

use yii\db\Migration;

class m230118_124253_create_api_refresh_token extends Migration
{
    private $table = '{{%api_refresh_token}}';
    private $tableUser = '{{%user}}';

    public function safeUp()
    {
        $this->createTable($this->table, [
            'id' => $this->primaryKey()->comment('ID'),
            'user_id' => $this->integer()->null()->defaultValue(null)->comment('ID пользователя'),
            'token' => $this->string(1000)->notNull()->comment('Refresh token'),
            'identified_by' => $this->string(26)->notNull()->comment('Идентификатор токена в формате ULID'),
            'ip' => $this->string(50)->notNull()->comment('IP клиента'),
            'user_agent' => $this->string(1000)->notNull()->comment('Признак user_agent клиента'),
            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Создано'),
        ], 'CHARACTER SET utf8 COLLATE utf8_general_ci ENGINE=InnoDB COMMENT \'Параметры процесса JWT авторизации\'');

        $this->addForeignKey(
            'FK-api_refresh_token-user',
            $this->table,
            'user_id',
            $this->tableUser,
            'id',
            'CASCADE',
        );

        $this->createIndex(
            'UNIQ-token',
            $this->table,
            [
                'token',
            ],
            true,
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

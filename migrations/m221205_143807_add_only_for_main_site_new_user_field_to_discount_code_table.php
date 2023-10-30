<?php

use yii\db\Migration;

class m221205_143807_add_only_for_main_site_new_user_field_to_discount_code_table extends Migration
{
    private string $table = '{{%discount_code}}';

    public function safeUp()
    {
        $this->addColumn($this->table, 'only_for_main_site_new_user', $this->boolean()->defaultValue(false));
    }

    public function safeDown()
    {
        $this->dropColumn($this->table, 'only_for_main_site_new_user');
    }
}

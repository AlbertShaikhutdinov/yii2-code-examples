<?php

namespace app\models;

/**
 * Модель данных для хранения заказов покупателя со статусом "Передан в ТК Ozon Логистика" сервиса "Мой склад"
 * в таблице "my_warehouse_orders_transmitted_in_ozon"
 *
 * @property integer $id
 * @property string $my_warehouse_order_id
 */
class MyWarehouseOrdersTransmittedInOzon extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%my_warehouse_orders_transmitted_in_ozon}}';
    }

    public function attributeLabels()
    {
        return [
            'id' => '',
            'my_warehouse_order_id' => 'GUID заказа покупателя из сервиса "Мой склад"',
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['my_warehouse_order_id'], 'string'],
        ];
    }
}

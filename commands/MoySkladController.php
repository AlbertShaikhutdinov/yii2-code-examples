<?php

namespace app\commands;

use app\helpers\GeneratorHelper;
use app\helpers\IntegrationHelper;
use app\helpers\MyWarehouseHelper;
use app\helpers\payment\TaxHelper;
use app\helpers\RetailCrmHelper;
use app\models\MyWarehouseOrdersTransmittedInOzon;
use app\models\ProductsComplectsToItems;
use app\models\ProductsItem;
use DateTime;
use Psr\Log\LoggerInterface;
use Throwable;
use yii\console\Controller;
use yii\helpers\BaseConsole;
use yii\helpers\Json;

class MoySkladController extends Controller
{
    private const LOG_LEVEL_ERROR = 'error';
    private const LOG_LEVEL_INFO = 'info';

    public function __construct(
        $id,
        $module,
        $config = [],
        protected LoggerInterface $logger,
    )
    {
        parent::__construct($id, $module, $config);
    }

    public function actionSynchIncomeDates()
    {
        $dateFormat = 'Y-m-d H:i:s';
        $startDate = date('Y-m-d 00:00:00');
        $startDateTimestamp = DateTime::createFromFormat($dateFormat, $startDate)->getTimestamp();
        $out = 'moy-sklad/synch-income-dates cron started at ' . date($dateFormat) . PHP_EOL;
        $this->stdout($out, BaseConsole::FG_GREEN);

        $myWarehouseInstance = MyWarehouseHelper::getInstance();
        $purchaseOrders = $myWarehouseInstance->getRowGenerator('purchaseorders', [], MyWarehouseHelper::VERSION_1_2);
        $rowResultDates = [];
        $resultDates = []; //ext_code => available_date в таблице products_item

        foreach ($purchaseOrders as $row) {
            //Обрабатываем документ Заказ поставщику если
            //установлена Планируемая дата приемки
            //и она больше, чем текущая дата минус 3 дня
            //объяснение почему: план. дата. поставки на пятницу 7.10, т.е. в админке будет записано поступление 10.10 (понедельник),
            //но если при прогоне процедуры мы не учитываем план. даты из МС меньше текущей, то когда процедура запустится 8,9,10, поле будет пустым, а это неправильно
            if (isset($row['deliveryPlannedMoment'])) {
                //Будем записывать в базу следующий рабочий день
                $rowDeliveryPlannedMoment = DateTime::createFromFormat($dateFormat . '.000', $row['deliveryPlannedMoment']);
                $rowDeliveryPlannedMomentTimestamp = $rowDeliveryPlannedMoment->getTimestamp();
                $deliveryPlannedMomentTimestamp = $rowDeliveryPlannedMoment->modify('+1 weekday')->getTimestamp();

                if ($rowDeliveryPlannedMomentTimestamp > $startDateTimestamp - 86400*3) {
                    $positions = $myWarehouseInstance->getDataByHref($row['positions']['meta']['href']);
                    foreach ($positions['rows'] as $assortment) {
                        $variant = $myWarehouseInstance->getDataByHref($assortment['assortment']['meta']['href']);
                        if (!isset($resultDates[$variant['externalCode']]) || $this->isDeliveryPlannedMomentForResult($rowResultDates[$variant['externalCode']], $rowDeliveryPlannedMomentTimestamp, $startDateTimestamp)) {
                            $rowResultDates[$variant['externalCode']] = $rowDeliveryPlannedMomentTimestamp;
                            $resultDates[$variant['externalCode']] = $deliveryPlannedMomentTimestamp;
                        }
                    }
                }
            }
        }

        $productItems = ProductsItem::generateAll([['=', 'archive', 0], ['=', 'is_set', 0]]);
        foreach ($productItems as $productItem) {
            /**
             * @var ProductsItem $productItem
             */
            $availableDateOld = $productItem->available_date;

            if (isset($resultDates[$productItem->ext_code])) {
                $productItem->available_date = $resultDates[$productItem->ext_code];
            } else {
                $productItem->available_date = NULL;
            }

            //Если новая дата равна старой, то сохранение в БД пропускаем
            if ($productItem->available_date === $availableDateOld) {
                continue;
            }

            if ($productItem->save()) {
                $out = 'Item save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' available_date_old ' . ($availableDateOld ?? 'NULL') . ' available_date_new ' . ($productItem->available_date ?? 'NULL') . PHP_EOL;
            } else {
                $out = 'Item not save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' available_date_old ' . ($availableDateOld ?? 'NULL') . ' available_date_new ' . ($productItem->available_date ?? 'NULL') . PHP_EOL;
            }
            $this->stdout($out);
        }

        //Дата появления в наличии для наборов is_set = true
        $productItems = ProductsItem::generateAll([['=', 'archive', 0], ['=', 'is_set', 1]]);
        foreach ($productItems as $productItem) {
            /**
             * @var ProductsItem $productItem
             */
            $availableDateOld = $productItem->available_date;

            $complectItems = ProductsComplectsToItems::getSetItemsAvailableDate($productItem->id);
            if (!empty($complectItems)) {
                $productItem->available_date = 0;

                foreach ($complectItems as $complectItem) {
                    if ((integer) $complectItem['available_date'] > $productItem->available_date) {
                        $productItem->available_date = (integer) $complectItem['available_date'];
                    }

                    if ($complectItem['available_date'] === NULL) {
                        $productItem->available_date = NULL;
                        break;
                    }
                }

                if ($productItem->available_date === 0) {
                    $productItem->available_date = NULL;
                }
            } else {
                $productItem->available_date = NULL;
            }

            //Если новая дата равна старой, то сохранение в БД пропускаем
            if ($productItem->available_date === $availableDateOld) {
                continue;
            }

            if ($productItem->save()) {
                $out = 'Complect save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' available_date_old ' . ($availableDateOld ?? 'NULL') . ' available_date_new ' . ($productItem->available_date ?? 'NULL') . PHP_EOL;
            } else {
                $out = 'Complect not save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' available_date_old ' . ($availableDateOld ?? 'NULL') . ' available_date_new ' . ($productItem->available_date ?? 'NULL') . PHP_EOL;
            }
            $this->stdout($out);
        }

        $out = 'moy-sklad/synch-income-dates cron finished at ' . date($dateFormat) . PHP_EOL;
        $this->stdout($out, BaseConsole::FG_GREEN);
    }

    private function isDeliveryPlannedMomentForResult($currentResultDateTimestamp, $deliveryPlannedMomentTimestamp, $startDateTimestamp) : bool
    {
        return ($currentResultDateTimestamp > $deliveryPlannedMomentTimestamp && $deliveryPlannedMomentTimestamp >= $startDateTimestamp)
            || ($currentResultDateTimestamp < $startDateTimestamp && $deliveryPlannedMomentTimestamp >= $startDateTimestamp);
    }

    public function actionCorrectOzonStatus()
    {
        $dateFormat = 'Y-m-d H:i:s';
        $out = 'moy-sklad/correct-ozon-status cron started at ' . date($dateFormat) . PHP_EOL;
        $this->stdout($out, BaseConsole::FG_GREEN);

        $myWarehouseInstance = MyWarehouseHelper::getInstance();

        $orders = $myWarehouseInstance->getRowGenerator('orders', ['filter' => 'state.name=' . MyWarehouseHelper::ORDER_STATUS_TRANSMITTED_IN_OZON], MyWarehouseHelper::VERSION_1_2);
        foreach ($orders as $row) {
            $order = MyWarehouseOrdersTransmittedInOzon::find()->where(['my_warehouse_order_id' => $row['id']])->one();

            if (!$order) {
                $order = new MyWarehouseOrdersTransmittedInOzon();
                $order->my_warehouse_order_id = $row['id'];
                $order->save();
            }
        }

        $orders = $myWarehouseInstance->getRowGenerator('orders', ['filter' => 'state.name=' . MyWarehouseHelper::ORDER_STATUS_WAITING_DELIVERY], MyWarehouseHelper::VERSION_1_2);
        $orderIdsForUpdate = [];
        foreach ($orders as $row) {
            $order = MyWarehouseOrdersTransmittedInOzon::find()->where(['my_warehouse_order_id' => $row['id']])->one();

            if ($order) {
                $orderIdsForUpdate[] = $row['id'];
            }
        }

        if (!empty($orderIdsForUpdate)) {
            $myWarehouseInstance->updateOrdersOzonStatus($orderIdsForUpdate);
        }

        $out = 'moy-sklad/correct-ozon-status cron finished at ' . date($dateFormat) . PHP_EOL;
        $this->stdout($out, BaseConsole::FG_GREEN);
    }

    public function actionSynchNds()
    {
        $resultCode = 0;
        try {
            $dateFormat = 'Y-m-d H:i:s';
            $out = 'moy-sklad/synch-nds cron started at ' . date($dateFormat) . PHP_EOL;
            $this->stdout($out, BaseConsole::FG_GREEN);

            $myWarehouseInstance = MyWarehouseHelper::getInstance();

            $assortment = $myWarehouseInstance->getRowGenerator('assortment', [], MyWarehouseHelper::VERSION_1_2);
            foreach ($assortment as $row) {
                $productItem = ProductsItem::find()->where(['ext_code' => $row['externalCode']])->one();

                if ($productItem) {
                    $taxTypeOld = $productItem->tax_type;

                    if (isset($row['effectiveVat'])) {
                        $taxTypeVal = $row['effectiveVatEnabled'] ? (int)$row['effectiveVat'] : 'false';
                        $taxTypeNew = TaxHelper::getValueTaxType($taxTypeVal);
                    } elseif ($row['meta']['type'] === 'variant') {
                        $product = $myWarehouseInstance->getDataByHref($row['product']['meta']['href']);
                        if (isset($product['effectiveVat'])) {
                            $taxTypeVal = $product['effectiveVatEnabled'] ? (int)$product['effectiveVat'] : 'false';
                            $taxTypeNew = TaxHelper::getValueTaxType($taxTypeVal);
                        }
                    }

                    if (isset($taxTypeNew)) {
                        $productItem->tax_type = $taxTypeNew;
                        if ($productItem->save(false)) {
                            $out = 'Item save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' tax_type_old ' . ($taxTypeOld ?? 'NULL') . ' tax_type_new ' . ($taxTypeNew ?? 'NULL') . PHP_EOL;
                        } else {
                            $out = 'Item not save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' tax_type_old ' . ($taxTypeOld ?? 'NULL') . ' tax_type_new ' . ($taxTypeNew ?? 'NULL') . PHP_EOL;
                        }
                    } else {
                        $out = 'Vat for item not found - ext_code ' . $row['externalCode'] . PHP_EOL;
                    }
                } else {
                    $out = 'Item not found - ext_code ' . $row['externalCode'] . PHP_EOL;
                }
                $this->stdout($out);
            }

            $out = 'moy-sklad/synch-nds cron finished at ' . date($dateFormat) . PHP_EOL;
            $this->stdout($out, BaseConsole::FG_GREEN);
        } catch (Throwable $exception) {
            $this->log(
                sprintf('Ошибка команды cинхронизация НДС из сервиса "Мой склад": %s', $exception->getMessage()),
                [
                    'category' => 'moy-sklad-synch-nds',
                    'exception' => $exception
                ],
                self::LOG_LEVEL_ERROR
            );
            $resultCode = 1;
        }

        return $resultCode;
    }

    private function log(string $message, array $data = [], string $level = self::LOG_LEVEL_INFO)
    {
        switch ($level) {
            case self::LOG_LEVEL_INFO:
                $this->logger->info($message, $data);
                $this->stdout($message . PHP_EOL);
                break;
            case self::LOG_LEVEL_ERROR:
                $this->logger->error($message, $data);
                $this->stderr($message . PHP_EOL);
                break;
        }
    }
}

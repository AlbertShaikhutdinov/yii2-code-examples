<?php

namespace app\commands;

use app\helpers\IntegrationHelper;
use app\models\ProductsComplectsToItems;
use app\models\ProductsItem;
use Psr\Log\LoggerInterface;
use RetailCrm\ApiClient;
use Throwable;
use yii\console\Controller;
use yii\helpers\BaseConsole;

class RetailCrmController extends Controller
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

    public function actionSynchPrices()
    {
        $resultCode = 0;
        try {
            $this->processingSynchPrices();
        } catch (Throwable $exception) {
            $this->log(
                sprintf('Ошибка команды обновления цен из сервиса "RetailCRM": %s', $exception->getMessage()),
                [
                    'category' => 'retail-crm-synch-prices',
                    'exception' => $exception
                ],
                self::LOG_LEVEL_ERROR
            );
            $resultCode = 1;
        }

        return $resultCode;
    }

    public function processingSynchPrices()
    {
        $dateFormat = 'Y-m-d H:i:s';
        $out = 'retail-crm/synch-prices cron started at ' . date($dateFormat) . PHP_EOL;
        $this->stdout($out, BaseConsole::FG_GREEN);

        $retailApi = IntegrationHelper::getRetailCrmClientV5();
        $productItems = ProductsItem::generateAll([['=', 'archive', 0]]);
        foreach ($productItems as $productItem) {
            /**
             * @var ProductsItem $productItem
             */
            $priceOld = $productItem->price;

            $retailCrmProduct = $retailApi->request->getProductsList(['filter' => ['offerExternalId' => $productItem->ext_code]]);
            if ($retailCrmProduct->isSuccessful()
                && !empty($retailCrmProduct->getResponse()['products'])
                && !empty(reset($retailCrmProduct->getResponse()['products'])['offers'])) {
                $retailCrmProductOffers = reset($retailCrmProduct->getResponse()['products'])['offers'];
                foreach ($retailCrmProductOffers as $retailCrmOffer) {
                    if ($retailCrmOffer['externalId'] === $productItem->ext_code) {
                        $productItem->price = (float)$retailCrmOffer['price'];
                        break;
                    }
                }
            }

            if ($productItem->save()) {
                $out = 'Item save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' price_old ' . $priceOld . ' price_new ' . $productItem->price . PHP_EOL;
            } else {
                $out = 'Item not save - ' . $productItem->id . ' ext_code ' . $productItem->ext_code . ' price_old ' . $priceOld . ' price_new ' . $productItem->price . PHP_EOL;
            }
            $this->stdout($out);
        }

        $out = 'retail-crm/synch-prices cron finished at ' . date($dateFormat) . PHP_EOL;
        $this->stdout($out, BaseConsole::FG_GREEN);
    }

    /**
     * @param string $message
     * @param array $data
     * @param $level
     * @return void
     */
    private function log(string $message, array $data = [], $level = self::LOG_LEVEL_INFO)
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

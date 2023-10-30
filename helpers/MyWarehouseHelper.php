<?php
/**
 * Created by PhpStorm.
 * User: lex
 * Date: 12.04.17
 * Time: 10:41
 *
 * Хелпер для работы с мой склад
 */

namespace app\helpers;

use app\components\Container;
use Generator;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Psr\Log\LoggerInterface;
use Yii;
use GuzzleHttp\Client;
use yii\helpers\Json;

class MyWarehouseHelper
{
    private const LOG_LEVEL_ERROR = 'error';
    private const LOG_LEVEL_INFO = 'info';

    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_DELETE = 'DELETE';

    const VERSION_1_1 = '1.1';
    const VERSION_1_2 = '1.2';

    const ORDER_STATUS_NEW = 'fe60d788-b17a-11e6-7a69-8f55000d6836'; // Новый
    const ORDER_STATUS_CONFIRMED = 'fe60d90d-b17a-11e6-7a69-8f55000d6837'; // Подтвержден
    const ORDER_STATUS_ASSEMBLED = 'fe60daee-b17a-11e6-7a69-8f55000d6838'; //Собран
    const ORDER_STATUS_SHIPPED = 'fe60dc3b-b17a-11e6-7a69-8f55000d6839'; //  Отгружен
    const ORDER_STATUS_SHIPPED_LC = 'cf500ac8-8e2c-11e7-7a34-5acf000568b4'; //  Отгружен ЛК
    const ORDER_STATUS_ON_DELIVERY = 'cf500e49-8e2c-11e7-7a34-5acf000568b5'; //  На доставке
    const ORDER_STATUS_RE_SENT = 'cf501057-8e2c-11e7-7a34-5acf000568b6'; //  Отправлен повторно
    const ORDER_STATUS_PARCEL_LOST = 'cf5012e2-8e2c-11e7-7a34-5acf000568b7'; //  Посылка утеряна
    const ORDER_STATUS_DELIVERED = 'fe60ddf2-b17a-11e6-7a69-8f55000d683a'; // Доставлен
    const ORDER_STATUS_REFUND = ''; // Возврат
    const ORDER_STATUS_CANCELED = 'fe60dfbc-b17a-11e6-7a69-8f55000d683c'; //  Отменен
    const ORDER_STATUS_COMPLETED = '1e0a6906-5cdc-11e8-9109-f8fc001ddbad'; // Завершен

    const ORDER_STATUS_WAITING_DELIVERY = 'Ожидает доставки'; // Ожидает доставки
    const ORDER_STATUS_TRANSMITTED_IN_OZON = 'Передан в ТК Ozon Логистика'; // Передан в ТК Ozon Логистика
    const ORDER_STATUS_TRANSMITTED_IN_OZON_ID = '96a05d9e-73bd-11ec-0a80-0c0500820802';
    const ORDER_STATUS_TRANSMITTED_IN_OZON_DEV_ID = 'd43227ce-584c-11ed-0a80-072d00268f24';

    const ORDER_STATE_REGULAR = 'Regular';
    const ORDER_STATE_SUCCESSFUL = 'Successful';
    const ORDER_STATE_UNSUCCESSFUL = 'Unsuccessful';

    const MODIFICATION_CHARACTERISTIC_WEIGHT = 'd1328f47-edd5-11e6-7a69-8f55000331e0'; // Вес
    const MODIFICATION_CHARACTERISTIC_VOLUME = 'd7e061c6-1ac3-11e7-7a69-9711000e8f79'; // Объем
    const MODIFICATION_CHARACTERISTIC_BRAND = '066c9571-e47f-11e6-7a69-93a70053d670'; // Бренд
    const MODIFICATION_CHARACTERISTIC_SIZE = '066c939f-e47f-11e6-7a69-93a70053d66f'; // Размер

    const ORGANIZATION_NAPPY = 'ООО "НЭППИ КЛАБ"';
    const PROJECT_GENERAL = 'Основная деятельность';

    public $storesForSiteCount = [
        'Склад Операционный',
    ];

    private $logMethods = [
        'getProcessingPlanMaterials',
        'getProcessingPlanProducts',
        'getReportStockByStore',
        'createProcessing',
    ];

    private $apiLogin;
    private $apiPassword;
    private $apiUrl;
    private $apiPath;

    /**
     * @var Client
     */
    private $client;

    /** @var self|null */
    private static $_instance = null;

    private LoggerInterface $logger;

    // singleton
    private function __construct()
    {
        $this->apiLogin = Yii::$app->params['myWarehouse']['login'];
        $this->apiPassword = Yii::$app->params['myWarehouse']['password'];

        $urlParts = parse_url(Yii::$app->params['myWarehouse']['url']);
        $this->apiUrl = $urlParts['scheme'].'://'.$urlParts['host'];
        $this->apiPath = $urlParts['path'];
        $this->logger = Container::instance()->getLogger();
    }

    // singleton
    protected function __clone()
    {

    }

    /**
     * @return MyWarehouseHelper
     */
    public static function getInstance()
    {
        if(self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }


    public function getApiClient()
    {
        if ($this->client === null) {
            $stack = HandlerStack::create();
            $stack->push(GuzzleRetryMiddleware::factory([
                'max_retry_attempts' => 10,
                'default_retry_multiplier' => 3
            ]));

            $this->client = new Client([
                'base_uri' => $this->apiUrl,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'auth' => [$this->apiLogin, $this->apiPassword],
                'verify' => false,
                'handler' => $stack,
            ]);
        }

        return $this->client;
    }

    public static function getWebHookRequestBody()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            return json_decode(file_get_contents('php://input'), true);
        }

        return false;
    }

    public function getOrders(array $params = [], $version = self::VERSION_1_1)
    {
        $url = $this->makeUrl('/entity/customerorder', $params, $version);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    public function getPurchaseOrders(array $params = [], $version = self::VERSION_1_1)
    {
        $url = $this->makeUrl('/entity/purchaseorder', $params, $version);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    public function getAssortment(array $params = [], $version = self::VERSION_1_1)
    {
        $url = $this->makeUrl('/entity/assortment', $params, $version);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    /**
     * @param string $type
     * @param array $params
     * @param string $version
     * @return Generator
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getRowGenerator(string $type, array $params, string $version = self::VERSION_1_1)
    {
        $orders = [];
        $offset = 0;
        $limit = 100;
        $nextRequest = true;

        do {
            $defaultParams = [
                'offset' => $offset,
                'limit' => $params['size'] ?? $limit,
            ];
            $params = array_replace($params, $defaultParams);

            switch ($type) {
                case '/report/stock/bystore':
                    $params['limit'] = 1000;
                    $params['filter'] = 'stockMode=all';
                    $res = $this->getReportStockByStore($params, $version);
                    break;
                case 'assortment':
                    $params['limit'] = 1000;
                    $res = $this->getAssortment($params, $version);
                    break;
                case 'purchaseorders':
                    $res = $this->getPurchaseOrders($params, $version);
                    break;
                case 'orders':
                default:
                    $res = $this->getOrders($params, $version);
                    break;
            }

            if (!empty($res['rows'])) {
                $orders = array_merge($orders, $res['rows']);

                $meta = $res['meta'];
                if ($meta['offset'] + $meta['limit'] >= ($params['size'] ?? $meta['size'])) {
                    $nextRequest = false;
                }

                $offset += $limit;
            } else {
                $nextRequest = false;
            }

            yield from $orders;

        } while ($nextRequest);
    }

    /**
     * @param array $orderIds
     * @param array $requestParams
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateOrdersOzonStatus(array $orderIds, array $requestParams = [])
    {
        $data = [];
        foreach ($orderIds as $orderId) {
            $data[] = [
                'meta' => [
                    'href' => 'https://online.moysklad.ru/api/remap/1.2/entity/customerorder/'.$orderId,
                    'metadataHref' => 'https://online.moysklad.ru/api/remap/1.2/entity/customerorder/metadata',
                    'type' => 'customerorder',
                    'mediaType' => 'application/json',
                ],
                'state' => [
                    'meta' => [
                        'href' => 'https://online.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/'.(YII_ENV_DEV || YII_ENV_TEST ? MyWarehouseHelper::ORDER_STATUS_TRANSMITTED_IN_OZON_DEV_ID : MyWarehouseHelper::ORDER_STATUS_TRANSMITTED_IN_OZON_ID),
                        'type' => 'state',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];
        }

        $url = $this->makeUrl('/entity/customerorder', [], self::VERSION_1_2);
        $params = ['body' => json_encode($data)];

        return $this->request(__METHOD__, self::METHOD_POST, $url, $this->mergeRequestParams($params, $requestParams));
    }

    public function getLastCreatedAndUpdatedOrders($period, $params)
    {
        $result = [];

        $dateFrom = (new \DateTime('NOW'))->sub(new \DateInterval('P' . $period . 'D'));
        $dateFormat = 'Y-m-d H:i:s';
        $params['filter'] = 'created>'.$dateFrom->format($dateFormat);
        foreach($this->getRowGenerator('orders', $params) as $order) {
            $result[$order['id']] = $order;
        }

        $params['filter'] = 'updated>' . $dateFrom->format($dateFormat);
        foreach($this->getRowGenerator('orders', $params) as $order) {
            $result[$order['id']] = $order;
        }

        return $result;
    }

    public function getOrder($id, array $params = [])
    {
        $url = $this->makeUrl('/entity/customerorder/'.$id, $params);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    public function updateOrderPosition($orderId, $positionId, $data, array $requestParams = [])
    {
        $url = $this->makeUrl('/entity/customerorder/'.$orderId.'/positions/'.$positionId);
        $params = ['body' => json_encode($data)];

        return $this->getApiClient()->request(self::METHOD_PUT, $url, $this->mergeRequestParams($params, $requestParams));
    }

    public function deleteOrderPosition($orderId, $positionId, array $requestParams = [])
    {
        $url = $this->makeUrl('/entity/customerorder/'.$orderId.'/positions/'.$positionId);
        $params = [];

        return $this->request(__METHOD__, self::METHOD_DELETE, $url, $this->mergeRequestParams($params, $requestParams));
    }

    public function getOrderDemandTemplate($orderId, array $params = [])
    {
        $data = [
            'customerOrder' => [
                'meta' => [
                    'href' => $this->apiUrl.$this->apiPath.'/entity/customerorder/'.$orderId,
                    'metadataHref' => $this->apiUrl.$this->apiPath.'/entity/customerorder/metadata',
                    'type' => 'customerorder',
                    'mediaType' => 'application/json'
                ]
            ]
        ];

        $url = $this->makeUrl('/entity/demand/new', $params);

        return $this->request(__METHOD__, self::METHOD_PUT, $url, ['body' => json_encode($data)]);
    }

    public function getDemand($id, array $params = [])
    {
        $url = $this->makeUrl('/entity/demand/'.$id, $params);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    public function createDemand($data, $requestParams = [])
    {
        $url = $this->makeUrl('/entity/demand');
        $params = ['body' => json_encode($data)];

        return $this->request(__METHOD__, self::METHOD_POST, $url, $this->mergeRequestParams($params, $requestParams));
    }

    private function makeUrl($url, $params = [], $version = self::VERSION_1_1)
    {
        return $this->apiPath . '/' . $version . $url . $this->getParamsQueryString($params);
    }

    /**
     * @return array
     */
    private function getResponse($response)
    {
        return Json::decode($response->getBody(), true);
    }

    private function getParamsQueryString($params)
    {
        $queryString = http_build_query($params);

        return $queryString ? '?'.$queryString : '';
    }

    private function mergeRequestParams($params, $requestParams)
    {
        $returnParams = [];
        foreach ($requestParams as $param => $value) {
            switch ($param) {
                case 'disable_hooks':
                    $returnParams['headers']['X-Lognex-WebHook-Disable'] = $value;
                    break;
                default:
                    $returnParams[$param] = $value;
                    break;
            }
        }

        return array_merge($params, $returnParams);
    }

    public static function isCrmDelivery($assortment)
    {
        return $assortment['externalCode'] == 'Nyy0PphBhxTyAZothm2u51' || $assortment['name'] == 'Доставка retailCRM';
    }

    /**
     * @param $url
     * @return bool
     */
    public function setCompletedStatus($url)
    {
        $state['state'] = [
            'meta' => [
                'href' => 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder/metadata/states/' . self::ORDER_STATUS_COMPLETED,
                'type' => 'state',
                'mediaType' => 'application/json'
            ]
        ];

        $responseArray = $this->request(__METHOD__, self::METHOD_PUT, $url, ['body' => Json::encode($state)]);

        return self::isSuccessful($responseArray);
    }

    /**
     * @param $res
     * @return bool
     */
    public static function isSuccessful($res)
    {
        return !isset($res['errors']);
    }

    public function findAllEntityVariant()
    {
        $offset = 0;
        $limit = 100;

        do {
            $url = $this->makeUrl('/entity/variant', ['limit' => $limit, 'offset' => $offset]);
            $data = $this->request(__METHOD__, self::METHOD_GET, $url);
            $rows = $data['rows'];
            yield from $rows;

            $offset += $limit;
        } while (count($rows) > 0);
    }

    public function findAllEntityAssortment($params)
    {
        $offset = 0;
        $limit = 100;

        do {
            $urlParams = array_merge(['limit' => $limit, 'offset' => $offset], $params);

            $url = $this->makeUrl('/entity/assortment', $urlParams);
            $data = $this->request(__METHOD__, self::METHOD_GET, $url);
            $rows = $data['rows'];
            yield from $rows;

            $offset += $limit;
        } while (count($rows) > 0);
    }

    public function findAllProductFolder($params)
    {
        $offset = 0;
        $limit = 100;

        do {
            $urlParams = array_merge(['limit' => $limit, 'offset' => $offset], $params);

            $url = $this->makeUrl('/entity/productfolder', $urlParams);
            $data = $this->request(__METHOD__, self::METHOD_GET, $url);
            $rows = $data['rows'];
            yield from $rows;

            $offset += $limit;
        } while (count($rows) > 0);
    }

    /**
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProcessingPlans(array $params = [])
    {
        $url = $this->makeUrl('/entity/processingplan', $params, self::VERSION_1_2);
        $data = $this->request(__METHOD__, self::METHOD_GET, $url);

        return $data['rows'] ?? [];
    }

    /**
     * @param string $planId
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProcessingPlan(string $planId, array $params = [])
    {
        $url = $this->makeUrl('/entity/processingplan/' . $planId, $params, self::VERSION_1_2);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    /**
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getStores(array $params = [])
    {
        $url = $this->makeUrl('/entity/store', $params, self::VERSION_1_2);
        $data = $this->request(__METHOD__, self::METHOD_GET, $url);

        return $data['rows'] ?? [];
    }

    /**
     * @param string $storeId
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getStore(string $storeId, array $params = [])
    {
        $url = $this->makeUrl('/entity/store/' . $storeId, $params, self::VERSION_1_2);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    /**
     * @param string $planId
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProcessingPlanMaterials(string $planId, array $params = [])
    {

        $url = $this->makeUrl('/entity/processingplan/' . $planId . '/materials', $params, self::VERSION_1_2);
        $data = $this->request(__METHOD__, self::METHOD_GET, $url);

        return $data['rows'] ?? [];
    }

    /**
     * @param string $planId
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProcessingPlanProducts(string $planId, array $params = [])
    {

        $url = $this->makeUrl('/entity/processingplan/' . $planId . '/products', $params, self::VERSION_1_2);
        $data = $this->request(__METHOD__, self::METHOD_GET, $url);

        return $data['rows'] ?? [];
    }

    /**
     * @param array $params
     * @param string $version
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getReportStockByStore(array $params = [], string $version = self::VERSION_1_2)
    {
        $url = $this->makeUrl('/report/stock/bystore', $params, $version);

        return $this->request(__METHOD__, self::METHOD_GET, $url);
    }

    /**
     * @param array $stockByStore
     * @return int
     */
    public function getSiteCountFromStockByStore(array $stockByStore) {
        $count = 0;
        foreach ($stockByStore as $store) {
            if (in_array($store['name'], $this->storesForSiteCount)) {
                $count += (int) $store['stock'] - (int) $store['reserve'];
            }
        }

        return $count;
    }

    /**
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getOrganizations(array $params = [])
    {
        $url = $this->makeUrl('/entity/organization', $params, self::VERSION_1_2);
        $data = $this->request(__METHOD__, self::METHOD_GET, $url);

        return $data['rows'] ?? [];
    }

    /**
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProjects(array $params = [])
    {
        $url = $this->makeUrl('/entity/project', $params, self::VERSION_1_2);
        $data = $this->request(__METHOD__, self::METHOD_GET, $url);

        return $data['rows'] ?? [];
    }

    /**
     * @param \JsonSerializable $data
     * @param array $requestParams
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createProcessing(\JsonSerializable $data, array $requestParams = [])
    {
        $url = $this->makeUrl('/entity/processing');
        $params = ['body' => json_encode($data)];

        return $this->request(__METHOD__, self::METHOD_POST, $url, $this->mergeRequestParams($params, $requestParams));
    }

    /**
     * @param string $href
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getDataByHref(string $href)
    {
        return $this->request(__METHOD__, self::METHOD_GET, $href);
    }

    /**
     * @param string $classMethod
     * @param string $method
     * @param string $url
     * @param array $params
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function request($classMethod = '', $method = self::METHOD_GET, $url = '', $params = [])
    {
        switch ($method) {
            case self::METHOD_GET:
                $response = $this->getApiClient()->get($url);
                break;
            case self::METHOD_POST:
                $response = $this->getApiClient()->post($url, $params);
                break;
            case self::METHOD_PUT:
                $response = $this->getApiClient()->put($url, $params);
                break;
            case self::METHOD_DELETE:
                $response = $this->getApiClient()->delete($url, $params);
                break;
        }
        $responseArray = $this->getResponse($response);

        $classMethodName = explode('::', $classMethod)[1];
        if (in_array($classMethodName, $this->logMethods)) {
            $this->log(
                sprintf('%s-запрос в "Мой склад": %s', $method, $url),
                [
                    'url' => $url,
                    'params' => $params,
                    'response' => $responseArray,
                ],
                self::LOG_LEVEL_INFO,
            );
        }

        if (array_key_exists('errors', $responseArray)) {
            $this->log(
                sprintf('Ошибка %s-запроса в "Мой склад": %s', $method, $url),
                [
                    'url' => $url,
                    'params' => $params,
                    'response' => $responseArray,
                ],
                self::LOG_LEVEL_ERROR,
            );

            $message = sprintf(
                "Error in method %s \nRESPONSE: \n%s  \nCONTEXT: \n%s",
                $classMethod,
                print_r($responseArray, true),
                print_r($params, true)
            );
            AlertHelper::send(' MOYSKLAD ERROR', $message);
        }

        return $responseArray;
    }

    /**
     * @param string $message
     * @param array $data
     * @param $level
     * @return void
     */
    private function log(string $message, array $data = [], $level = self::LOG_LEVEL_INFO)
    {
        $data['category'] = 'my-warehouse-request';
        switch ($level) {
            case self::LOG_LEVEL_INFO:
                $this->logger->info($message, $data);
                break;
            case self::LOG_LEVEL_ERROR:
                $this->logger->error($message, $data);
                break;
        }
    }
}

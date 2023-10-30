<?php

namespace app\controllers\mobileApi;

use app\models\Products;
use app\models\ProductsCategory;
use app\modules\mobileApi\factory\CategoryListResponseFactory;
use app\modules\mobileApi\factory\ProductFactory;
use app\modules\mobileApi\factory\ProductsListResponseFactory;
use app\modules\mobileApi\generated\CategoriesGet200Response;
use app\modules\mobileApi\generated\Product;
use app\modules\mobileApi\generated\ProductsGet200Response;
use app\modules\mobileApi\generated\ProductsGetRequest;
use app\modules\mobileApi\generated\SearchGetRequest;
use app\services\EntityFactory\CategoryRepository;
use app\services\EntityFactory\ProductFactory as EntityProductFactory;
use NappyClub\Module\ElasticSearch\Index\Product\ProductIndexRepository;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class ProductsController extends Controller
{
    public $enableCsrfValidation = false;

    public function __construct(
        $id,
        $module,
        $config = [],
        protected ProductsListResponseFactory $productsListResponseFactory,
        protected CategoryListResponseFactory $categoryListResponseFactory,
        protected CategoryRepository $categoryRepository,
        protected ProductFactory $productFactory,
        protected ProductIndexRepository $repository,
        protected EntityProductFactory $entityProductFactory,
    )
    {
        parent::__construct($id, $module, $config);
    }

    public function actionCategories(): CategoriesGet200Response
    {
        $userId = null;
        $categories = $this->categoryRepository->getAllCategories($userId);

        $response = new CategoriesGet200Response();
        $response->setData($this->categoryListResponseFactory->create($categories));

        return $response;
    }

    public function actionList(): ProductsGet200Response
    {
        $request = new ProductsGetRequest();

        $request->setCategory(Yii::$app->request->get('category', null));
        $request->setSort(Yii::$app->request->get('sort', ''));
        $request->setType(Yii::$app->request->get('type', ''));
        $request->setSize(Yii::$app->request->get('size', ''));
        $request->setXPage(Yii::$app->request->headers->get('X-Page', 1));
        $request->setXPageSize(Yii::$app->request->headers->get('X-Page-Size', 10));

        $options = $this->getSearchOptions(
            page: $request->getXPage(),
            size: $request->getXPageSize(),
            categorySurl: empty($request->getCategory()) ? null : $request->getCategory(),
        );

        $productSearchResult = $this->repository->findProducts($options);

        $response = new ProductsGet200Response();
        $response->setData($this->productsListResponseFactory->create($productSearchResult));

        return $response;
    }

    public function actionSearch(): ProductsGet200Response
    {
        $request = new SearchGetRequest();
        $request->setQuery(Yii::$app->request->get('query', ''));
        $request->setSort(Yii::$app->request->get('sort', ''));
        $request->setXPage(Yii::$app->request->headers->get('X-Page', 1));
        $request->setXPageSize(Yii::$app->request->headers->get('X-Page-Size', 10));

        $options = $this->getSearchOptions(
            query: $request->getQuery(),
            page: $request->getXPage(),
            size: $request->getXPageSize(),
        );

        $productSearchResult = $this->repository->findProducts($options);

        $response = new ProductsGet200Response();
        $response->setData($this->productsListResponseFactory->create($productSearchResult));

        return $response;
    }

    public function actionGetByProductId(string $id): Product
    {
        $userId = null;

        $product = Products::find()->where(['id' => $id])->one();
        if (!$product instanceof Products) {
            throw new NotFoundHttpException('Товар не найден');
        }

        return $this->productFactory->create(
            $this->entityProductFactory->create($product, $userId)
        );
    }

    private function getSearchOptions(
        ?string $query = null,
        int     $page = 1,
        int     $size = 10,
        array   $productIds = [],
        string  $categorySurl = null,
    ): array
    {
        $options = [
            'query' => [
                'filter' => [
                    'active' => true,
                    'archive' => false,
                    'brand.active' => true,
                    'product_items.status' => true,
                    'product_items.archive' => false,
                    'product_items.is_gift' => false,
                    'product_items.is_pack' => false,
                    'product_items.is_probe' => false,
                    'product_items.calculate_delivery' => true,
                ],
            ],
            'pagination' => [
                'page' => max($page, 1),
                'size' => max(min($size, 30), 1),
            ],
        ];

        if (null !== $query) {
            $options['query']['query'] = $query;
        }

        if (count($productIds) > 0) {
            $options['query']['filter']['ids'] = $productIds;
        }

        if ($categorySurl !== ProductsCategory::MAIN_CATEGORY_CODE) {
            $options['query']['filter']['category_surl'] = $categorySurl;
        }

        return $options;
    }
}

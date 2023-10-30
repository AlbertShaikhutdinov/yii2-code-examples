<?php

namespace app\controllers\mobileApi;

use app\components\JwtHttpApiKeyAuth;
use app\helpers\BasketHelper;
use app\models\BasketProducts;
use app\models\mobileApi\ApiBasket;
use app\models\mobileApi\ApiRefreshToken;
use app\models\phone\PhoneSendConfirmationCode;
use app\models\phone\PhoneValidateConfirmationCode;
use app\modules\mobileApi\exception\ErrorResponseException;
use app\modules\mobileApi\factory\AuthResponseFactory;
use app\modules\mobileApi\generated\AuthGet200Response;
use app\modules\mobileApi\generated\AuthRefreshRequest;
use app\modules\mobileApi\generated\AuthRequest;
use app\modules\mobileApi\generated\GetSMSCodeRequest;
use DateTimeImmutable;
use dektrium\user\models\LoginByPhoneForm;
use dektrium\user\models\User;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Token\RegisteredClaims;
use NappyClub\Module\Phone\ConfirmationCodeSenderService;
use NappyClub\Module\Phone\ConfirmationCodeValidatorService;
use NappyClub\Module\Phone\UserLoginByPhoneService;
use Symfony\Component\Uid\Ulid;
use sizeg\jwt\Jwt;
use Yii;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\ServerErrorHttpException;

class AuthController extends Controller
{
    public $enableCsrfValidation = false;

    public function __construct(
        $id,
        $module,
        $config = [],
        protected AuthResponseFactory $authResponseFactory,
        protected ConfirmationCodeSenderService $confirmationCodeSenderService,
        protected ConfirmationCodeValidatorService $confirmationCodeValidatorService,
        protected UserLoginByPhoneService $userLoginByPhoneService,
    )
    {
        parent::__construct($id, $module, $config);
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['authenticator'] = [
            'class' => JwtHttpApiKeyAuth::class,
            'except' => [
                'index',
                'refresh',
            ],
        ];

        return $behaviors;
    }

    public function actionIndex(): AuthGet200Response
    {
        $user = null;
        $apiRefreshToken = $this->generateRefreshToken($user);
        $accessToken = $this->generateJwt($user, $apiRefreshToken->identified_by, 'access');

        $basket = BasketHelper::createBasket($user?->id);
        $apiBasket = new ApiBasket([
            'basket_id' => $basket->id,
            'identified_by' => $apiRefreshToken->identified_by,
        ]);
        if (!$apiBasket->save()) {
            throw new ServerErrorHttpException('Ошибка сохранения модели apiBasket');
        }

        $response = new AuthGet200Response();
        $response->setData($this->authResponseFactory->create($apiRefreshToken, $accessToken));

        return $response;
    }

    public function actionRefresh(): AuthGet200Response
    {
        $request = new AuthRefreshRequest(Yii::$app->request->getBodyParams());

        /** @var ApiRefreshToken $userRefreshToken */
        $userRefreshToken = ApiRefreshToken::find()
            ->where(['token' => $request->getRefreshToken()])
            ->one();

        if (!$userRefreshToken) {
            throw new HttpException(400, 'Ошибка определения токена');
        }

        $user = User::find()
            ->where(['id' => $userRefreshToken->user_id])
            ->one();
        $accessToken = $this->generateJwt($user, $userRefreshToken->identified_by, 'access');

        $userRefreshToken->token = $this->generateJwt($user, $userRefreshToken->identified_by, 'refresh')->toString();
        if (!$userRefreshToken->save()) {
            throw new ServerErrorHttpException('Ошибка сохранения параметра refresh token');
        }

        $response = new AuthGet200Response();
        $response->setData($this->authResponseFactory->create($userRefreshToken, $accessToken));

        return $response;
    }

    public function actionSendCode(): AuthGet200Response
    {
        $request = new GetSMSCodeRequest(Yii::$app->request->getBodyParams());

        $phoneSendConfirmation = new PhoneSendConfirmationCode();
        $phoneSendConfirmation->phone = $request->getPhone();

        $result = $this->confirmationCodeSenderService->send($phoneSendConfirmation);

        if (!isset($result['data'])) {
            throw new ErrorResponseException($result['status'], 'Ошибка отправки кода подтверждения на номер телефона', ['phone' => $result['phonesendconfirmationcode-phone']]);
        }

        $response = new AuthGet200Response();
        return $response;
    }

    public function actionAuth(): AuthGet200Response
    {
        $request = new AuthRequest(Yii::$app->request->getBodyParams());
        $authHeader = Yii::$app->request->getHeaders()->get(JwtHttpApiKeyAuth::HEADER);
        $requestToken =  Yii::$app->jwt->loadToken($authHeader);
        $identifiedBy = $requestToken->claims()->get(RegisteredClaims::ID);

        /** @var ApiRefreshToken $userRefreshToken */
        $userRefreshToken = ApiRefreshToken::find()
            ->where(['identified_by' => $identifiedBy])
            ->one();

        if (!$userRefreshToken) {
            throw new HttpException(400, 'Ошибка определения токена');
        }

        $basket = null;
        if ($userRefreshToken->user_id === null) {
            $user = Yii::$app->user;
            $basket = BasketHelper::getBasket($user->isGuest, $user->id);
        }

        $phoneValidateConfirmationCode = new PhoneValidateConfirmationCode();
        $phoneValidateConfirmationCode->phone = $request->getPhone();
        $phoneValidateConfirmationCode->code = $request->getCode();

        $validationResult = $this->confirmationCodeValidatorService->validate($phoneValidateConfirmationCode);
        if (!isset($validationResult['data'])) {
            throw new ErrorResponseException($validationResult['status'], 'Ошибка проверки кода подтверждения', ['code' => $validationResult['phonevalidateconfirmationcode-code']]);
        }

        $loginForm = new LoginByPhoneForm();
        $loginForm->phone = $validationResult['data']['phone']['number'];
        $loginForm->phone_authentication_code = $validationResult['data']['phone']['authentication_code'];
        $loginForm->confirmation_date = $validationResult['data']['phone']['confirmation_date'];
        $loginForm->from_basket = false;

        $result = $this->userLoginByPhoneService->login($loginForm);
        if (!$result['success']) {
            throw new ErrorResponseException($result['status'], 'Ошибка авторизации', ['' => $result['error']]);
        }

        $authenticatedUser = Yii::$app->user;
        $userAuthRefreshToken = ApiRefreshToken::find()
            ->where(['user_id' => $authenticatedUser->id])
            ->one();
        if (!$userAuthRefreshToken) {
            $userAuthRefreshToken = $this->generateRefreshToken($authenticatedUser->identity);
        }
        $accessToken = $this->generateJwt($authenticatedUser->identity, $userAuthRefreshToken->identified_by, 'access');

        if ($basket) {
            $userBasket = BasketHelper::getBasket($authenticatedUser->isGuest, $authenticatedUser->id);

            /** @var BasketProducts $basketProducts */
            /** @var BasketProducts $anonymousBasketProducts */
            $basketProducts = BasketProducts::findOrCreate(['basket_id' => $userBasket->id]);
            $anonymousBasketProducts = BasketProducts::findOrCreate(['basket_id' => $basket->id]);

            foreach ($anonymousBasketProducts->products as $key => $product) {
                if (isset($basketProducts->products[$key])) {
                    $basketProducts->products[$key]['quantity'] += $product['quantity'];
                } else {
                    $basketProducts->products[$key] = $product;
                }
            }
            $basketProducts->save();
        }

        $response = new AuthGet200Response();
        $response->setData($this->authResponseFactory->create($userAuthRefreshToken, $accessToken));

        return $response;
    }

    private function generateJwt(?User $user, string $identifiedBy, string $type = 'access') : Token
    {
        /** @var Jwt $jwt */
        $jwt = Yii::$app->jwt;

        $now = new DateTimeImmutable();
        $signer = $jwt->getSigner();
        $key = $jwt->getSignerKey();

        $jwtParams = Yii::$app->params['jwt'];

        switch ($type)
        {
            case ('access'):
            case ('refresh'):
                $expire = $jwtParams['expire'][$type];
                break;
            default:
                $expire = 300;
                break;
        }

        $builder = $jwt->getBuilder()
            ->issuedBy($jwtParams['issuer'])
            ->permittedFor($jwtParams['audience'])
            ->identifiedBy($identifiedBy)
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$expire} minutes"));

        $userId = $user?->id;
        if ($userId) {
            $builder = $builder->withClaim('uid', $userId);
        }


        return $builder->getToken($signer, $key);
    }

    /**
     * @throws ServerErrorHttpException
     */
    private function generateRefreshToken(?User $user): ApiRefreshToken
    {
        $userId = $user?->id;

        $identifiedBy = (new Ulid())->__toString();
        $refreshToken = $this->generateJwt($user, $identifiedBy, 'refresh');

        $userRefreshToken = new ApiRefreshToken([
            'user_id' => $userId,
            'token' => $refreshToken->toString(),
            'identified_by' => $identifiedBy,
            'ip' => Yii::$app->request->userIP,
            'user_agent' => Yii::$app->request->userAgent,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$userRefreshToken->save()) {
            throw new ServerErrorHttpException('Ошибка сохранения параметра refresh token');
        }

        return $userRefreshToken;
    }
}

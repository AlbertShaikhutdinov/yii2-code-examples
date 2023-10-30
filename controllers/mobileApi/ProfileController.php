<?php

namespace app\controllers\mobileApi;

use app\components\JwtHttpRegisteredUserAuth;
use app\helpers\dadata\DaDataHelper;
use app\models\mobileApi\ProfileFullName;
use app\models\profile\ProfileEmail;
use app\modules\mobileApi\exception\ErrorResponseException;
use app\modules\mobileApi\factory\ProfileResponseFactory;
use app\modules\mobileApi\generated\NappyProfile;
use app\modules\mobileApi\generated\ProfileGet200Response;
use app\modules\mobileApi\generated\ProfileRequest;
use dektrium\user\models\User;
use Yii;
use yii\web\Controller;
use yii\widgets\ActiveForm;

class ProfileController extends Controller
{
    public $enableCsrfValidation = false;

    public function __construct(
        $id,
        $module,
        $config = [],
        protected ProfileResponseFactory $profileResponseFactory,
    )
    {
        parent::__construct($id, $module, $config);
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['authenticator'] = [
            'class' => JwtHttpRegisteredUserAuth::class,
        ];

        return $behaviors;
    }

    public function actionIndex(): ProfileGet200Response
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;

        $response = new ProfileGet200Response();
        $response->setData($this->profileResponseFactory->create($user));

        return $response;
    }

    public function actionUpdate(): ProfileGet200Response
    {
        $request = new ProfileRequest(Yii::$app->request->getBodyParams());

        /** @var User $user */
        $user = Yii::$app->user->identity;
        $profile = $user->profile;
        $oldEmail = $profile->public_email;

        $errors = [];
        $profileFullName = ProfileFullName::createFromProfile($profile);
        $profileFullName->surname = $request->getFullName();
        $result = ActiveForm::validate($profileFullName);
        if ($profileFullName->hasErrors()) {
            $errors['fullName'] = $result['profilefullname-surname'];
        }

        $profileEmail = ProfileEmail::createFromProfile($profile);
        $profileEmail->email = $request->getEmail();
        $result = ActiveForm::validate($profileEmail);
        if ($profileEmail->hasErrors()) {
            $errors['email'] = $result['profileemail-email'];
        }

        $address = trim($request->getAddress());
        if (!empty($address)) {
            $dadataHelper = new DaDataHelper();
            $data = $dadataHelper->clean([$address]);
            $result = $data[0] ?? [];
        }
        if ($result && in_array($result['fias_level'], [8, 9])) { // fias_level - уровень детализации, до которого адрес найден в ФИАС. 8 — дом, 9 — квартира.
            $address = $result['postal_code'] . ', ' . $result['result'];
        } else {
            $errors['address'] = ['Укажите существующий адрес (частный дом или квартиру)'];
        }

        if ($errors) {
            throw new ErrorResponseException(400, 'Ошибка редактирования профиля пользователя', $errors);
        }

        $profile->surname = $profileFullName->surname;
        $profile->adrress = $address;
        $profile->public_email = $profileEmail->email;
        $profile->save(false);

        $user->email = $profile->public_email;
        if ($oldEmail !== $user->email) {
            $user->email_confirmed = false;
        }
        $user->save(false);

        $response = new ProfileGet200Response();
        $response->setData($this->profileResponseFactory->create($user));

        return $response;
    }
}

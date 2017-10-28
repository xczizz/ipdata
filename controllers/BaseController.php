<?php
/**
 * User: Mr-mao
 * Date: 2017/10/26
 * Time: 21:37
 */


namespace app\controllers;

use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\rest\ActiveController;

class BaseController extends ActiveController
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => CompositeAuth::className(),
            'authMethods' => [
                HttpBearerAuth::className()
            ]
        ];
        return $behaviors;
    }
}
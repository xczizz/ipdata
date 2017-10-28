<?php
/**
 * User: Mr-mao
 * Date: 2017/10/26
 * Time: 17:19
 */


namespace app\controllers;


use yii\helpers\Url;
use yii\web\Controller;
use Yii;

class DefaultController extends Controller
{
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'height' => 50,
                'width' => 120,
                'minLength' => 4,
                'maxLength' => 4
            ],
            'doc' => [
                'class' => 'light\swagger\SwaggerAction',
                'restUrl' => Url::to(['/default/api'], true)
            ],
            'api' => [
                'class' => 'light\swagger\SwaggerApiAction',
                //The scan directories, you should use real path there.
                'scanDir' => [
                    Yii::getAlias('@app/swagger'),
                    Yii::getAlias('@app/controllers'),
                ],
                'api_key' => null,
            ],
        ];
    }
}
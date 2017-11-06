<?php
/**
 * User: Mr-mao
 * Date: 2017/10/26
 * Time: 21:44
 */


namespace app\controllers;

use app\models\OverdueFineApi;
use app\models\PaidFeeApi;
use app\models\UnpaidFeeApi;
use Yii;
use app\models\ChangeOfBibliographicDataApi;
use app\models\Patent;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

class PatentController extends BaseController
{
    public $modelClass = 'app\models\Patent';

    /**
     * @var $_patent Patent
     */
    private $_patent;

    public function behaviors()
    {
        $behaviors =  parent::behaviors();
        $behaviors['authenticator']['only'] = [''];
        return $behaviors;
    }

    public function actions()
    {
        $actions['options'] = parent::actions()['options'];
        return $actions;
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if ($application_no = Yii::$app->request->getQueryParam('application_no')) {
            $this->_patent = Patent::findOne(['application_no' => $application_no]);
            if ($this->_patent === null) {
                throw new NotFoundHttpException();
            }
        }
        return true;
    }
    
    /**
     * @SWG\Get(
     *     path="/patents/view/{application_no}",
     *     tags={"Patent"},
     *     summary="专利详情",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *          @SWG\Schema(ref="#/definitions/Patent")
     *     ),
     *     @SWG\Response(
     *          response = 404,
     *          description = "Not Found",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array
     */
    public function actionView()
    {
        return $this->_patent->toArray([
            'application_no',
            'patent_type',
            'title',
            'filing_date',
            'case_status',
            'general_status',
            'publication_no',
            'publication_date',
            'issue_announcement',
            'issue_no',
            'applicants',
            'inventors',
            'ip_agency',
            'first_named_attorney'
        ]);
    }

    /**
     * @SWG\Get(
     *     path="/patents/{application_no}/change-of-bibliographic-data",
     *     tags={"Patent"},
     *     summary="著录项目变更",
     *     description="查看该专利著录项目变更相关的信息",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *          @SWG\Schema(ref="#/definitions/ChangeOfBibliographicData")
     *     ),
     *     @SWG\Response(
     *          response = 404,
     *          description = "Not Found",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function actionChangeOfBibliographicData()
    {
        $data = ChangeOfBibliographicDataApi::find()
            ->where(['patent_id' => $this->_patent->id])
            ->orderBy('date ASC')
            ->all();
        return $data;
    }

    /**
     * @SWG\Get(
     *     path="/patents/{application_no}/unpaid-fees",
     *     tags={"Patent"},
     *     summary="待缴费信息",
     *     description="所有未缴费的信息,包括年费、滞纳金等",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *          @SWG\Schema(ref="#/definitions/UnpaidFees")
     *     ),
     *     @SWG\Response(
     *          response = 404,
     *          description = "Not Found",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array
     */
    public function actionUnpaidFees()
    {
        return UnpaidFeeApi::find()
            ->where(['patent_id' => $this->_patent->id])
            ->orderBy('due_date ASC')
            ->all();
    }

    /**
     * @SWG\Get(
     *     path="/patents/{application_no}/latest-unpaid-fee",
     *     tags={"Patent"},
     *     summary="最近一条未缴费信息",
     *     description="获取数据库里时间最靠前的一条信息",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *          @SWG\Schema(ref="#/definitions/UnpaidFee")
     *     ),
     *     @SWG\Response(
     *          response = 404,
     *          description = "Not Found",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array|null|\yii\db\ActiveRecord
     */
    public function actionLatestUnpaidFee()
    {
        return UnpaidFeeApi::find()
            ->where(['patent_id' => $this->_patent->id])
            ->orderBy('due_date ASC')
            ->one();
    }

    /**
     * @SWG\Get(
     *     path="/patents/{application_no}/paid-fees",
     *     tags={"Patent"},
     *     summary="已缴费信息",
     *     description="展示所有已经缴过费用的信息",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *          @SWG\Schema(ref="#/definitions/PaidFees")
     *     ),
     *     @SWG\Response(
     *          response = 404,
     *          description = "Not Found",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function actionPaidFees()
    {
        return PaidFeeApi::find()
            ->where(['patent_id' => $this->_patent->id])
            ->all();
    }

    /**
     * @SWG\Get(
     *     path="/patents/{application_no}/overdue-fees",
     *     tags={"Patent"},
     *     summary="滞纳金",
     *     description="列出当前滞纳金信息",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *          @SWG\Schema(ref="#/definitions/OverdueFees")
     *     ),
     *     @SWG\Response(
     *          response = 404,
     *          description = "Not Found",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function actionOverdueFees()
    {
        return OverdueFineApi::find()
            ->where(['patent_id' => $this->_patent->id])
            ->all();
    }

    /**
     * @SWG\Post(
     *     path="/patents",
     *     tags={"Patent"},
     *     summary="添加申请号",
     *     @SWG\Parameter(
     *          in = "formData",
     *          name = "application_no",
     *          description = "申请号",
     *          required = true,
     *          type = "string",
     *     ),
     *     @SWG\Response(
     *          response = 201,
     *          description = "Created",
     *     ),
     *     @SWG\Response(
     *          response = 400,
     *          description = "Bad Request",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     *     @SWG\Response(
     *          response = 409,
     *          description = "Conflict",
     *          @SWG\Schema(ref="#/definitions/Error")
     *     ),
     * )
     *
     * @return array
     * @throws BadRequestHttpException
     * @throws ConflictHttpException
     */
    public function actionCreate()
    {
        $application_no = Yii::$app->request->post('application_no');
        if (!$this->checkApplicationNo($application_no)) {
            throw new BadRequestHttpException('申请号校验失败');
        }
        if (Patent::appNoExist($application_no)) {
            throw new ConflictHttpException('申请号已存在');
        }
        $patent = new Patent();
        $patent->application_no = $application_no;
        $patent->save();
        Yii::$app->getResponse()->setStatusCode(201);
        return $patent->toArray(['application_no']);
    }

    /**
     * 校验申请号
     *
     * @param $application_no
     * @return boolean
     */
    private function checkApplicationNo($application_no)
    {
        $pattern = '/^(((19[5-9]\d)|(20[0-4]\d))[123589](\d{7})[0-9xX])$|^((([5-9]\d)|(0[0-3]))(\d{6})[0-9xX])$/i';
        if (preg_match($pattern, $application_no)) {
            if (strlen($application_no) == 13) {
                $number = substr($application_no, 0, 12);
                $code = strtolower(substr($application_no, -1));
                $check_code = 0;
                for ($i = 0; $i < strlen($number); $i++) {
                    $index = 1 + $i;
                    if ($index < 9) {
                        $check_code += substr($number, $i, 1) * ($index + 1);
                    } else {
                        $check_code += substr($number, $i, 1) * ($index - 7);
                    }
                }
                $check_code = $check_code % 11;
                $check_code = ($check_code == 10) ? 'x' : $check_code;
                if ($code == $check_code) {
                    return true;
                }
            }
            if (strlen($application_no) == 9) {
                $number = substr($application_no, 0, 8);
                $code = strtolower(substr($application_no, -1));
                $check_code = 0;
                for ($i = 0; $i < strlen($number); $i++) {
                    $check_code += substr($number, $i, 1) * ($i + 2);
                }
                $check_code = $check_code % 11;
                $check_code = ($check_code == 10) ? 'x' : $check_code;
                if ($check_code == $code) {
                    return true;
                }
            }
        }
        return false;
    }
}
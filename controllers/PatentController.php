<?php
/**
 * User: Mr-mao
 * Date: 2017/10/26
 * Time: 21:44
 */


namespace app\controllers;

use app\models\OverdueFineApi;
use app\models\PaidFee;
use app\models\UnpaidFee;
use app\models\OverdueFine;
use app\models\ChangeOfBibliographicData;
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
                throw new NotFoundHttpException('专利号不存在');
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
     *     path="/patents/due/{days}",
     *     tags={"Patent"},
     *     summary="获取即将到期的专利号",
     *     description="根据传递进来的天数获取即将到期的专利号",
     *     @SWG\Parameter(
     *          in = "path",
     *          name = "days",
     *          description = "天数,可用-1,20等表示(正数不要写+号)",
     *          required = true,
     *          type = "integer"
     *     ),
     *     @SWG\Response(
     *          response = 200,
     *          description = "OK",
     *     ),
     * )
     */
    public function actionDue()
    {
        $days = (int)Yii::$app->request->getQueryParam('days');
        // 本地测试的子查询比left join快一些
        // SELECT DISTINCT application_no,unpaid_fee.patent_id FROM `unpaid_fee` LEFT JOIN patent ON unpaid_fee.patent_id = patent.id WHERE TO_DAYS(due_date)-TO_DAYS(NOW()) = 1; # 0.2999s
        // SELECT application_no FROM patent WHERE id in (SELECT DISTINCT patent_id FROM unpaid_fee WHERE TO_DAYS(due_date)-TO_DAYS(NOW()) = 1); # 0.1720s
        $sql = "SELECT application_no FROM patent WHERE id in (SELECT DISTINCT patent_id FROM unpaid_fee WHERE TO_DAYS(due_date)-TO_DAYS(NOW()) = $days)";
        $result = Yii::$app->db->createCommand($sql)->queryAll();
        return array_column($result, 'application_no');
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
     *     path="/patents/{application_no}/latest-unpaid-fees",
     *     tags={"Patent"},
     *     summary="最近一个日期所有的未缴费信息",
     *     description="获取数据库里时间最靠前的那个时间点的所有待缴费信息，正常情况下返回一条，如果有滞纳金则返回两条",
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
     * @return array|null|\yii\db\ActiveRecord
     */
    public function actionLatestUnpaidFees()
    {
        return UnpaidFeeApi::find()
            ->where(['patent_id' => $this->_patent->id])
            ->andWhere(['in', 'due_date', \app\models\UnpaidFee::find()->where(['patent_id' => $this->_patent->id])->min('due_date')])
            ->all();
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
     * 返回所有的申请号
     *
     * @return array
     */
    public function actionList()
    {
        return Patent::find()->select(['application_no'])->column();
    }
    
    /**
     * put 方式更新数据，不存在就添加
     *
     * @throws BadRequestHttpException
     */
    public function actionUpdate()
    {
        $result = Yii::$app->request->bodyParams;
        if (!isset($result['application_no'])) {
            throw new BadRequestHttpException('application_no is required');
        }
        $patent = Patent::findOne(['application_no' => $result['application_no']]);
        if (!$patent) {
            if (!$this->checkApplicationNo($result['application_no'])) {
                throw new BadRequestHttpException('申请号校验失败');
            }
            $patent = new Patent();
            $patent->application_no = $result['application_no'];
        }

        $patent->patent_type = $result['patent_type'] ?? '';
        $patent->title = $result['title'] ?? '';
        $patent->filing_date = $result['filing_date'] ?? '';
        $patent->case_status = $result['case_status'] ?? '';
        $patent->general_status = $result['general_status'] ?? null;
        $patent->publication_date = $result['publication_date'] ?? '';
        $patent->publication_no = $result['publication_no'] ? str_replace(' ','',$result['publication_no']) : '';
        $patent->issue_announcement = $result['issue_announcement'] ?? '';
        $patent->issue_no = $result['issue_no'] ? str_replace(' ','',$result['issue_no']) : '';
        $patent->applicants = $result['applicants'] ?? '';
        $patent->inventors = $result['inventors'] ?? '';
        $patent->ip_agency = $result['ip_agency'] ?? '';
        $patent->first_named_attorney = $result['first_named_attorney'] ?? '';
        $patent->updated_at = time();
        $patent->basic_updated_at = time();
        if (isset($result['paid_fee'])) {
            $patent->payment_updated_at = time();
        }

        if (!$patent->save()) {
            throw new BadRequestHttpException(implode(' ', array_column($patent->errors, 0)));
        }

        if (isset($result['paid_fee'])) {
            PaidFee::deleteAll(['patent_id' => $patent->id]);
            $paid_fee_model = new PaidFee();
            foreach ($result['paid_fee'] as $paid_fee) {
                $_model = clone $paid_fee_model;
                $paid_fee['patent_id'] = $patent->id;
                $_model->setAttributes($paid_fee);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }

        if (isset($result['unpaid_fee'])) {
            UnpaidFee::deleteAll(['patent_id' => $patent->id]);
            $unpaid_fee_model = new UnpaidFee();
            foreach ($result['unpaid_fee'] as $unpaid_fee) {
                $_model = clone $unpaid_fee_model;
                $unpaid_fee['patent_id'] = $patent->id;
                $_model->setAttributes($unpaid_fee);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }

        if (isset($result['over_due_fee'])) {
            OverdueFine::deleteAll(['patent_id' => $patent->id]);
            $over_due_model = new OverdueFine();
            foreach ($result['over_due_fee'] as $over_due) {
                $_model = clone $over_due_model;
                $over_due['patent_id'] = $patent->id;
                $_model->setAttributes($over_due);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }

        if (isset($result['change_of_bibliographic']) && !empty($result['change_of_bibliographic'])) {
            ChangeOfBibliographicData::deleteAll(['patent_id' => $patent->id]);
            $change = new ChangeOfBibliographicData();
            foreach ($result['change_of_bibliographic'] as $item) {
                $_model = clone $change;
                $item['patent_id'] = $patent->id;
                $_model->setAttributes($item);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }
    }

    /**
     * 只更新费用信息 post
     *
     * @throws BadRequestHttpException
     */
    public function actionUpdateFees()
    {
        $patent_id = $this->_patent->id;
        $result = Yii::$app->request->bodyParams;
        if (isset($result['paid_fee'])) {
            PaidFee::deleteAll(['patent_id' => $patent_id]);
            $paid_fee_model = new PaidFee();
            foreach ($result['paid_fee'] as $paid_fee) {
                $_model = clone $paid_fee_model;
                $paid_fee['patent_id'] = $patent_id;
                $_model->setAttributes($paid_fee);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }

        if (isset($result['unpaid_fee'])) {
            $patent = Patent::findOne(['application_no' => $patent_id]);
            $patent->payment_updated_at = time();
            $patent->save();

            UnpaidFee::deleteAll(['patent_id' => $patent_id]);
            $unpaid_fee_model = new UnpaidFee();
            foreach ($result['unpaid_fee'] as $unpaid_fee) {
                $_model = clone $unpaid_fee_model;
                $unpaid_fee['patent_id'] = $patent_id;
                $_model->setAttributes($unpaid_fee);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }

        if (isset($result['over_due_fee'])) {
            OverdueFine::deleteAll(['patent_id' => $patent_id]);
            $over_due_model = new OverdueFine();
            foreach ($result['over_due_fee'] as $over_due) {
                $_model = clone $over_due_model;
                $over_due['patent_id'] = $patent_id;
                $_model->setAttributes($over_due);
                if (!$_model->save()) {
                    throw new BadRequestHttpException(implode(' ', array_column($_model->errors, 0)));
                }
            }
        }
        Yii::$app->response->statusCode = 201;
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
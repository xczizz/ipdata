<?php
/**
 * User: JokerRoc
 * Date: 2017/10/26
 * Time: 16:35
 * Desc: 申请号同步脚本
 */

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\Patent;
use app\models\ipapp\Patents;

class SyncAppNoController extends Controller
{
    public $queue = [];

    /**
     * 申请号同步脚本
     */
    public function actionIndex()
    {
        echo 'Start time: ' . date('y/m/d H:i:s') . PHP_EOL;
        $successCount = 0;
        $data = Patents::find()
            ->select(['patentApplicationNo'])
            ->where(['not in', 'patentApplicationNo', ['', 'Not Available Yet']])
            ->asArray()
            ->all();

        foreach ($data as $key => $val) {
            $application_no = $val['patentApplicationNo'];
            // 判断申请号是否存在
            if (!Patent::find()->where(['application_no'=>$application_no])->exists()) {
                $model = new Patent;
                $model->application_no = $application_no;
                $model->save();
                $successCount++;
            }
        }
        echo 'Successfully written: '.$successCount . PHP_EOL;
        echo 'End time: ' . date('y/m/d H:i:s');
    }

}
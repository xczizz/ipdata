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
     * 把所有的申请号同步到redis集合中
     */
    public function actionSyncRedis()
    {
        $application_nos = Patent::find()
            ->select(['application_no'])
            ->asArray()
            ->column();

        foreach ($application_nos as $application_no) {
            // 同步申请号到redis中
            Yii::$app->redis->sadd(Patent::APP_NOS_REDIS_KEY, $application_no);
        }
    }

    /**
     * 申请号同步脚本
     */
    public function actionIndex()
    {
        echo 'Start time: ' . date('y/m/d H:i:s') . PHP_EOL;
        $start_time = microtime(true);
        $successCount = 0;
        // 同步过的申请号redis key
        $redis_key = 'commands:synced:application_nos';
        $application_nos = Patents::find()
            ->select(['patentApplicationNo'])
            ->where(['not in', 'patentApplicationNo', ['', 'Not Available Yet']])
            ->asArray()
            ->column();

        foreach ($application_nos as $application_no) {
            // 判断申请号是否存在
            if (!Patent::appNoExist($application_no)) {
                $model = new Patent;
                $model->application_no = $application_no;
                $model->save();
                $successCount++;
            }
        }
        echo 'Successfully written: '.$successCount . PHP_EOL;
        $end_time = microtime(true);
        echo '花费时间: '. round($end_time-$start_time, 2) . PHP_EOL;
        echo 'End time: ' . date('y/m/d H:i:s') . PHP_EOL;

    }

}
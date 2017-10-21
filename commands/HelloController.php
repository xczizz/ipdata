<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use yii\console\Controller;
use app\models\Patent;

/**
 * This command echoes the first argument that you have entered.
 *
 * This command is provided as an example for you to learn how to create console commands.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class HelloController extends Controller
{
    /**
     * This command echoes what you have entered as the message.
     * @param string $message the message to be echoed.
     */
    public function actionIndex($message = 'hello world')
    {
        echo $message . "\n";
    }

    public function actionImport()
    {
    	$successCount = 0;
        echo 'Start time: ' . date('y/m/d H:i:s') . PHP_EOL;
        for ($i = 1; $i <= 7; $i++) {
        	$path = './runtime/' . $i . '.xls';
        	$objReader = \PHPExcel_IOFactory::load($path);
        	$sheetData = $objReader->getActiveSheet()->toArray(null,true,true,true);
        	foreach ($sheetData as $key => $value) {
        		if ($key >= 4) {
        			$application_no = str_replace('.', '', substr($value['E'], 2));
        			if (!Patent::findOne(['application_no' => $application_no])) {
        				$model = new Patent;
        				$model->application_no = $application_no;
        				if (!$model->save()) {
	                        echo 'Error: ' . $application_no . PHP_EOL;
	                        print_r($model->errors);
	                        echo PHP_EOL;
	                    } else {
	                        $successCount ++;
	                    }
        			} else {
						echo $application_no . ' already exists!' . PHP_EOL;
        			}
        		}
        	}
        	echo $i . '.xsl finished!' . PHP_EOL;
        }
        echo 'Successfully written: '.$successCount.PHP_EOL;
        echo 'End time: ' . date('y/m/d H:i:s');
    }
}

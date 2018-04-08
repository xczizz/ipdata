<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use app\models\UnpaidFee;
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

    public function actionExport()
    {
        $path = 'runtime/overdue-fine-patents.xlsx';
        if (is_file($path)) unlink($path);

        $excel = new \PHPExcel();
        $excel->getProperties()
            ->setCreator("阳光惠远客服中心")
            ->setLastModifiedBy("阳光惠远")
            ->setTitle("哈工大有滞纳金的年费信息汇总")
            ->setKeywords("滞纳金");
        $excel->getActiveSheet()->getColumnDimension('A')->setWidth(23);
        $excel->getActiveSheet()->getColumnDimension('B')->setWidth(45);
        $excel->getActiveSheet()->getColumnDimension('C')->setWidth(17);
        $excel->getActiveSheet()->getColumnDimension('D')->setWidth(17);
        $excel->getActiveSheet()->getColumnDimension('E')->setWidth(28);
        $excel->getActiveSheet()->getColumnDimension('F')->setWidth(28);
        $excel->getActiveSheet()->getColumnDimension('G')->setWidth(17);
        $excel->getActiveSheet()->getColumnDimension('H')->setWidth(10);
        $excel->getActiveSheet()->getColumnDimension('I')->setWidth(10);
        $excel->getActiveSheet()->getColumnDimension('J')->setWidth(26);

        $titleStyleArray = [
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER
            ],
            'fill' => [
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
                'color' => [
                    'rgb' => 'C0C0C0' // 单元格背景设置灰色
                ]
            ]
        ];
        $contentStyleArray = [
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_TOP
            ],
            'borders' => [
                'allborders' => [
                    'style' => \PHPExcel_Style_Border::BORDER_DOTTED,
                    'color' => [
                        'rgb' => '62ACFE' // 设置单元格边框颜色
                    ]
                ]
            ]
        ];
        $contentFont = [
            'name' => '微软雅黑',
            'size' => 10
        ];


        $excel->getActiveSheet()
            ->setCellValue('A1','专利号')
            ->setCellValue('B1','专利名称')
            ->setCellValue('C1','申请日')
            ->setCellValue('D1','授权公告日')
            ->setCellValue('E1','发明人')
            ->setCellValue('F1','专利人')
            ->setCellValue('G1','缴费截止日期')
            ->setCellValue('H1','缴费金额')
            ->setCellValue('I1','滞纳金')
            ->setCellValue('J1','缴费类型');

        $excel->getActiveSheet()->freezePane('K2');
        for ($i = ord('A'); $i <= ord('J'); $i++) {
            // 设置第一行加粗
            $excel->getActiveSheet()->getStyle((string)chr($i) . '1')->applyFromArray($titleStyleArray)->getFont()->setBold(true);
        }

        $overdue_list = UnpaidFee::find()->where(['like', 'type', '滞纳金'])->asArray()->all();
        $idx = 2;
        foreach ($overdue_list as $value) {
            $overdue_annual_fee = UnpaidFee::find()->where(['due_date' => $value['due_date'], 'patent_id' => $value['patent_id']])->andWhere(['<>', 'id', $value['id']])->asArray()->all();
            if (count($overdue_annual_fee) == 1) {
                $basic_info = Patent::findOne($value['patent_id'])->toArray();
                if (mb_strpos($basic_info['applicants'], '哈尔滨工业大学') === false) continue; // 如果不是工大就跳出
                $application_no = $basic_info['application_no'];
                $title = $basic_info['title'];
                $filing_date = $basic_info['filing_date']; // 申请日
                $issue_announcement = $basic_info['issue_announcement']; // 授权公告日
                $inventors = $basic_info['inventors']; // 发明人
                $applicants = $basic_info['applicants']; // 专利人
                $due_date = $value['due_date']; // 缴费截止日
                $amount = $overdue_annual_fee[0]['amount']; // 缴费金额
                $overdue = $value['amount']; // 滞纳金
                $type = $overdue_annual_fee[0]['type']; // 年费名称(第几年)

                $excel->setActiveSheetIndex()
                    ->setCellValue('A' . (string)($idx), $application_no)
                    ->setCellValue('B'. (string)($idx), $title)
                    ->setCellValue('C'. (string)($idx), $filing_date)
                    ->setCellValue('D'. (string)($idx), $issue_announcement)
                    ->setCellValue('E'. (string)($idx), $inventors)
                    ->setCellValue('F'. (string)($idx), $applicants)
                    ->setCellValue('G'. (string)($idx), $due_date)
                    ->setCellValue('H'. (string)($idx), $amount)
                    ->setCellValue('I'. (string)($idx), $overdue)
                    ->setCellValue('J'. (string)($idx), $type);

                for ($i = ord('A'); $i <= ord('J'); $i++) {
                    if ($i == ord('A')) {
                        $excel->getActiveSheet()->getStyle((string)chr($i) . (string)($idx))->getNumberFormat()->setFormatCode('000000000');
                    }
                    $excel->getActiveSheet()->getStyle((string)chr($i) . (string)($idx))->applyFromArray($contentStyleArray)->getFont()->applyFromArray($contentFont);
                    $excel->getActiveSheet()->getStyle((string)chr($i) . (string)($idx))->getAlignment()->setIndent(1);
                }

                $excel->getActiveSheet()->getRowDimension($idx)->setRowHeight(20);
                ++ $idx;
            } else {
                // 目前所有有滞纳金的日期当天都是对应滞纳金和年费，没有其他相关的费用
            }
        }
        $excel->setActiveSheetIndex(0);

        echo date('H:i:s') , " Write to Excel2007 format" , PHP_EOL;
        $callStartTime = microtime(true);

        $objWrite = \PHPExcel_IOFactory::createWriter($excel,'Excel2007');
        $objWrite->save($path);

        $callEndTime = microtime(true);
        $callTime = $callEndTime - $callStartTime;
        echo date('H:i:s') , " File written to " , $path , PHP_EOL;
        echo 'Call time to write Workbook was ' , sprintf('%.4f',$callTime) , " seconds" , PHP_EOL;
// Echo memory usage
        echo date('H:i:s') , ' Current memory usage: ' , (memory_get_usage(true) / 1024 / 1024) , " MB" , PHP_EOL;
        echo date('H:i:s') , " Peak memory usage: " , (memory_get_peak_usage(true) / 1024 / 1024) , " MB" , PHP_EOL;
    }

    public function actionGeneralImport($name)
    {
        $successCount = 0;
        echo '开始时间: ' . date('y/m/d H:i:s') . PHP_EOL;
        $path = './runtime/' . $name;
        $objReader = \PHPExcel_IOFactory::load($path);
        $sheetData = $objReader->getActiveSheet()->toArray(null,true,true,true);
        $existCount = 0;
        foreach ($sheetData as $key => $value) {
            if ($key >= 2) {
                $application_no = str_replace('.', '', substr($value['B'], 2));
                if (!Patent::findOne(['application_no' => $application_no])) {
                    $model = new Patent;
                    $model->application_no = $application_no;
                    if (!$model->save()) {
                        echo '导入错误: ' . $application_no . PHP_EOL;
                        print_r($model->errors);
                        echo PHP_EOL;
                    } else {
                        $successCount ++;
                    }
                } else {
                    $existCount ++;
                }
            }
        }
        echo '导入成功：'.$successCount.'条'.PHP_EOL.'已存在：'.$existCount.'条'.PHP_EOL;
        echo '完成时间: ' . date('y/m/d H:i:s');
    }
}

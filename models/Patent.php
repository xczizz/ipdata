<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "patent".
 *
 * @property integer $id
 * @property string $application_no
 * @property string $patent_type
 * @property string $title
 * @property string $filing_date
 * @property string $case_status
 * @property string $general_status
 * @property string $publication_date
 * @property string $publication_no
 * @property string $issue_announcement
 * @property string $issue_no
 * @property string $applicants
 * @property string $inventors
 * @property string $ip_agency
 * @property string $first_named_attorney
 * @property integer $updated_at
 * @property integer $basic_updated_at
 * @property integer $publication_updated_at
 * @property integer $payment_updated_at
 */
class Patent extends \yii\db\ActiveRecord
{
    // 申请号redis key
    const APP_NOS_REDIS_KEY = 'patent:application_nos';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'patent';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['publication_date', 'issue_announcement'], 'safe'],
            [['updated_at', 'basic_updated_at', 'publication_updated_at', 'payment_updated_at'], 'integer'],
            [['application_no', 'general_status'], 'string', 'max' => 20],
            [['patent_type'], 'string', 'max' => 10],
            [['title', 'applicants', 'inventors'], 'string', 'max' => 500],
            [['filing_date', 'case_status', 'publication_no', 'issue_no'], 'string', 'max' => 50],
            [['ip_agency', 'first_named_attorney'], 'string', 'max' => 255],
            [['application_no'], 'unique'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'application_no' => 'Application No',
            'patent_type' => 'Patent Type',
            'title' => 'Title',
            'filing_date' => 'Filing Date',
            'case_status' => 'Case Status',
            'general_status' => 'General Status',
            'publication_date' => 'Publication Date',
            'publication_no' => 'Publication No',
            'issue_announcement' => 'Issue Announcement',
            'issue_no' => 'Issue No',
            'applicants' => 'Applicants',
            'inventors' => 'Inventors',
            'ip_agency' => 'Ip Agency',
            'first_named_attorney' => 'First Named Attorney',
            'updated_at' => 'Updated At',
            'basic_updated_at' => 'Basic Updated At',
            'publication_updated_at' => 'Publication Updated At',
            'payment_updated_at' => 'Payment Updated At',
        ];
    }

    /**
     * 保存数据后的操作
     *
     * @param bool $insert
     * @param array $changedAttributes
     */
    public function afterSave($insert, $changedAttributes)
    {
        if ($insert) {
            // 同步申请号到redis中
            Yii::$app->redis->sadd(Patent::APP_NOS_REDIS_KEY, $this->application_no);
        }
        return parent::afterSave($insert, $changedAttributes);
    }

    /**
     * 判断申请号是否存在
     *
     * @param string $application_no
     */
    public static function appNoExist($application_no)
    {
        return Yii::$app->redis->sismember(self::APP_NOS_REDIS_KEY, $application_no);
    }


}

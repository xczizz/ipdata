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
 * @property string $publication_date
 * @property string $publication_no
 * @property string $issue_announcement
 * @property string $applicants
 * @property string $inventors
 * @property string $ip_agency
 * @property string $first_named_attorney
 * @property integer $updated_at
 */
class Patent extends \yii\db\ActiveRecord
{
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
            [['application_no'], 'required'],
            [['publication_date', 'issue_announcement'], 'safe'],
            [['updated_at'], 'integer'],
            [['application_no', 'publication_no', 'ip_agency', 'first_named_attorney'], 'string', 'max' => 255],
            [['patent_type'], 'string', 'max' => 20],
            [['title', 'applicants', 'inventors'], 'string', 'max' => 500],
            [['filing_date', 'case_status'], 'string', 'max' => 50],
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
            'publication_date' => 'Publication Date',
            'publication_no' => 'Publication No',
            'issue_announcement' => 'Issue Announcement',
            'applicants' => 'Applicants',
            'inventors' => 'Inventors',
            'ip_agency' => 'Ip Agency',
            'first_named_attorney' => 'First Named Attorney',
            'updated_at' => 'Updated At',
        ];
    }
}

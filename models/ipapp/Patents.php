<?php

namespace app\models\ipapp;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "patents".
 *
 * @property integer $patentID
 * @property string $patentAjxxbID
 * @property string $patentEacCaseNo
 * @property string $patentType
 * @property integer $patentUserID
 * @property string $patentUsername
 * @property integer $patentUserLiaisonID
 * @property string $patentUserLiaison
 * @property string $patentAgent
 * @property string $patentProcessManager
 * @property string $patentTitle
 * @property string $patentApplicationNo
 * @property string $patentPatentNo
 * @property string $patentNote
 * @property string $patentApplicationDate
 * @property integer $patentFeeManagerUserID
 * @property string $patentCaseStatus
 * @property string $patentApplicationInstitution
 * @property string $patentInventors
 * @property string $patentAgency
 * @property string $patentAgencyAgent
 * @property string $patentFeeDueDate
 * @property string $patentAlteredItems
 * @property integer $UnixTimestamp
 *
 * @property Patentevents[] $patentevents
 * @property Patentfiles[] $patentfiles
 */
class Patents extends ActiveRecord
{

    public static function getDb()
    {
        return \Yii::$app->dbIpapp;
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'patents';
    }

}

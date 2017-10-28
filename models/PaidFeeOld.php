<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "paid_fee_old".
 *
 * @property integer $id
 * @property integer $patent_id
 * @property string $type
 * @property integer $amount
 * @property string $paid_date
 * @property string $paid_by
 * @property string $receipt_no
 */
class PaidFeeOld extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'paid_fee_old';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['patent_id'], 'required'],
            [['patent_id', 'amount'], 'integer'],
            [['paid_date'], 'safe'],
            [['type', 'receipt_no'], 'string', 'max' => 255],
            [['paid_by'], 'string', 'max' => 20],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'patent_id' => 'Patent ID',
            'type' => 'Type',
            'amount' => 'Amount',
            'paid_date' => 'Paid Date',
            'paid_by' => 'Paid By',
            'receipt_no' => 'Receipt No',
        ];
    }

    public function fields()
    {
        return [
            'type',
            'amount',
            'paid_date',
            'paid_by',
            'receipt_no'
        ];
    }
}

<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "paid_fee".
 *
 * @property integer $id
 * @property integer $patent_id
 * @property string $type
 * @property integer $amount
 * @property string $paid_date
 * @property string $paid_by
 * @property string $receipt_no
 */
class PaidFee extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'paid_fee';
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
            [['type', 'receipt_no', 'paid_by'], 'string', 'max' => 255],
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
}

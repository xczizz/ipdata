<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "unpaid_fee".
 *
 * @property integer $id
 * @property integer $patent_id
 * @property string $type
 * @property integer $amount
 * @property string $due_date
 */
class UnpaidFee extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'unpaid_fee';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['patent_id', 'amount'], 'integer'],
            [['due_date'], 'safe'],
            [['type'], 'string', 'max' => 50],
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
            'due_date' => 'Due Date',
        ];
    }
}

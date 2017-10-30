<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "overdue_fine_old".
 *
 * @property integer $id
 * @property integer $patent_id
 * @property string $due_date
 * @property integer $original_amount
 * @property integer $fine_amount
 * @property integer $total_amount
 */
class OverdueFineOld extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'overdue_fine_old';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['patent_id'], 'required'],
            [['patent_id', 'original_amount', 'fine_amount', 'total_amount'], 'integer'],
            [['due_date'], 'string', 'max' => 255],
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
            'due_date' => 'Due Date',
            'original_amount' => 'Original Amount',
            'fine_amount' => 'Fine Amount',
            'total_amount' => 'Total Amount',
        ];
    }

    public function fields()
    {
        return [
            'due_date',
            'original_amount',
            'fine_amount',
            'total_amount'
        ];
    }
}

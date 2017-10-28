<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "change_of_bibliographic_data_old".
 *
 * @property integer $id
 * @property integer $patent_id
 * @property string $date
 * @property string $changed_item
 * @property string $before_change
 * @property string $after_change
 */
class ChangeOfBibliographicDataOld extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'change_of_bibliographic_data_old';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['patent_id'], 'required'],
            [['patent_id'], 'integer'],
            [['date'], 'safe'],
            [['changed_item', 'before_change', 'after_change'], 'string', 'max' => 255],
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
            'date' => 'Date',
            'changed_item' => 'Changed Item',
            'before_change' => 'Before Change',
            'after_change' => 'After Change',
        ];
    }

    public function fields()
    {
        return [
            'date',
            'changed_item',
            'before_change' => function () {
                // 莫名其秒的会出现 \r\n 这种换行符,暂时先 trim 处理一下
                return trim($this->before_change);
            },
            'after_change' => function () {
                return trim($this->after_change);
            },
        ];
    }
}

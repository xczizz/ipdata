<?php

namespace app\models;

class ChangeOfBibliographicDataApi extends ChangeOfBibliographicData
{

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

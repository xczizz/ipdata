<?php

namespace app\models;

class UnpaidFeeApi extends UnpaidFee
{
    public function fields()
    {
        return [
        	'id',
            'type',
            'amount',
            'due_date'
        ];
    }
}

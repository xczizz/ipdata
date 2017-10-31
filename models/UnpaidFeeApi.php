<?php

namespace app\models;

class UnpaidFeeApi extends UnpaidFee
{
    public function fields()
    {
        return [
            'type',
            'amount',
            'due_date'
        ];
    }
}

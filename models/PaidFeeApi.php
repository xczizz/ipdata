<?php

namespace app\models;


class PaidFeeApi extends PaidFee
{

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

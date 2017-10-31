<?php

namespace app\models;

class OverdueFineApi extends OverdueFine
{

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

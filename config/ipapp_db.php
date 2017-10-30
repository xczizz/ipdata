<?php

$params = require( __DIR__.'/params.php');

return [
    'class' => 'yii\db\Connection',
    'dsn' => 'mysql:host='. $params['ipapp_db']['host'] .';dbname='. $params['ipapp_db']['name'],
    'username' => $params['ipapp_db']['username'],
    'password' => $params['ipapp_db']['password'],
    'charset' => 'utf8mb4',
];

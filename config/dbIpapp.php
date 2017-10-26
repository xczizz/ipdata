<?php

$params = require( __DIR__.'/params.php');

return [
    'class' => 'yii\db\Connection',
    'dsn' => 'mysql:host='. $params['dbIpapp']['host'] .';dbname='. $params['dbIpapp']['name'],
    'username' => $params['dbIpapp']['username'],
    'password' => $params['dbIpapp']['password'],
    'charset' => 'utf8mb4',
];

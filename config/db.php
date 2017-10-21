<?php

$params = require( __DIR__.'./params.php');

return [
    'class' => 'yii\db\Connection',
    'dsn' => 'mysql:host='. $params['db']['host'] .';dbname='. $params['db']['name'],
    'username' => $params['db']['username'],
    'password' => $params['db']['password'],
    'charset' => 'utf8mb4',
];

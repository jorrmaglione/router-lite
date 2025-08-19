<?php

include_once __DIR__ . '/../vendor/autoload.php';
include_once __DIR__ . '/Controller.php';

use Jorrmaglione\RouterLite\Router;

$router = new Router();
$router->get('/users', 'Controller::method');
$router->post('/users', 'Controller::method');
$router->get('/users/{id}', 'Controller::method');
$router->put('/users/{id}', 'Controller::method');
$router->delete('/users/{id}', 'Controller::method');
$router->run('GET', '/users/1');

<?php
require_once __DIR__ . '/../app/bootstrap.php';
Auth::logout();
redirect('login.php');

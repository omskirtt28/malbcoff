<?php
require __DIR__ . '/bootstrap.php';
Auth::logout();
redirect('login.php');

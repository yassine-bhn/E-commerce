<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if (true === filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    umask(0000);
}

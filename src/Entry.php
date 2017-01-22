<?php

$input = file_get_contents('php://stdin');
spl_autoload_register(function ($class) {
    if (is_file($file = __DIR__ . '/' . substr(strtr($class, '\\', '/'), 10) . '.php')) {
        require $file;
    }
});
$configs = unserialize($input);

$argv = $configs['argv'];
$server = new Laravoole\Server($configs['mode']);
$server->start(
    $configs['host'],
    $configs['port'],
    $configs['pid_file'],
    $configs['root_dir'],
    $configs['handler_config'],
    $configs['wrapper_config'],
    $configs['config']
);

<?php
namespace Laravoole;

use Exception;
use ErrorException;

use Laravoole\Illuminate\Application;
use Laravoole\Illuminate\Request as IlluminateRequest;

use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;

abstract class Base
{
    public $config;

    protected $root_dir;

    protected $pid_file;

    protected $handler_config;

    protected $kernel;

    protected $tmp_autoloader;

    protected $app;
    protected $origApp;

    protected $server;

    public function start()
    {
        throw new Exception(__CLASS__ . "::start MUST be implemented", 1);
    }

    final public function init($pid_file, $root_dir, $handler_config, $wrapper_config)
    {
        $this->pid_file = $pid_file;
        $this->root_dir = $root_dir;
        $this->handler_config = $handler_config;
        $this->wrapper_config = $wrapper_config;
    }

    public function prepareKernel()
    {
        // unregister temporary autoloader
        foreach (spl_autoload_functions() as $function) {
            spl_autoload_unregister($function);
        }

        require $this->root_dir . '/bootstrap/autoload.php';
        $this->app = $this->getApp();

        $this->kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        // from \Illuminate\Contracts\Console\Kernel
        // do not using Http\Kernel here, because needs SetRequestForConsole
        $this->app->bootstrapWith($this->config['bootstrapWith']);
        config(['laravoole.active' => 1]);
        $this->origApp = $this->app;
        chdir(public_path());
    }
    
    public function getOrigKernel()
    {
        $app = clone $this->origApp;
        $app->instance('app', $app);
        $app->instance('Illuminate\Container\Container', $app);
        $app->setInstance($app);
        $kernel = $this->kernel;
        $reset = \Closure::bind(function ($app) {
            $this->app = $app;
        }, $kernel, get_class($kernel));
        $reset($app);
        return $kernel;
    }

    public function onRequest($request, $response, $illuminate_request = false)
    {
        // for file system
        clearstatcache();
        if (config('laravoole.base_config.deal_with_public')) {
            if ($this->dealWithPublic($request, $response)) {
                return;
            }
        }

        try {
            ob_start();
            $kernel = $this->getOrigKernel();
            if (!$illuminate_request) {
                $illuminate_request = $this->dealWithRequest($request);
            }
            
            $this->app['events']->fire('laravoole.on_request', [$illuminate_request]);

            $illuminate_response = $kernel->handle($illuminate_request);


            // Is gzip enabled and the client accept it?
            $accept_gzip = config('laravoole.base_config.gzip') && isset($request->header['Accept-Encoding']) && stripos($request->header['Accept-Encoding'], 'gzip') !== false;
            $content = ob_get_clean();
            if (strlen($content)) {
                $illuminate_response->setContent($content.$illuminate_response->getContent());
            }
            $this->dealWithResponse($response, $illuminate_response, $accept_gzip);

        } catch (\Exception $e) {
            echo '[ERR] ' . $e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage() . PHP_EOL;
            echo $e->getTraceAsString() . PHP_EOL;
        } catch (\Throwable $e) {
            echo '[ERR] ' . $e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage() . PHP_EOL;
            echo $e->getTraceAsString() . PHP_EOL;
        } finally {
            if (isset($illuminate_response)) {
                $kernel->terminate($illuminate_request, $illuminate_response);
            }

            $this->clean($illuminate_request);

            return $response;
        }

    }

    protected function dealWithRequest($request, $classname = IlluminateRequest::class)
    {

        $get = isset($request->get) ? $request->get : array();
        $post = isset($request->post) ? $request->post : array();
        $cookie = isset($request->cookie) ? $request->cookie : array();
        $server = isset($request->server) ? $request->server : array();
        $header = isset($request->header) ? $request->header : array();
        $files = isset($request->files) ? $request->files : array();
        // $attr = isset($request->files) ? $request->files : array();

        $content = $request->rawContent() ?: null;

        return new $classname($get, $post, []/* attributes */, $cookie, $files, $server, $content);
    }

    private function dealWithResponse($response, $illuminate_response, $accept_gzip)
    {

        // status
        $response->status($illuminate_response->getStatusCode());
        // headers
        $response->header('Server', config('laravoole.base_config.server'));
        foreach ($illuminate_response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                $response->header($name, $value);
            }
        }
        // cookies
        foreach ($illuminate_response->headers->getCookies() as $cookie) {
            $response->rawcookie(
                $cookie->getName(),
                urlencode($cookie->getValue()),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain(),
                $cookie->isSecure(),
                $cookie->isHttpOnly()
            );
        }
        // content
        $content = $illuminate_response->getContent();

        // check gzip
        if ($accept_gzip && isset($response->header['Content-Type'])) {
            $mime = $response->header['Content-Type'];
            if (strlen($content) > config('laravoole.base_config.gzip_min_length') && is_mime_gzip($mime)) {
                $response->gzip(config('laravoole.base_config.gzip'));
            }
        }
        $this->endResponse($response, $content);
    }

    protected function clean(IlluminateRequest $request)
    {
        if ($request->hasSession()) {
            $request->getSession()->clear();
        }

        // Clean laravel cookie queue
        $cookies = $this->app->make(CookieJar::class);
        foreach ($cookies->getQueuedCookies() as $name => $cookie) {
            $cookies->unqueue($name);
        }

        if ($this->app->isProviderLoaded(\Illuminate\Auth\AuthServiceProvider::class)) {
            $this->app->register(\Illuminate\Auth\AuthServiceProvider::class, [], true);
            Facade::clearResolvedInstance('auth');
        }

        //...
    }

    public function endResponse($response, $content)
    {
        // send content & close
        $response->end($content);
    }

    protected function dealWithPublic($request, $response)
    {
        static $public_path;
        if (!$public_path) {
            $app = $this->app;
            $public_path = $app->make('path.public');

        }
        $uri = $request->server['REQUEST_URI'];
        $file = realpath($public_path . $uri);
        if (is_file($file)) {
            if (!strncasecmp($file, $uri, strlen($public_path))) {
                $response->status(403);
                $response->end();
            } else {
                $response->header('Content-Type', get_mime_type($file));
                if (!filesize($file)) {
                    $response->end();
                } else {
                    $response->sendfile($file);
                }
            }
            return true;
        }
        return false;

    }

    protected function getApp()
    {
        $app = new Application($this->root_dir);
        $rootNamespace = $app->getNamespace();
        $rootNamespace = trim($rootNamespace, '\\');

        $app->singleton(
            \Illuminate\Contracts\Http\Kernel::class,
            "\\{$rootNamespace}\\Http\\Kernel"
        );

        $app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            "\\{$rootNamespace}\\Console\\Kernel"
        );

        $app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            "\\{$rootNamespace}\\Exceptions\\Handler"
        );

        return $app;
    }

}

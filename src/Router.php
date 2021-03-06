<?php

namespace Vista\TinyRouter;

use ReflectionMethod;
use ReflectionFunction;
use RuntimeException;
use Psr\Http\Message\ServerRequestInterface;

class Router
{
    /**
     * The namespace for classes.
     *
     * @var string
     */
    protected $root = '';

    /**
     * The route rules.
     *
     * @var array
     */
    protected $rules = [];

    /**
     * The callbacks for the route rules.
     *
     * @var array
     */
    protected $callbacks = [];

    /**
     * Set the namespace for classes.
     *
     * @param string $namespace
     * @return void
     */
    public function setNamespace(string $namespace)
    {
        $this->root = trim($namespace, '\\');
    }

    /**
     * Dispatch the request to the processor and get the result.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return mixed
     */
    public function dispatch(ServerRequestInterface $request)
    {
        $request_uri = $request->getServerParams()['REQUEST_URI'];
        $uri_path = trim(parse_url($request_uri)['path'], '/');

        if (($index = $this->compareUri($uri_path)) < 0) {
            $processor = $this->resolveUri($uri_path);
            $reflector = $this->reflectMethod($processor->class, $processor->method);
            $params = [];
        } else {
            $reflector = $this->reflectCallback($index, $request);
            $params = $this->getParamsByUri($index, $uri_path);
        }

        $arguments = $this->bindArguments($reflector, $params);

        return $reflector->invokeArgs($arguments);
    }

    /**
     * Match the uri to find the matching route rule.
     *
     * @param string $uri
     * @return int
     */
    protected function compareUri(string $uri)
    {
        foreach ($this->rules as $key => $rule) {
            $rule_regex = preg_replace('/\{\w+\}/', '(\w+)', $rule);
            $pattern = '/' . str_replace('/', '\/', $rule_regex) . '/';

            if (preg_match($pattern, $uri) === 1) {
                return $key;
            }
        }

        return -1;
    }

    /**
     * Get class and method by parsing uri.
     *
     * @param string $uri
     * @return object
     */
    protected function resolveUri(string $uri)
    {
        $segments = explode('/', $uri);
        $method = array_pop($segments);
        
        foreach ($segments as $key => $segment) {
            if (!(strpos($segment, '_') === false)) {
                $segment = implode(array_map(function ($segment) {
                    $segment = ucfirst(strtolower($segment));

                    return $segment;
                }, explode('_', $segment)));
            } else {
                $segment = ucfirst($segment);
            }

            $segments[$key] = $segment;
        }

        $class = $this->root . '\\' . implode('\\', $segments);

        return (object)(['class' => $class, 'method' => $method]);
    }

    /**
     * Get class's method and convert it to a reflection class.
     *
     * @param mixed $class
     * @param string $method
     * @return \ReflectionFunction
     */
    protected function reflectMethod($class, string $method)
    {
        $object = is_string($class) ? new $class : $class;

        $reflector_method = new ReflectionMethod($object, $method);

        $closure = $reflector_method->getClosure($object);

        return new ReflectionFunction($closure);
    }

    /**
     * Get the corresponding callback and convert it to a reflection class.
     *
     * @param int $index
     * @param Psr\Http\Message\ServerRequestInterface $request
     * @return \ReflectionFunction|null
     */
    protected function reflectCallback(int $index, ServerRequestInterface $request)
    {
        $request_method = $request->getServerParams()['REQUEST_METHOD'];
        $request_method = strtolower($request_method);

        $callback = $this->callbacks[$index][$request_method];

        if (is_array($callback)) {
            if ((is_string($callback[0]) || is_object($callback[0])) && is_string($callback[1])) {
                return $this->reflectMethod($callback[0], $callback[1]);
            }
        } elseif (is_callable($callback)) {
            return new ReflectionFunction($callback);
        }

        return null;
    }

    /**
     * Get parameters by the path placeholder match uri.
     *
     * @param string $index
     * @param string $uri
     * @return array
     */
    protected function getParamsByUri(string $index, string $uri)
    {
        $rule_segments = explode('/', $this->rules[$index]);
        $uri_segments = explode('/', $uri);

        foreach ($rule_segments as $index => $segment) {
            if (preg_match('/\{(\w+)\}/', $segment, $matches) === 1) {
                $key = $matches[1];
                $value = $uri_segments[$index];
                $params[$key] = $value;
            }
        }

        return $params ?? [];
    }

    /**
     * Bind parameters to an anonymous function or object method.
     *
     * @param \ReflectionFunction $reflector
     * @param array $params
     * @return array
     */
    protected function bindArguments(ReflectionFunction $reflector, array $params)
    {
        $parameters = $reflector->getParameters();
        
        if (!empty($parameters)) {
            $reflector = $parameters[0]->getClass();
            
            if (count($parameters) == 1 && !is_null($reflector)) {
                if ($reflector->implementsInterface(RouteModelInterface::class)) {
                    $constructor = $reflector->getConstructor();

                    if (!is_null($constructor)) {
                        foreach ($constructor->getParameters() as $key => $parameter) {
                            if (isset($params[$parameter->name])) {
                                $value = $params[$parameter->name];
                                $arguments[$key] = $value;
                            }
                        }

                        $arguments = [$reflector->newInstanceArgs(($arguments ?? []))];
                    }
                } else {
                    if (isset($params[$parameters[0]->name])) {
                        $arguments[] = $params[$parameters[0]->name];
                    }
                }
            } else {
                foreach ($parameters as $key => $parameter) {
                    if (isset($params[$parameter->name])) {
                        $value = $params[$parameter->name];
                        $arguments[$key] = $value;
                    }
                }
            }
        }

        return $arguments ?? [];
    }

    /**
     * Handle dynamic method calls in the router.
     *
     * @param string $method
     * @param array $arguments
     * @return void
     * @throws \RuntimeException
     */
    public function __call($method, $arguments)
    {
        $method = strtolower($method);
        $rule = trim($arguments[0], '/');
        $keys = array_keys($this->rules, $rule);

        if (count($keys) > 0) {
            $key = current($keys);

            $this->callbacks[$key][$method] = $arguments[1];
        } else {
            $callbacks[$method] = $arguments[1];
            
            $this->rules[] = $rule;
            $this->callbacks[] = $callbacks;
        }
    }
}

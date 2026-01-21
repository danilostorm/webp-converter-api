<?php
namespace App;

/**
 * Simple Router for API endpoints
 */
class Router {
    private $routes = [];
    private $middleware = null;
    
    public function get($pattern, $callback) {
        $this->routes['GET'][$pattern] = $callback;
    }
    
    public function post($pattern, $callback) {
        $this->routes['POST'][$pattern] = $callback;
    }
    
    public function middleware($callback) {
        $this->middleware = $callback;
    }
    
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_GET['route'] ?? '';
        $path = '/' . trim($path, '/');
        
        // Run middleware for non-health routes
        $context = [];
        if ($this->middleware && $path !== '/api/v1/health') {
            $context = call_user_func($this->middleware);
        }
        
        // Match route
        if (!isset($this->routes[$method])) {
            Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
        }
        
        foreach ($this->routes[$method] as $pattern => $callback) {
            $regex = '#^' . preg_replace('#\\([a-z]+)#i', '([^/]+)', preg_quote($pattern, '#')) . '$#';
            
            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // Remove full match
                array_unshift($matches, $context); // Add context as first param
                call_user_func_array($callback, $matches);
                return;
            }
        }
        
        Response::error('NOT_FOUND', 'Endpoint not found', 404);
    }
}

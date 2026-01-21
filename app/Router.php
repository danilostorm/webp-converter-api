<?php
namespace App;

/**
 * Simple Router for API endpoints
 */
class Router {
    private $routes = [];
    private $middleware = null;
    private $publicRoutes = [];
    
    public function get($pattern, $callback, $public = false) {
        $this->routes['GET'][$pattern] = $callback;
        if ($public) {
            $this->publicRoutes[$pattern] = true;
        }
    }
    
    public function post($pattern, $callback, $public = false) {
        $this->routes['POST'][$pattern] = $callback;
        if ($public) {
            $this->publicRoutes[$pattern] = true;
        }
    }
    
    public function middleware($callback) {
        $this->middleware = $callback;
    }
    
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        // Get path from REQUEST_URI (works with both Apache and Nginx)
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Remove query string
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = '/' . trim($path, '/');
        
        // Match route first to know if it's public
        $matchedRoute = null;
        $matchedCallback = null;
        $matchedParams = [];
        
        if (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $pattern => $callback) {
                // Convert pattern to regex
                $regex = '#^' . preg_replace('/\\\(([^)]+)\\\)/', '([^/]+)', preg_quote($pattern, '#')) . '$#';
                
                if (preg_match($regex, $path, $matches)) {
                    array_shift($matches); // Remove full match
                    $matchedRoute = $pattern;
                    $matchedCallback = $callback;
                    $matchedParams = $matches;
                    break;
                }
            }
        }
        
        if (!$matchedCallback) {
            Response::error('NOT_FOUND', 'Endpoint not found: ' . $path, 404);
            return;
        }
        
        // Run middleware only for protected routes
        $context = [];
        $isPublic = isset($this->publicRoutes[$matchedRoute]);
        
        if ($this->middleware && !$isPublic) {
            $context = call_user_func($this->middleware);
        }
        
        // Execute callback
        array_unshift($matchedParams, $context);
        call_user_func_array($matchedCallback, $matchedParams);
    }
}

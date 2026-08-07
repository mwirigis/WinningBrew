<?php
/**
 * Jogu.php - The PHP Macroframework
 * 
 * A zero-config, plug-and-play framework that works as both a microframework 
 * and a full-stack framework. Single file, no dependencies, just upload and code!
 * 
 * @package Jogu
 * @version 3.0.0
 * @author Jogu Framework Team
 * @license MIT
 * @link https://jogu.framework
 * 
 * Jogu is a MACROFRAMEWORK - use it as a lightweight microframework for APIs
 * or scale it up to a full-featured MVC framework for enterprise applications.
 * Everything is included in a single file with zero configuration required.
 */

// ============================================================================
// JOGU CORE - The Heart of the Macroframework
// ============================================================================

class Jogu {
    // ========================================================================
    // CONFIGURATION
    // ========================================================================
    
    private static $instance = null;
    private $config = [];
    private $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => [], 'PATCH' => [], 'ANY' => []];
    private $middleware = [];
    private $globalMiddleware = [];
    private $routeMiddleware = [];
    private $middlewareAliases = [];
    private $basePath = '';
    private $modules = [];
    private $services = [];
    private $events = [];
    private $listeners = [];
    private $hooks = [];
    
    // Database
    private $db = null;
    private $tablePrefix = '';
    private $queryLog = [];
    private $transactionLevel = 0;
    private $cache = [];
    private $cacheDriver = 'file';
    private $cacheDir = '';
    private $connectionPool = [];
    
    // Application state
    private $booted = false;
    private $environment = 'development';
    private $debug = false;
    private $timezone = 'UTC';
    private $locale = 'en';
    private $fallbackLocale = 'en';
    private $translations = [];
    
    // ========================================================================
    // MAGIC METHODS & SINGLETON
    // ========================================================================
    
    public function __construct($config = []) {
        self::$instance = $this;
        $this->basePath = dirname($_SERVER['SCRIPT_FILENAME']);
        $this->environment = getenv('APP_ENV') ?: 'development';
        
        // Default configuration
        $this->config = array_merge([
            'debug' => ($this->environment !== 'production'),
            'timezone' => 'UTC',
            'locale' => 'en',
            'fallback_locale' => 'en',
            'table_prefix' => '',
            'cache_dir' => sys_get_temp_dir() . '/jogu_cache',
            'session_name' => 'jogu_session',
            'session_lifetime' => 3600,
            'secret_key' => $this->generateSecretKey(),
            'error_log' => null,
            'log_file' => null,
            'rate_limit' => 100,
            'rate_window' => 3600,
            'upload_max_size' => 2097152, // 2MB
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
            'view_path' => 'views/',
            'public_path' => 'public/',
            'storage_path' => 'storage/',
            'cache_enabled' => true,
            'cors_enabled' => false,
            'cors_origins' => ['*'],
            'cors_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'cors_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'security_headers' => true,
            'trusted_hosts' => [],
            'trusted_proxies' => [],
            'session_secure' => false,
            'session_httponly' => true,
            'session_samesite' => 'Lax',
            'csrf_protection' => true,
            'csrf_token_name' => 'csrf_token',
            'csrf_exclude' => [],
            'compression_enabled' => true,
            'compression_level' => 6,
            'minify_html' => false,
            'maintenance_mode' => false,
            'maintenance_message' => 'Site is under maintenance. Please check back later.',
            'maintenance_ips' => [],
            'database' => null
        ], $config);
        
        // Set timezone
        date_default_timezone_set($this->config['timezone']);
        
        // Initialize session if configured
        if ($this->config['session_name']) {
            $this->initSession();
        }
        
        // Setup database if provided
        if (isset($this->config['database']) && $this->config['database']) {
            $this->setupDatabase($this->config['database']);
        }
        
        // Setup cache
        if ($this->config['cache_enabled']) {
            $this->setupCache();
        }
        
        // Register default middleware
        $this->registerDefaultMiddleware();
        
        // Register default services
        $this->registerDefaultServices();
        
        // Setup error handling
        $this->setupErrorHandling();
        
        // Setup security headers
        if ($this->config['security_headers']) {
            $this->setupSecurityHeaders();
        }
        
        // Register shutdown function for maintenance
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __call($name, $arguments) {
        // Magic method for service container
        if (isset($this->services[$name])) {
            return call_user_func_array($this->services[$name], $arguments);
        }
        throw new Exception("Method $name not found");
    }
    
    // ========================================================================
    // SESSION MANAGEMENT
    // ========================================================================
    
    private function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            $options = [
                'name' => $this->config['session_name'],
                'lifetime' => $this->config['session_lifetime'],
                'httponly' => $this->config['session_httponly'],
                'secure' => $this->config['session_secure'],
                'samesite' => $this->config['session_samesite']
            ];
            
            if ($this->environment === 'production') {
                session_set_cookie_params($options);
            }
            
            session_start();
        }
    }
    
    public function session($key = null, $value = null) {
        if ($key === null) {
            return $_SESSION ?? [];
        }
        
        if ($value === null) {
            return $_SESSION[$key] ?? null;
        }
        
        $_SESSION[$key] = $value;
        return $this;
    }
    
    public function sessionFlash($key, $value = null) {
        if ($value === null) {
            $flash = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $flash;
        }
        
        $_SESSION['_flash'][$key] = $value;
        return $this;
    }
    
    // ========================================================================
    // DATABASE & ORM
    // ========================================================================
    
    public function setupDatabase($config) {
        try {
            if (is_string($config)) {
                // DSN string or SQLite path
                if (strpos($config, ':') === false) {
                    $config = "sqlite:" . $config;
                }
                $this->db = new PDO($config);
            } elseif (is_array($config)) {
                $dsn = "{$config['driver']}:host={$config['host']};dbname={$config['database']}";
                if (isset($config['port'])) {
                    $dsn .= ";port={$config['port']}";
                }
                if (isset($config['charset'])) {
                    $dsn .= ";charset={$config['charset']}";
                }
                if (isset($config['unix_socket'])) {
                    $dsn .= ";unix_socket={$config['unix_socket']}";
                }
                $this->db = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '');
            } elseif ($config instanceof PDO) {
                $this->db = $config;
            } else {
                throw new Exception("Invalid database configuration");
            }
            
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            
            // Enable foreign keys for SQLite
            if (strpos($config, 'sqlite') !== false) {
                $this->db->exec('PRAGMA foreign_keys = ON');
            }
            
            return $this;
        } catch (PDOException $e) {
            if ($this->config['debug']) {
                throw $e;
            }
            $this->logError($e->getMessage());
            return $this;
        }
    }
    
    public function getDb() {
        return $this->db;
    }
    
    public function table($table) {
        return new JoguTable($this, $this->tablePrefix . $table);
    }
    
    public function find($table, $id) {
        return $this->table($table)->find($id);
    }
    
    public function findOne($table, $conditions = [], $params = []) {
        return $this->table($table)->findOne($conditions, $params);
    }
    
    public function findAll($table, $conditions = [], $params = [], $order = null, $limit = null) {
        return $this->table($table)->findAll($conditions, $params, $order, $limit);
    }
    
    public function create($table, $data) {
        return $this->table($table)->create($data);
    }
    
    public function update($table, $data, $conditions = [], $params = []) {
        return $this->table($table)->update($data, $conditions, $params);
    }
    
    public function delete($table, $conditions = [], $params = []) {
        return $this->table($table)->delete($conditions, $params);
    }
    
    public function deleteById($table, $id) {
        return $this->table($table)->deleteById($id);
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $this->logQuery($sql, $params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError($e->getMessage() . " SQL: $sql");
            throw $e;
        }
    }
    
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }
    
    public function fetchRow($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function fetchAssoc($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $assoc = [];
        foreach ($rows as $row) {
            $assoc[$row[0]] = $row[1] ?? null;
        }
        return $assoc;
    }
    
    public function fetchColumn($sql, $params = [], $column = 0) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn($column);
    }
    
    public function execute($sql, $params = []) {
        return $this->query($sql, $params);
    }
    
    public function lastInsertId() {
        return $this->db->lastInsertId();
    }
    
    public function beginTransaction() {
        if ($this->transactionLevel == 0) {
            $this->db->beginTransaction();
        }
        $this->transactionLevel++;
        return $this;
    }
    
    public function commit() {
        $this->transactionLevel--;
        if ($this->transactionLevel == 0) {
            $this->db->commit();
        }
        return $this;
    }
    
    public function rollback() {
        $this->transactionLevel--;
        if ($this->transactionLevel == 0) {
            $this->db->rollBack();
        }
        return $this;
    }
    
    public function inTransaction() {
        return $this->transactionLevel > 0;
    }
    
    public function setTablePrefix($prefix) {
        $this->tablePrefix = $prefix;
        return $this;
    }
    
    public function getTablePrefix() {
        return $this->tablePrefix;
    }
    
    // ========================================================================
    // CACHE MANAGEMENT
    // ========================================================================
    
    private function setupCache() {
        $this->cacheDir = $this->config['cache_dir'];
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }
    
    public function cache($key, $callback = null, $ttl = 3600) {
        if ($callback === null) {
            return $this->getCache($key);
        }
        
        $cached = $this->getCache($key);
        if ($cached !== null) {
            return $cached;
        }
        
        $value = $callback();
        $this->setCache($key, $value, $ttl);
        return $value;
    }
    
    public function getCache($key) {
        if ($this->cacheDriver === 'memory') {
            return $this->cache[$key] ?? null;
        }
        
        $file = $this->cacheDir . '/' . md5($key) . '.cache';
        if (!file_exists($file)) return null;
        
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function setCache($key, $value, $ttl = 3600) {
        if ($this->cacheDriver === 'memory') {
            $this->cache[$key] = $value;
            return $this;
        }
        
        $file = $this->cacheDir . '/' . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        file_put_contents($file, serialize($data));
        return $this;
    }
    
    public function deleteCache($key) {
        if ($this->cacheDriver === 'memory') {
            unset($this->cache[$key]);
            return $this;
        }
        
        $file = $this->cacheDir . '/' . md5($key) . '.cache';
        if (file_exists($file)) unlink($file);
        return $this;
    }
    
    public function clearCache() {
        if ($this->cacheDriver === 'memory') {
            $this->cache = [];
            return $this;
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return $this;
    }
    
    // ========================================================================
    // ROUTING SYSTEM
    // ========================================================================
    
    public function get($pattern, $callback, $middleware = null) {
        return $this->addRoute('GET', $pattern, $callback, $middleware);
    }
    
    public function post($pattern, $callback, $middleware = null) {
        return $this->addRoute('POST', $pattern, $callback, $middleware);
    }
    
    public function put($pattern, $callback, $middleware = null) {
        return $this->addRoute('PUT', $pattern, $callback, $middleware);
    }
    
    public function delete($pattern, $callback, $middleware = null) {
        return $this->addRoute('DELETE', $pattern, $callback, $middleware);
    }
    
    public function patch($pattern, $callback, $middleware = null) {
        return $this->addRoute('PATCH', $pattern, $callback, $middleware);
    }
    
    public function options($pattern, $callback, $middleware = null) {
        return $this->addRoute('OPTIONS', $pattern, $callback, $middleware);
    }
    
    public function any($pattern, $callback, $middleware = null) {
        return $this->addRoute('ANY', $pattern, $callback, $middleware);
    }
    
    public function match($methods, $pattern, $callback, $middleware = null) {
        foreach ((array) $methods as $method) {
            $this->addRoute($method, $pattern, $callback, $middleware);
        }
        return $this;
    }
    
    private function addRoute($method, $pattern, $callback, $middleware = null) {
        $pattern = '/' . ltrim($pattern, '/');
        
        if ($middleware) {
            if (!isset($this->routeMiddleware[$pattern])) {
                $this->routeMiddleware[$pattern] = [];
            }
            $this->routeMiddleware[$pattern][] = $middleware;
        }
        
        if ($method === 'ANY') {
            foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $m) {
                $this->routes[$m][$pattern] = $callback;
            }
        } else {
            $this->routes[$method][$pattern] = $callback;
        }
        
        return $this;
    }
    
    public function resource($name, $controller) {
        $base = '/' . ltrim($name, '/');
        
        $this->get($base, [$controller, 'index']);
        $this->get($base . '/create', [$controller, 'create']);
        $this->post($base, [$controller, 'store']);
        $this->get($base . '/{id}', [$controller, 'show']);
        $this->get($base . '/{id}/edit', [$controller, 'edit']);
        $this->put($base . '/{id}', [$controller, 'update']);
        $this->patch($base . '/{id}', [$controller, 'update']);
        $this->delete($base . '/{id}', [$controller, 'destroy']);
        
        return $this;
    }
    
    public function apiResource($name, $controller) {
        $base = '/' . ltrim($name, '/');
        
        $this->get($base, [$controller, 'index']);
        $this->post($base, [$controller, 'store']);
        $this->get($base . '/{id}', [$controller, 'show']);
        $this->put($base . '/{id}', [$controller, 'update']);
        $this->patch($base . '/{id}', [$controller, 'update']);
        $this->delete($base . '/{id}', [$controller, 'destroy']);
        
        return $this;
    }
    
    public function group($prefix, $callback, $middleware = null) {
        $group = new JoguGroup($this, $prefix);
        if ($middleware) {
            $group->use($middleware);
        }
        $callback($group);
        return $this;
    }
    
    public function use($middleware) {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }
    
    public function middleware($name, $callback) {
        $this->middlewareAliases[$name] = $callback;
        return $this;
    }
    
    public function getRoutes() {
        return $this->routes;
    }
    
    // ========================================================================
    // REQUEST HANDLING
    // ========================================================================
    
    public function run() {
        // Maintenance mode check
        if ($this->config['maintenance_mode']) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!in_array($ip, $this->config['maintenance_ips'])) {
                echo $this->config['maintenance_message'];
                return;
            }
        }
        
        // Boot the application
        $this->boot();
        
        // Handle request
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';
        
        // Remove base path if any
        $baseDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($baseDir != '/' && strpos($uri, $baseDir) === 0) {
            $uri = substr($uri, strlen($baseDir));
            $uri = $uri ?: '/';
        }
        
        // CORS handling
        if ($this->config['cors_enabled']) {
            $this->handleCors();
            if ($method === 'OPTIONS') {
                http_response_code(200);
                return;
            }
        }
        
        // Run global middleware
        foreach ($this->globalMiddleware as $mw) {
            $result = $this->runMiddleware($mw);
            if ($result === false) {
                return;
            }
        }
        
        // Find matching route
        $routes = $this->routes[$method] ?? [];
        foreach ($routes as $pattern => $callback) {
            if ($this->matchRoute($pattern, $uri, $params)) {
                // Run route middleware
                if (isset($this->routeMiddleware[$pattern])) {
                    foreach ($this->routeMiddleware[$pattern] as $mw) {
                        $result = $this->runMiddleware($mw);
                        if ($result === false) {
                            return;
                        }
                    }
                }
                
                // Merge request data
                $params['app'] = $this;
                $params = array_merge($params, $_GET, $_POST);
                
                // Handle JSON input
                if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                    $input = json_decode(file_get_contents('php://input'), true);
                    if ($input) {
                        $params = array_merge($params, $input);
                    }
                }
                
                // CSRF protection
                if ($this->config['csrf_protection'] && 
                    in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']) &&
                    !in_array($pattern, $this->config['csrf_exclude'])) {
                    if (!$this->validateCsrf($params['csrf_token'] ?? null)) {
                        http_response_code(403);
                        echo $this->json(['error' => 'CSRF token validation failed']);
                        return;
                    }
                }
                
                // Execute callback
                $response = call_user_func($callback, $params);
                
                // Handle response
                $this->sendResponse($response);
                return;
            }
        }
        
        // 404 Not Found
        http_response_code(404);
        $this->sendResponse($this->handleNotFound($uri));
    }
    
    private function matchRoute($pattern, $uri, &$params) {
        // Handle wildcard routes
        if ($pattern === '*') {
            $params = [];
            return true;
        }
        
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';
        
        if (preg_match($pattern, $uri, $matches)) {
            $params = array_filter($matches, function($key) {
                return !is_numeric($key);
            }, ARRAY_FILTER_USE_KEY);
            return true;
        }
        return false;
    }
    
    private function runMiddleware($middleware) {
        if (is_string($middleware) && isset($this->middlewareAliases[$middleware])) {
            $middleware = $this->middlewareAliases[$middleware];
        }
        
        if (is_callable($middleware)) {
            return $middleware($this);
        }
        
        return true;
    }
    
    private function sendResponse($response) {
        if ($response instanceof JoguResponse) {
            $response->send();
            return;
        }
        
        if (is_array($response) || is_object($response)) {
            header('Content-Type: application/json');
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
        }
        
        echo (string) $response;
    }
    
    // ========================================================================
    // RESPONSE HELPERS
    // ========================================================================
    
    public function json($data, $status = 200, $options = 0) {
        http_response_code($status);
        header('Content-Type: application/json');
        return json_encode($data, $options | JSON_UNESCAPED_UNICODE);
    }
    
    public function render($view, $data = []) {
        return $this->view($view, $data);
    }
    
    public function view($view, $data = []) {
        $viewPath = $this->getViewPath($view);
        if (!file_exists($viewPath)) {
            throw new Exception("View not found: $view");
        }
        
        extract($data);
        ob_start();
        include $viewPath;
        return ob_get_clean();
    }
    
    public function redirect($url, $status = 302) {
        header("Location: $url", true, $status);
        exit;
    }
    
    public function redirectBack() {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        return $this->redirect($referer);
    }
    
    public function response($content, $status = 200, $headers = []) {
        return new JoguResponse($content, $status, $headers);
    }
    
    public function download($file, $name = null, $headers = []) {
        if (!file_exists($file)) {
            throw new Exception("File not found: $file");
        }
        
        $name = $name ?: basename($file);
        $headers = array_merge([
            'Content-Type' => mime_content_type($file) ?: 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"$name\"",
            'Content-Length' => filesize($file),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public'
        ], $headers);
        
        return $this->response(file_get_contents($file), 200, $headers);
    }
    
    public function stream($callback, $status = 200, $headers = []) {
        return new JoguStreamResponse($callback, $status, $headers);
    }
    
    // ========================================================================
    // VIEW HELPERS
    // ========================================================================
    
    private function getViewPath($view) {
        $viewPath = $this->config['view_path'] . '/' . ltrim($view, '/');
        return $this->basePath . '/' . $viewPath;
    }
    
    public function asset($path) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return $base . '/' . ltrim($this->config['public_path'] . '/' . ltrim($path, '/'), '/');
    }
    
    public function url($path = '') {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return "$scheme://$host$base/" . ltrim($path, '/');
    }
    
    public function csrf() {
        $token = bin2hex(random_bytes(32));
        $this->session('_csrf_token', $token);
        return $token;
    }
    
    public function csrfField() {
        return '<input type="hidden" name="csrf_token" value="' . $this->csrf() . '">';
    }
    
    public function validateCsrf($token = null) {
        $token = $token ?: ($_POST['csrf_token'] ?? null);
        $stored = $this->session('_csrf_token');
        if ($token && $stored && hash_equals($stored, $token)) {
            $this->session('_csrf_token', null);
            return true;
        }
        return false;
    }
    
    public function old($key, $default = null) {
        return $_SESSION['_old'][$key] ?? $default;
    }
    
    public function flashOld() {
        $_SESSION['_old'] = $_POST;
        return $this;
    }
    
    // ========================================================================
    // SECURITY & AUTHENTICATION
    // ========================================================================
    
    public function user() {
        return $this->session('user');
    }
    
    public function login($user) {
        $this->session('user', $user);
        $this->trigger('user.logged_in', $user);
        return $this;
    }
    
    public function logout() {
        $user = $this->session('user');
        $this->session('user', null);
        $this->trigger('user.logged_out', $user);
        return $this;
    }
    
    public function isAuthenticated() {
        return !is_null($this->session('user'));
    }
    
    public function guard() {
        return new JoguGuard($this);
    }
    
    public function hash($value) {
        return password_hash($value, PASSWORD_BCRYPT);
    }
    
    public function verify($value, $hash) {
        return password_verify($value, $hash);
    }
    
    public function encrypt($data) {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->config['secret_key'], 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public function decrypt($data) {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->config['secret_key'], 0, $iv);
    }
    
    // ========================================================================
    // INPUT HANDLING
    // ========================================================================
    
    public function input($key, $default = null) {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        return $this->sanitize($value);
    }
    
    public function allInput() {
        $data = array_merge($_GET, $_POST);
        return $this->sanitize($data);
    }
    
    public function only($keys) {
        $data = $this->allInput();
        return array_intersect_key($data, array_flip((array) $keys));
    }
    
    public function except($keys) {
        $data = $this->allInput();
        return array_diff_key($data, array_flip((array) $keys));
    }
    
    public function has($key) {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }
    
    public function file($key) {
        return $_FILES[$key] ?? null;
    }
    
    public function upload($key, $destination, $maxSize = null, $allowedTypes = null) {
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        $file = $_FILES[$key];
        $maxSize = $maxSize ?: $this->config['upload_max_size'];
        $allowedTypes = $allowedTypes ?: $this->config['allowed_extensions'];
        
        // Check size
        if ($file['size'] > $maxSize) {
            return false;
        }
        
        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            return false;
        }
        
        // Generate unique filename
        $filename = uniqid() . '.' . $ext;
        $destPath = $this->basePath . '/' . ltrim($destination, '/') . '/' . $filename;
        
        if (!is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0777, true);
        }
        
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            return $filename;
        }
        
        return false;
    }
    
    public function sanitize($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize'], $data);
        }
        if (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $data;
    }
    
    public function validate($data, $rules) {
        $validator = new JoguValidator();
        return $validator->validate($data, $rules);
    }
    
    // ========================================================================
    // SERVICE CONTAINER
    // ========================================================================
    
    public function register($name, $callback) {
        $this->services[$name] = $callback;
        return $this;
    }
    
    public function getService($name) {
        if (!isset($this->services[$name])) {
            throw new Exception("Service not found: $name");
        }
        return $this->services[$name]($this);
    }
    
    public function hasService($name) {
        return isset($this->services[$name]);
    }
    
    // ========================================================================
    // EVENT SYSTEM
    // ========================================================================
    
    public function on($event, $callback) {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $callback;
        return $this;
    }
    
    public function trigger($event, $data = null) {
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                $callback($data, $this);
            }
        }
        return $this;
    }
    
    public function off($event, $callback = null) {
        if ($callback === null) {
            unset($this->listeners[$event]);
        } else {
            if (isset($this->listeners[$event])) {
                $this->listeners[$event] = array_filter($this->listeners[$event], function($cb) use ($callback) {
                    return $cb !== $callback;
                });
            }
        }
        return $this;
    }
    
    // ========================================================================
    // HOOK SYSTEM
    // ========================================================================
    
    public function addHook($name, $callback) {
        if (!isset($this->hooks[$name])) {
            $this->hooks[$name] = [];
        }
        $this->hooks[$name][] = $callback;
        return $this;
    }
    
    public function applyHook($name, $value = null) {
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $callback) {
                $value = $callback($value, $this);
            }
        }
        return $value;
    }
    
    // ========================================================================
    // MODULE SYSTEM
    // ========================================================================
    
    public function registerModule($name, $config = []) {
        $this->modules[$name] = $config;
        $this->loadModule($name, $config);
        return $this;
    }
    
    private function loadModule($name, $config) {
        $moduleFile = $this->basePath . "/modules/$name/module.php";
        if (file_exists($moduleFile)) {
            require $moduleFile;
        }
        
        // Load views
        $viewPath = $this->basePath . "/modules/$name/views";
        if (is_dir($viewPath)) {
            $this->config['view_path'] .= ':' . $viewPath;
        }
        
        // Load routes
        $routesFile = $this->basePath . "/modules/$name/routes.php";
        if (file_exists($routesFile)) {
            require $routesFile;
        }
    }
    
    // ========================================================================
    // CORS & SECURITY HEADERS
    // ========================================================================
    
    private function setupSecurityHeaders() {
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        
        if ($this->environment === 'production') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }
    }
    
    private function handleCors() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $this->config['cors_origins'];
        
        if ($allowedOrigins === ['*'] || in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . ($allowedOrigins === ['*'] ? '*' : $origin));
            header("Access-Control-Allow-Methods: " . implode(', ', $this->config['cors_methods']));
            header("Access-Control-Allow-Headers: " . implode(', ', $this->config['cors_headers']));
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Max-Age: 86400");
        }
    }
    
    // ========================================================================
    // ERROR HANDLING
    // ========================================================================
    
    private function setupErrorHandling() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }
    
    public function handleError($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $error = new ErrorException($errstr, 0, $errno, $errfile, $errline);
        $this->handleException($error);
        return true;
    }
    
    public function handleException($exception) {
        $this->logError($exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        
        if ($this->config['debug']) {
            $response = $this->render('errors/debug.php', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'type' => get_class($exception)
            ]);
        } else {
            $response = $this->render('errors/500.php', [
                'message' => 'An error occurred'
            ]);
        }
        
        http_response_code(500);
        echo $response;
    }
    
    public function handleNotFound($uri) {
        if ($this->config['debug']) {
            return $this->view('errors/404.php', ['uri' => $uri]);
        }
        return $this->view('errors/404.php');
    }
    
    public function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    }
    
    public function setErrorHandler($callback) {
        $this->errorHandler = $callback;
        return $this;
    }
    
    public function setNotFoundHandler($callback) {
        $this->notFoundHandler = $callback;
        return $this;
    }
    
    // ========================================================================
    // LOGGING
    // ========================================================================
    
    public function log($message, $level = 'info') {
        $logFile = $this->config['log_file'] ?: $this->basePath . '/logs/app.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $message = is_array($message) || is_object($message) ? print_r($message, true) : $message;
        file_put_contents($logFile, "[$timestamp] [$level] $message\n", FILE_APPEND);
        return $this;
    }
    
    private function logError($message) {
        $this->log($message, 'error');
    }
    
    private function logQuery($sql, $params) {
        if ($this->config['debug']) {
            $this->queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => microtime(true)
            ];
            if (count($this->queryLog) > 100) {
                array_shift($this->queryLog);
            }
        }
    }
    
    public function getQueryLog() {
        return $this->queryLog;
    }
    
    // ========================================================================
    // APPLICATION LIFECYCLE
    // ========================================================================
    
    private function boot() {
        if ($this->booted) return;
        
        $this->trigger('boot', $this);
        
        // Load routes
        $routesFile = $this->basePath . '/routes.php';
        if (file_exists($routesFile)) {
            require $routesFile;
        }
        
        $this->booted = true;
        $this->trigger('booted', $this);
    }
    
    public function isBooted() {
        return $this->booted;
    }
    
    public function getEnvironment() {
        return $this->environment;
    }
    
    public function isDebug() {
        return $this->config['debug'];
    }
    
    // ========================================================================
    // CONFIGURATION HELPERS
    // ========================================================================
    
    public function config($key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    public function setConfig($key, $value) {
        $this->config[$key] = $value;
        return $this;
    }
    
    public function env($key, $default = null) {
        return getenv($key) ?: $default;
    }
    
    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================
    
    private function generateSecretKey() {
        return bin2hex(random_bytes(32));
    }
    
    private function registerDefaultMiddleware() {
        // Rate limiting middleware
        $this->middleware('rate_limit', function($app) {
            if ($app->config('rate_limit')) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $key = "rate_limit_{$ip}";
                $limit = $app->config('rate_limit');
                $window = $app->config('rate_window');
                
                $requests = $app->session($key, []);
                $now = time();
                
                $requests = array_filter($requests, function($time) use ($now, $window) {
                    return $now - $time < $window;
                });
                
                if (count($requests) >= $limit) {
                    http_response_code(429);
                    echo "Too many requests";
                    return false;
                }
                
                $requests[] = $now;
                $app->session($key, $requests);
            }
            return true;
        });
        
        // Compression middleware
        if ($this->config['compression_enabled'] && extension_loaded('zlib')) {
            ob_start('ob_gzhandler', $this->config['compression_level']);
        }
    }
    
    private function registerDefaultServices() {
        $this->register('logger', function($app) {
            return new JoguLogger($app);
        });
        
        $this->register('mailer', function($app) {
            return new JoguMailer($app);
        });
    }
    
    // ========================================================================
    // INTERNATIONALIZATION
    // ========================================================================
    
    public function setLocale($locale) {
        $this->locale = $locale;
        $this->loadTranslations($locale);
        return $this;
    }
    
    public function getLocale() {
        return $this->locale;
    }
    
    public function trans($key, $params = [], $locale = null) {
        $locale = $locale ?: $this->locale;
        $translations = $this->getTranslations($locale);
        
        $text = $translations[$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace(":$k", $v, $text);
            $text = str_replace("{:$k}", $v, $text);
        }
        return $text;
    }
    
    private function loadTranslations($locale) {
        $file = $this->basePath . "/lang/$locale.php";
        if (file_exists($file)) {
            $this->translations[$locale] = require $file;
        }
    }
    
    private function getTranslations($locale) {
        if (!isset($this->translations[$locale])) {
            $this->loadTranslations($locale);
        }
        return $this->translations[$locale] ?? [];
    }
}

// ============================================================================
// JOGU TABLE - ORM Query Builder
// ============================================================================

class JoguTable {
    private $app;
    private $table;
    private $conditions = [];
    private $params = [];
    private $orderBy = null;
    private $limit = null;
    private $offset = null;
    private $select = '*';
    private $joins = [];
    private $groupBy = null;
    private $having = null;
    private $model = null;
    private $cacheTTL = 0;
    private $distinct = false;
    private $unions = [];
    private $with = [];
    
    public function __construct($app, $table) {
        $this->app = $app;
        $this->table = $table;
    }
    
    public function where($column, $operator = null, $value = null) {
        if ($operator === null) {
            $this->conditions[] = [$column, '=', true];
            return $this;
        }
        
        if ($operator instanceof Closure) {
            $subQuery = new self($this->app, $this->table);
            $operator($subQuery);
            $this->conditions[] = [$column, 'IN', $subQuery];
            return $this;
        }
        
        $this->conditions[] = [$column, $operator, $value];
        if ($value !== null && !($value instanceof self)) {
            $this->params[] = $value;
        }
        return $this;
    }
    
    public function orWhere($column, $operator, $value) {
        $this->conditions[] = ['OR', $column, $operator, $value];
        if ($value !== null) {
            $this->params[] = $value;
        }
        return $this;
    }
    
    public function whereIn($column, $values) {
        if ($values instanceof Closure) {
            $subQuery = new self($this->app, $this->table);
            $values($subQuery);
            $this->conditions[] = [$column, 'IN', $subQuery];
            return $this;
        }
        
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->conditions[] = [$column, "IN", "($placeholders)"];
        $this->params = array_merge($this->params, $values);
        return $this;
    }
    
    public function whereNotIn($column, $values) {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->conditions[] = [$column, "NOT IN", "($placeholders)"];
        $this->params = array_merge($this->params, $values);
        return $this;
    }
    
    public function whereBetween($column, $min, $max) {
        $this->conditions[] = [$column, "BETWEEN", "? AND ?"];
        $this->params[] = $min;
        $this->params[] = $max;
        return $this;
    }
    
    public function whereNull($column) {
        $this->conditions[] = [$column, "IS NULL", ""];
        return $this;
    }
    
    public function whereNotNull($column) {
        $this->conditions[] = [$column, "IS NOT NULL", ""];
        return $this;
    }
    
    public function whereLike($column, $pattern) {
        $this->conditions[] = [$column, "LIKE", $pattern];
        $this->params[] = $pattern;
        return $this;
    }
    
    public function whereDate($column, $operator, $value) {
        $this->conditions[] = ["DATE($column)", $operator, $value];
        $this->params[] = $value;
        return $this;
    }
    
    public function orderBy($column, $direction = 'ASC') {
        $this->orderBy = "$column $direction";
        return $this;
    }
    
    public function orderByRaw($sql) {
        $this->orderBy = $sql;
        return $this;
    }
    
    public function limit($limit) {
        $this->limit = $limit;
        return $this;
    }
    
    public function offset($offset) {
        $this->offset = $offset;
        return $this;
    }
    
    public function select($columns) {
        $this->select = is_array($columns) ? implode(', ', $columns) : $columns;
        return $this;
    }
    
    public function selectRaw($sql) {
        $this->select = $sql;
        return $this;
    }
    
    public function distinct() {
        $this->distinct = true;
        return $this;
    }
    
    public function join($table, $on, $type = 'INNER') {
        $this->joins[] = "$type JOIN $table ON $on";
        return $this;
    }
    
    public function leftJoin($table, $on) {
        return $this->join($table, $on, 'LEFT');
    }
    
    public function rightJoin($table, $on) {
        return $this->join($table, $on, 'RIGHT');
    }
    
    public function fullJoin($table, $on) {
        return $this->join($table, $on, 'FULL');
    }
    
    public function groupBy($columns) {
        $this->groupBy = is_array($columns) ? implode(', ', $columns) : $columns;
        return $this;
    }
    
    public function having($condition) {
        $this->having = $condition;
        return $this;
    }
    
    public function union($query) {
        $this->unions[] = ['UNION', $query];
        return $this;
    }
    
    public function unionAll($query) {
        $this->unions[] = ['UNION ALL', $query];
        return $this;
    }
    
    public function with($relations) {
        $this->with = is_array($relations) ? $relations : func_get_args();
        return $this;
    }
    
    public function cache($ttl = 3600) {
        $this->cacheTTL = $ttl;
        return $this;
    }
    
    public function setModel($model) {
        $this->model = $model;
        return $this;
    }
    
    public function buildQuery($forCount = false) {
        $sql = $forCount ? "SELECT COUNT(*) FROM `$this->table`" : "SELECT" . 
               ($this->distinct ? " DISTINCT" : "") . " $this->select FROM `$this->table`";
        
        // Joins
        foreach ($this->joins as $join) {
            $sql .= " $join";
        }
        
        // Where conditions
        if (!empty($this->conditions)) {
            $whereParts = [];
            foreach ($this->conditions as $cond) {
                if ($cond[0] === 'OR') {
                    $whereParts[] = "OR (" . $this->buildCondition($cond[1], $cond[2], $cond[3]) . ")";
                } else {
                    $whereParts[] = $this->buildCondition($cond[0], $cond[1], $cond[2]);
                }
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        if ($forCount) {
            return $sql;
        }
        
        // Group By
        if ($this->groupBy) {
            $sql .= " GROUP BY $this->groupBy";
        }
        
        // Having
        if ($this->having) {
            $sql .= " HAVING $this->having";
        }
        
        // Order By
        if ($this->orderBy) {
            $sql .= " ORDER BY $this->orderBy";
        }
        
        // Limit
        if ($this->limit !== null) {
            $sql .= " LIMIT $this->limit";
            if ($this->offset !== null) {
                $sql .= " OFFSET $this->offset";
            }
        }
        
        return $sql;
    }
    
    private function buildCondition($column, $operator, $value) {
        if ($value instanceof self) {
            return "`$column` $operator (" . $value->buildQuery() . ")";
        }
        if ($operator === 'IN' || $operator === 'NOT IN') {
            return "`$column` $operator $value";
        }
        if ($operator === 'BETWEEN') {
            return "`$column` $operator $value";
        }
        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            return "`$column` $operator";
        }
        return "`$column` $operator ?";
    }
    
    private function getCacheKey() {
        $sql = $this->buildQuery();
        return md5($sql . serialize($this->params));
    }
    
    public function find($id) {
        $this->where('id', '=', $id);
        $result = $this->first();
        return $result;
    }
    
    public function findOne($conditions = [], $params = []) {
        if (!empty($conditions)) {
            foreach ($conditions as $col => $value) {
                $this->where($col, '=', $value);
            }
        }
        $this->params = array_merge($this->params, $params);
        return $this->first();
    }
    
    public function findAll($conditions = [], $params = [], $order = null, $limit = null) {
        if (!empty($conditions)) {
            foreach ($conditions as $col => $value) {
                $this->where($col, '=', $value);
            }
        }
        $this->params = array_merge($this->params, $params);
        if ($order) $this->orderBy($order);
        if ($limit) $this->limit($limit);
        
        return $this->get();
    }
    
    public function get() {
        // Check cache
        if ($this->cacheTTL > 0) {
            $key = $this->getCacheKey();
            $cached = $this->app->getCache($key);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $sql = $this->buildQuery();
        $stmt = $this->app->query($sql, $this->params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Store in cache
        if ($this->cacheTTL > 0) {
            $this->app->setCache($key, $results, $this->cacheTTL);
        }
        
        // Convert to models
        return array_map([$this, 'createModel'], $results);
    }
    
    public function first() {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    public function last() {
        $this->orderBy('id', 'DESC');
        return $this->first();
    }
    
    public function create($data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO `$this->table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        
        $this->app->query($sql, array_values($data));
        $id = $this->app->lastInsertId();
        
        $data['id'] = $id;
        return $this->createModel($data);
    }
    
    public function update($data, $conditions = [], $params = []) {
        if (!empty($conditions)) {
            foreach ($conditions as $col => $value) {
                $this->where($col, '=', $value);
            }
            $this->params = array_merge($this->params, $params);
        }
        
        if (empty($this->conditions)) {
            throw new Exception("Update requires at least one condition");
        }
        
        $sets = [];
        $updateParams = [];
        foreach ($data as $col => $value) {
            $sets[] = "`$col` = ?";
            $updateParams[] = $value;
        }
        
        $sql = "UPDATE `$this->table` SET " . implode(', ', $sets);
        $sql .= $this->buildWhereClause();
        
        $params = array_merge($updateParams, $this->params);
        $stmt = $this->app->query($sql, $params);
        return $stmt->rowCount();
    }
    
    public function delete($conditions = [], $params = []) {
        if (!empty($conditions)) {
            foreach ($conditions as $col => $value) {
                $this->where($col, '=', $value);
            }
            $this->params = array_merge($this->params, $params);
        }
        
        if (empty($this->conditions)) {
            throw new Exception("Delete requires at least one condition");
        }
        
        $sql = "DELETE FROM `$this->table`";
        $sql .= $this->buildWhereClause();
        
        $stmt = $this->app->query($sql, $this->params);
        return $stmt->rowCount();
    }
    
    public function deleteById($id) {
        return $this->where('id', '=', $id)->delete();
    }
    
    public function increment($column, $amount = 1) {
        $this->conditions[] = [$column, '+=', $amount];
        return $this->update([$column => $this->app->raw("`$column` + $amount")]);
    }
    
    public function decrement($column, $amount = 1) {
        $this->conditions[] = [$column, '-=', $amount];
        return $this->update([$column => $this->app->raw("`$column` - $amount")]);
    }
    
    public function count() {
        $sql = $this->buildQuery(true);
        $stmt = $this->app->query($sql, $this->params);
        return (int) $stmt->fetchColumn();
    }
    
    public function exists() {
        return $this->count() > 0;
    }
    
    public function paginate($page = 1, $perPage = 20) {
        $total = $this->count();
        $lastPage = ceil($total / $perPage);
        $page = max(1, min($page, $lastPage));
        
        $this->limit($perPage)->offset(($page - 1) * $perPage);
        $items = $this->get();
        
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total)
        ];
    }
    
    public function getQuery() {
        $sql = $this->buildQuery();
        return [
            'sql' => $sql,
            'params' => $this->params
        ];
    }
    
    public function explain() {
        $sql = "EXPLAIN " . $this->buildQuery();
        return $this->app->fetchAll($sql, $this->params);
    }
    
    public function toSql() {
        $sql = $this->buildQuery();
        $params = $this->params;
        
        return preg_replace_callback('/\?/', function($matches) use (&$params) {
            $value = array_shift($params);
            if (is_string($value)) {
                return "'" . addslashes($value) . "'";
            }
            return $value;
        }, $sql);
    }
    
    private function buildWhereClause() {
        if (empty($this->conditions)) {
            return '';
        }
        
        $whereParts = [];
        foreach ($this->conditions as $cond) {
            if ($cond[0] === 'OR') {
                $whereParts[] = "OR (" . $this->buildCondition($cond[1], $cond[2], $cond[3]) . ")";
            } else {
                $whereParts[] = $this->buildCondition($cond[0], $cond[1], $cond[2]);
            }
        }
        return " WHERE " . implode(' AND ', $whereParts);
    }
    
    private function createModel($data) {
        if ($this->model && class_exists($this->model)) {
            $model = new $this->model($data);
            $model->setApp($this->app);
            $model->setTable($this->table);
            return $model;
        }
        
        $model = new JoguModel($this->app, $this->table, $data);
        return $model;
    }
}

// ============================================================================
// JOGU MODEL - Active Record Pattern
// ============================================================================

class JoguModel {
    protected $app;
    protected $table;
    protected $data = [];
    protected $original = [];
    protected $exists = false;
    protected $relations = [];
    protected $timestamps = ['created_at', 'updated_at'];
    protected $softDelete = false;
    protected $softDeleteField = 'deleted_at';
    protected $hidden = [];
    protected $fillable = [];
    protected $guarded = ['id'];
    protected $casts = [];
    protected $appends = [];
    protected $with = [];
    
    public function __construct($app = null, $table = null, $data = []) {
        $this->app = $app ?: Jogu::instance();
        $this->table = $table ?: $this->getTableName();
        $this->data = $data;
        $this->original = $data;
        if (!empty($data)) {
            $this->exists = true;
        }
    }
    
    protected function getTableName() {
        $className = get_called_class();
        $className = basename(str_replace('\\', '/', $className));
        return strtolower($className) . 's';
    }
    
    public function setApp($app) {
        $this->app = $app;
        return $this;
    }
    
    public function setTable($table) {
        $this->table = $table;
        return $this;
    }
    
    public function __get($key) {
        if (in_array($key, $this->appends)) {
            return $this->getAttribute($key);
        }
        
        if (isset($this->data[$key])) {
            return $this->getAttributeValue($key);
        }
        
        if (method_exists($this, $key)) {
            return $this->getRelation($key);
        }
        
        return null;
    }
    
    public function __set($key, $value) {
        $this->setAttribute($key, $value);
    }
    
    public function __isset($key) {
        return isset($this->data[$key]) || in_array($key, $this->appends);
    }
    
    public function getAttribute($key) {
        $method = 'get' . ucfirst($key) . 'Attribute';
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        return $this->getAttributeValue($key);
    }
    
    protected function getAttributeValue($key) {
        $value = $this->data[$key] ?? null;
        
        if (isset($this->casts[$key])) {
            return $this->castAttribute($key, $value);
        }
        
        return $value;
    }
    
    protected function castAttribute($key, $value) {
        switch ($this->casts[$key]) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'array':
            case 'json':
                return json_decode($value, true);
            case 'object':
                return json_decode($value);
            case 'date':
                return new DateTime($value);
            case 'timestamp':
                return strtotime($value);
        }
        return $value;
    }
    
    public function setAttribute($key, $value) {
        $method = 'set' . ucfirst($key) . 'Attribute';
        if (method_exists($this, $method)) {
            $this->$method($value);
            return $this;
        }
        
        $this->data[$key] = $value;
        return $this;
    }
    
    public function fill($data) {
        if (!empty($this->fillable)) {
            $data = array_intersect_key($data, array_flip($this->fillable));
        } else {
            $data = array_diff_key($data, array_flip($this->guarded));
        }
        
        foreach ($data as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }
    
    public function save() {
        if ($this->exists) {
            return $this->update();
        }
        return $this->insert();
    }
    
    protected function insert() {
        if ($this->timestamps) {
            $this->data['created_at'] = date('Y-m-d H:i:s');
            $this->data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        $result = $this->app->table($this->table)->create($this->data);
        if ($result) {
            $this->data = $result->toArray();
            $this->original = $this->data;
            $this->exists = true;
            $this->app->trigger('model.saved', $this);
            return true;
        }
        return false;
    }
    
    protected function update() {
        if ($this->timestamps) {
            $this->data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        $changed = $this->getChanges();
        if (empty($changed)) {
            return true;
        }
        
        $result = $this->app->table($this->table)
            ->where('id', '=', $this->data['id'] ?? 0)
            ->update($changed);
            
        $this->original = $this->data;
        $this->app->trigger('model.updated', $this);
        return $result > 0;
    }
    
    public function delete() {
        if ($this->softDelete) {
            $this->{$this->softDeleteField} = date('Y-m-d H:i:s');
            return $this->save();
        }
        
        if (!$this->exists || !isset($this->data['id'])) {
            return false;
        }
        
        $result = $this->app->table($this->table)->deleteById($this->data['id']);
        if ($result) {
            $this->exists = false;
            $this->app->trigger('model.deleted', $this);
            return true;
        }
        return false;
    }
    
    public function forceDelete() {
        if (!$this->exists || !isset($this->data['id'])) {
            return false;
        }
        
        $result = $this->app->table($this->table)->deleteById($this->data['id']);
        if ($result) {
            $this->exists = false;
            return true;
        }
        return false;
    }
    
    public function restore() {
        if (!$this->softDelete || !$this->{$this->softDeleteField}) {
            return false;
        }
        
        $this->{$this->softDeleteField} = null;
        return $this->save();
    }
    
    public function getChanges() {
        $changes = [];
        foreach ($this->data as $key => $value) {
            if (!isset($this->original[$key]) || $this->original[$key] !== $value) {
                $changes[$key] = $value;
            }
        }
        return $changes;
    }
    
    public function getOriginal($key = null) {
        if ($key === null) {
            return $this->original;
        }
        return $this->original[$key] ?? null;
    }
    
    public function isDirty($key = null) {
        if ($key === null) {
            return !empty($this->getChanges());
        }
        return isset($this->data[$key]) && $this->data[$key] !== ($this->original[$key] ?? null);
    }
    
    public function getRelation($key) {
        if (!isset($this->relations[$key])) {
            $method = $this->$key();
            if ($method instanceof JoguTable) {
                $this->relations[$key] = $method->get();
            } else {
                $this->relations[$key] = $method;
            }
        }
        return $this->relations[$key];
    }
    
    public function hasMany($related, $foreignKey = null, $localKey = 'id') {
        $foreignKey = $foreignKey ?: strtolower($this->table) . '_id';
        return $this->app->table($related)
            ->where($foreignKey, '=', $this->data[$localKey] ?? 0)
            ->setModel($related);
    }
    
    public function hasOne($related, $foreignKey = null, $localKey = 'id') {
        return $this->hasMany($related, $foreignKey, $localKey)->first();
    }
    
    public function belongsTo($related, $foreignKey = null, $ownerKey = 'id') {
        $foreignKey = $foreignKey ?: strtolower($related) . '_id';
        $value = $this->data[$foreignKey] ?? null;
        return $this->app->find($related, $value);
    }
    
    public function belongsToMany($related, $through = null, $foreignKey = null, $otherKey = null) {
        $through = $through ?: strtolower($this->table) . '_' . strtolower($related);
        $foreignKey = $foreignKey ?: strtolower($this->table) . '_id';
        $otherKey = $otherKey ?: strtolower($related) . '_id';
        
        $ids = $this->app->table($through)
            ->where($foreignKey, '=', $this->data['id'] ?? 0)
            ->select($otherKey)
            ->get();
            
        $ids = array_column($ids, $otherKey);
        
        if (empty($ids)) {
            return [];
        }
        
        return $this->app->table($related)
            ->whereIn('id', $ids)
            ->setModel($related)
            ->get();
    }
    
    public function toArray() {
        $data = [];
        
        // Add attributes
        foreach ($this->data as $key => $value) {
            if (!in_array($key, $this->hidden)) {
                $data[$key] = $this->getAttributeValue($key);
            }
        }
        
        // Add appends
        foreach ($this->appends as $key) {
            $data[$key] = $this->getAttribute($key);
        }
        
        return $data;
    }
    
    public function toJson($options = 0) {
        return json_encode($this->toArray(), $options);
    }
    
    public function __toString() {
        return $this->toJson();
    }
}

// ============================================================================
// JOGU GROUP - Route Grouping
// ============================================================================

class JoguGroup {
    private $app;
    private $prefix;
    private $middleware = [];
    
    public function __construct($app, $prefix) {
        $this->app = $app;
        $this->prefix = rtrim($prefix, '/');
    }
    
    public function use($middleware) {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    public function get($pattern, $callback, $middleware = null) {
        $this->addRoute('GET', $pattern, $callback, $middleware);
        return $this;
    }
    
    public function post($pattern, $callback, $middleware = null) {
        $this->addRoute('POST', $pattern, $callback, $middleware);
        return $this;
    }
    
    public function put($pattern, $callback, $middleware = null) {
        $this->addRoute('PUT', $pattern, $callback, $middleware);
        return $this;
    }
    
    public function delete($pattern, $callback, $middleware = null) {
        $this->addRoute('DELETE', $pattern, $callback, $middleware);
        return $this;
    }
    
    public function patch($pattern, $callback, $middleware = null) {
        $this->addRoute('PATCH', $pattern, $callback, $middleware);
        return $this;
    }
    
    public function any($pattern, $callback, $middleware = null) {
        $this->addRoute('ANY', $pattern, $callback, $middleware);
        return $this;
    }
    
    public function resource($name, $controller) {
        $this->app->resource($this->prefix . '/' . $name, $controller);
        return $this;
    }
    
    public function apiResource($name, $controller) {
        $this->app->apiResource($this->prefix . '/' . $name, $controller);
        return $this;
    }
    
    private function addRoute($method, $pattern, $callback, $middleware = null) {
        $pattern = $this->prefix . '/' . ltrim($pattern, '/');
        $allMiddleware = array_merge($this->middleware, $middleware ? [$middleware] : []);
        
        if (!empty($allMiddleware)) {
            foreach ($allMiddleware as $mw) {
                $this->app->middleware($pattern, $mw);
            }
        }
        
        $this->app->$method($pattern, $callback);
    }
}

// ============================================================================
// JOGU RESPONSE - HTTP Response Handling
// ============================================================================

class JoguResponse {
    private $content;
    private $status;
    private $headers;
    
    public function __construct($content, $status = 200, $headers = []) {
        $this->content = $content;
        $this->status = $status;
        $this->headers = $headers;
    }
    
    public function send() {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }
        echo $this->content;
    }
    
    public function withHeader($key, $value) {
        $this->headers[$key] = $value;
        return $this;
    }
    
    public function withStatus($status) {
        $this->status = $status;
        return $this;
    }
    
    public function withJson($data, $options = 0) {
        $this->headers['Content-Type'] = 'application/json';
        $this->content = json_encode($data, $options);
        return $this;
    }
}

class JoguStreamResponse {
    private $callback;
    private $status;
    private $headers;
    
    public function __construct($callback, $status = 200, $headers = []) {
        $this->callback = $callback;
        $this->status = $status;
        $this->headers = $headers;
    }
    
    public function send() {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }
        call_user_func($this->callback);
    }
}

// ============================================================================
// JOGU GUARD - Authentication
// ============================================================================

class JoguGuard {
    private $app;
    private $user = null;
    private $provider = null;
    
    public function __construct($app) {
        $this->app = $app;
        $this->user = $app->user();
    }
    
    public function attempt($credentials) {
        $user = $this->app->table('users')
            ->where('email', '=', $credentials['email'])
            ->first();
        
        if ($user && $this->app->verify($credentials['password'], $user['password'])) {
            $this->app->login($user);
            return true;
        }
        return false;
    }
    
    public function user() {
        return $this->user;
    }
    
    public function check() {
        return !is_null($this->user);
    }
    
    public function guest() {
        return is_null($this->user);
    }
    
    public function id() {
        return $this->user['id'] ?? null;
    }
    
    public function logout() {
        $this->app->logout();
        $this->user = null;
    }
    
    public function viaRemember() {
        return false; // Can be implemented with remember tokens
    }
}

// ============================================================================
// JOGU VALIDATOR - Input Validation
// ============================================================================

class JoguValidator {
    private $errors = [];
    private $rules = [];
    private $messages = [];
    
    public function validate($data, $rules, $messages = []) {
        $this->errors = [];
        $this->rules = $rules;
        $this->messages = $messages;
        
        foreach ($rules as $field => $rule) {
            $rulesList = explode('|', $rule);
            foreach ($rulesList as $rule) {
                $this->validateRule($field, $data[$field] ?? null, $rule);
            }
        }
        
        return empty($this->errors);
    }
    
    public function fails() {
        return !empty($this->errors);
    }
    
    public function errors() {
        return $this->errors;
    }
    
    private function validateRule($field, $value, $rule) {
        $method = 'validate' . ucfirst($rule);
        if (method_exists($this, $method)) {
            if (!$this->$method($value)) {
                $this->addError($field, $rule);
            }
        }
    }
    
    private function validateRequired($value) {
        return !empty($value) || $value === '0';
    }
    
    private function validateEmail($value) {
        return filter_var($value, FILTER_VALIDATE_EMAIL);
    }
    
    private function validateNumeric($value) {
        return is_numeric($value);
    }
    
    private function validateInteger($value) {
        return filter_var($value, FILTER_VALIDATE_INT);
    }
    
    private function validateBoolean($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function validateArray($value) {
        return is_array($value);
    }
    
    private function validateString($value) {
        return is_string($value);
    }
    
    private function validateUrl($value) {
        return filter_var($value, FILTER_VALIDATE_URL);
    }
    
    private function validateIp($value) {
        return filter_var($value, FILTER_VALIDATE_IP);
    }
    
    private function validateDate($value) {
        return strtotime($value) !== false;
    }
    
    private function validateAlpha($value) {
        return ctype_alpha($value);
    }
    
    private function validateAlphaNum($value) {
        return ctype_alnum($value);
    }
    
    private function addError($field, $rule) {
        $message = $this->messages[$field . '.' . $rule] ?? 
                  $this->messages[$field] ?? 
                  "The $field field failed validation for $rule";
        $this->errors[$field][] = $message;
    }
}

// ============================================================================
// JOGU LOGGER - Logging Service
// ============================================================================

class JoguLogger {
    private $app;
    private $logFile;
    
    public function __construct($app) {
        $this->app = $app;
        $this->logFile = $app->config('log_file') ?: $app->basePath . '/logs/app.log';
    }
    
    public function emergency($message) { $this->log($message, 'EMERGENCY'); }
    public function alert($message) { $this->log($message, 'ALERT'); }
    public function critical($message) { $this->log($message, 'CRITICAL'); }
    public function error($message) { $this->log($message, 'ERROR'); }
    public function warning($message) { $this->log($message, 'WARNING'); }
    public function notice($message) { $this->log($message, 'NOTICE'); }
    public function info($message) { $this->log($message, 'INFO'); }
    public function debug($message) { $this->log($message, 'DEBUG'); }
    
    private function log($message, $level = 'INFO') {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $message = is_array($message) || is_object($message) ? print_r($message, true) : $message;
        file_put_contents($this->logFile, "[$timestamp] [$level] $message\n", FILE_APPEND);
    }
}

// ============================================================================
// JOGU MAILER - Email Service (Simple)
// ============================================================================

class JoguMailer {
    private $app;
    private $config;
    
    public function __construct($app) {
        $this->app = $app;
        $this->config = $app->config('mail', []);
    }
    
    public function send($to, $subject, $body, $from = null) {
        $headers = [];
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        
        if ($from) {
            $headers[] = "From: $from";
        } elseif (isset($this->config['from'])) {
            $headers[] = "From: {$this->config['from']}";
        }
        
        if (isset($this->config['reply_to'])) {
            $headers[] = "Reply-To: {$this->config['reply_to']}";
        }
        
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    public function sendTemplate($to, $subject, $template, $data = [], $from = null) {
        $body = $this->app->render($template, $data);
        return $this->send($to, $subject, $body, $from);
    }
}

// ============================================================================
// AUTOLOADER & CLASS REGISTRATION
// ============================================================================

spl_autoload_register(function($class) {
    $app = Jogu::instance();
    $basePath = $app->basePath;
    
    // Check controllers
    $controllerFile = $basePath . '/controllers/' . $class . '.php';
    if (file_exists($controllerFile)) {
        require $controllerFile;
        return;
    }
    
    // Check models
    $modelFile = $basePath . '/models/' . $class . '.php';
    if (file_exists($modelFile)) {
        require $modelFile;
        return;
    }
    
    // Check app directory
    $appFile = $basePath . '/app/' . $class . '.php';
    if (file_exists($appFile)) {
        require $appFile;
        return;
    }
});

// ============================================================================
// BASE CONTROLLER
// ============================================================================

class JoguController {
    protected $app;
    protected $guard;
    
    public function __construct() {
        $this->app = Jogu::instance();
        $this->guard = new JoguGuard($this->app);
    }
    
    protected function render($view, $data = []) {
        return $this->app->render($view, $data);
    }
    
    protected function json($data, $status = 200) {
        return $this->app->json($data, $status);
    }
    
    protected function redirect($url, $status = 302) {
        return $this->app->redirect($url, $status);
    }
    
    protected function redirectBack() {
        return $this->app->redirectBack();
    }
    
    protected function input($key, $default = null) {
        return $this->app->input($key, $default);
    }
    
    protected function allInput() {
        return $this->app->allInput();
    }
    
    protected function validate($data, $rules) {
        return $this->app->validate($data, $rules);
    }
    
    protected function authorize($ability) {
        // Simple authorization - can be extended
        if (!$this->guard->check()) {
            $this->app->redirect('/login');
        }
        return true;
    }
}

// ============================================================================
// DOCUMENTATION & COMMENTS
// ============================================================================

/**
 * JOGU MACROFRAMEWORK - Quick Start Guide
 * 
 * 1. BASIC SETUP:
 * <?php
 * require 'jogu.php';
 * $app = new Jogu();
 * $app->get('/', function() { return "Hello World"; });
 * $app->run();
 * 
 * 2. DATABASE CONFIGURATION:
 * $app = new Jogu([
 *     'database' => [
 *         'driver' => 'mysql',
 *         'host' => 'localhost',
 *         'database' => 'myapp',
 *         'username' => 'root',
 *         'password' => ''
 *     ]
 * ]);
 * 
 * 3. ORM USAGE:
 * $users = $app->table('users')->where('active', '=', 1)->get();
 * $user = $app->find('users', 1);
 * $app->create('users', ['name' => 'John']);
 * 
 * 4. MODELS:
 * class User extends JoguModel {
 *     public function posts() {
 *         return $this->hasMany('Post');
 *     }
 * }
 * 
 * 5. ROUTES WITH CONTROLLERS:
 * $app->get('/users', 'UserController@index');
 * $app->resource('users', 'UserController');
 * 
 * 6. MIDDLEWARE:
 * $app->use(function($app) {
 *     // Process request
 * });
 * 
 * 7. AUTHENTICATION:
 * $app->login($user);
 * $app->isAuthenticated();
 * $app->logout();
 * 
 * 8. RESPONSES:
 * return $app->json($data);
 * return $app->render('view.php', ['data' => $data]);
 * return $app->redirect('/url');
 * 
 * 9. EVENTS:
 * $app->on('user.created', function($user) {
 *     // Send welcome email
 * });
 * $app->trigger('user.created', $user);
 * 
 * 10. CACHE:
 * $data = $app->cache('key', function() {
 *     return expensiveOperation();
 * }, 3600);
 * 
 * For more documentation, visit: https://jogu.framework/docs
 */

// ============================================================================
// BOOTSTRAP
// ============================================================================

// Return the app instance if not running directly
if (__FILE__ !== realpath($_SERVER['SCRIPT_FILENAME'])) {
    return new Jogu();
}

// ============================================================================
// END OF JOGU.PHP
// ============================================================================
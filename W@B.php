<?php
// WinningBrew.php - Complete Microframework with ORM aka The First PHP "Macroframework."
// Single file, zero configuration, just upload and code!

class WeB {
    // Framework core
    private $routes = [];
    private $middleware = [];
    private $basePath = '';
    private $config = [];
    private static $instance = null;
    
    // ORM properties
    private $db = null;
    private $tablePrefix = '';
    private $queryLog = [];
    private $transactionLevel = 0;
    
    public function __construct($config = []) {
        self::$instance = $this;
        $this->config = array_merge([
            'debug' => false,
            'timezone' => 'UTC',
            'table_prefix' => '',
            'db' => null
        ], $config);
        
        date_default_timezone_set($this->config['timezone']);
        $this->basePath = dirname($_SERVER['SCRIPT_FILENAME']);
        
        if (isset($this->config['db'])) {
            $this->setupDatabase($this->config['db']);
        }
    }
    
    public static function instance() {
        return self::$instance;
    }
    
    // ============ DATABASE SETUP ============
    
    public function setupDatabase($config) {
        if (is_string($config)) {
            // DSN string
            $this->db = new PDO($config);
        } elseif (is_array($config)) {
            $dsn = "{$config['driver']}:host={$config['host']};dbname={$config['database']}";
            if (isset($config['port'])) {
                $dsn .= ";port={$config['port']}";
            }
            if (isset($config['charset'])) {
                $dsn .= ";charset={$config['charset']}";
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
        
        return $this;
    }
    
    public function setTablePrefix($prefix) {
        $this->tablePrefix = $prefix;
        return $this;
    }
    
    // ============ ORM CORE ============
    
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
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $this->logQuery($sql, $params);
        return $stmt;
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
    
    private function logQuery($sql, $params) {
        if ($this->config['debug']) {
            $this->queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => microtime(true)
            ];
        }
    }
    
    public function getQueryLog() {
        return $this->queryLog;
    }
    
    public function getDb() {
        return $this->db;
    }
    
    // ============ RELATIONSHIPS ============
    
    public function hasMany($model, $related, $foreignKey = null) {
        $foreignKey = $foreignKey ?: strtolower($model) . '_id';
        return $this->table($related)->where($foreignKey, '=', $this->getPk());
    }
    
    public function belongsTo($model, $related, $foreignKey = null) {
        $foreignKey = $foreignKey ?: strtolower($related) . '_id';
        return $this->find($related, $this->$foreignKey);
    }
    
    public function hasOne($model, $related, $foreignKey = null) {
        return $this->belongsTo($model, $related, $foreignKey);
    }
    
    public function belongsToMany($model, $related, $through = null, $foreignKey = null, $otherKey = null) {
        $through = $through ?: strtolower($model) . '_' . strtolower($related);
        $foreignKey = $foreignKey ?: strtolower($model) . '_id';
        $otherKey = $otherKey ?: strtolower($related) . '_id';
        
        $ids = $this->table($through)
            ->where($foreignKey, '=', $this->getPk())
            ->select($otherKey)
            ->fetchAll(PDO::FETCH_COLUMN);
            
        if (empty($ids)) {
            return [];
        }
        
        return $this->table($related)
            ->whereIn('id', $ids)
            ->findAll();
    }
    
    // ============ ROUTING ============
    
    public function get($pattern, $callback) {
        $this->routes['GET'][$pattern] = $callback;
        return $this;
    }
    
    public function post($pattern, $callback) {
        $this->routes['POST'][$pattern] = $callback;
        return $this;
    }
    
    public function put($pattern, $callback) {
        $this->routes['PUT'][$pattern] = $callback;
        return $this;
    }
    
    public function delete($pattern, $callback) {
        $this->routes['DELETE'][$pattern] = $callback;
        return $this;
    }
    
    public function any($pattern, $callback) {
        $this->routes['ANY'][$pattern] = $callback;
        return $this;
    }
    
    public function resource($name, $controller) {
        $this->get("/$name", [$controller, 'index']);
        $this->get("/$name/create", [$controller, 'create']);
        $this->post("/$name", [$controller, 'store']);
        $this->get("/$name/{id}", [$controller, 'show']);
        $this->get("/$name/{id}/edit", [$controller, 'edit']);
        $this->put("/$name/{id}", [$controller, 'update']);
        $this->delete("/$name/{id}", [$controller, 'destroy']);
        return $this;
    }
    
    public function group($prefix, $callback) {
        $group = new JoguGroup($this, $prefix);
        $callback($group);
        return $this;
    }
    
    public function use($middleware) {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    public function run() {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';
        
        // Remove base path if any
        $baseDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($baseDir != '/' && strpos($uri, $baseDir) === 0) {
            $uri = substr($uri, strlen($baseDir));
            $uri = $uri ?: '/';
        }
        
        // Run middleware
        foreach ($this->middleware as $mw) {
            $mw($this);
        }
        
        $routes = $this->routes[$method] ?? [];
        $routes = array_merge($routes, $this->routes['ANY'] ?? []);
        
        foreach ($routes as $pattern => $callback) {
            if ($this->matchRoute($pattern, $uri, $params)) {
                $params['app'] = $this;
                $params = array_merge($params, $_GET, $_POST);
                
                if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
                    $input = json_decode(file_get_contents('php://input'), true);
                    if ($input) {
                        $params = array_merge($params, $input);
                    }
                }
                
                $response = call_user_func($callback, $params);
                
                if (is_array($response) || is_object($response)) {
                    header('Content-Type: application/json');
                    echo json_encode($response);
                } else {
                    echo $response;
                }
                return;
            }
        }
        
        http_response_code(404);
        echo $this->render('404.php', ['message' => 'Page not found']);
    }
    
    private function matchRoute($pattern, $uri, &$params) {
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
    
    // ============ RENDERING ============
    
    public function render($view, $data = []) {
        extract($data);
        ob_start();
        $viewPath = $this->basePath . '/' . ltrim($view, '/');
        if (file_exists($viewPath)) {
            include $viewPath;
        } else {
            echo "View not found: $view";
        }
        return ob_get_clean();
    }
    
    public function view($view, $data = []) {
        return $this->render($view, $data);
    }
    
    public function json($data) {
        header('Content-Type: application/json');
        return json_encode($data);
    }
    
    public function redirect($url, $status = 302) {
        header("Location: $url", true, $status);
        exit;
    }
    
    // ============ HELPERS ============
    
    public function session($key = null, $value = null) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if ($key === null) {
            return $_SESSION ?? [];
        }
        
        if ($value === null) {
            return $_SESSION[$key] ?? null;
        }
        
        $_SESSION[$key] = $value;
        return $this;
    }
    
    public function user() {
        return $this->session('user');
    }
    
    public function login($user) {
        $this->session('user', $user);
        return $this;
    }
    
    public function logout() {
        $this->session('user', null);
        return $this;
    }
    
    public function isAuthenticated() {
        return !is_null($this->session('user'));
    }
    
    public function csrf() {
        $token = bin2hex(random_bytes(32));
        $this->session('csrf_token', $token);
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
    
    public function validateCsrf($token = null) {
        $token = $token ?: ($_POST['csrf_token'] ?? null);
        return $token && $token === $this->session('csrf_token');
    }
    
    public function input($key, $default = null) {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }
    
    public function allInput() {
        return array_merge($_GET, $_POST);
    }
    
    public function file($key) {
        return $_FILES[$key] ?? null;
    }
    
    public function upload($key, $destination) {
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        $file = $_FILES[$key];
        $destPath = $this->basePath . '/' . ltrim($destination, '/');
        
        if (!is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0777, true);
        }
        
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            return $destPath;
        }
        
        return false;
    }
    
    public function config($key, $default = null) {
        return $this->config[$key] ?? $default;
    }
}

// ============ ORM TABLE CLASS ============

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
    
    public function __construct($app, $table) {
        $this->app = $app;
        $this->table = $table;
    }
    
    public function where($column, $operator, $value) {
        $this->conditions[] = [$column, $operator, $value];
        $this->params[] = $value;
        return $this;
    }
    
    public function whereIn($column, $values) {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->conditions[] = [$column, "IN", "($placeholders)"];
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
    
    public function orderBy($column, $direction = 'ASC') {
        $this->orderBy = "$column $direction";
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
        $this->select = $columns;
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
    
    public function groupBy($columns) {
        $this->groupBy = $columns;
        return $this;
    }
    
    public function having($condition) {
        $this->having = $condition;
        return $this;
    }
    
    public function setModel($model) {
        $this->model = $model;
        return $this;
    }
    
    public function buildQuery($forCount = false) {
        $sql = $forCount ? "SELECT COUNT(*) FROM `$this->table`" : "SELECT $this->select FROM `$this->table`";
        
        // Joins
        foreach ($this->joins as $join) {
            $sql .= " $join";
        }
        
        // Where conditions
        if (!empty($this->conditions)) {
            $whereParts = [];
            foreach ($this->conditions as $cond) {
                list($col, $op, $val) = $cond;
                if ($op == 'IN' || $op == 'BETWEEN') {
                    $whereParts[] = "`$col` $op $val";
                } else {
                    $whereParts[] = "`$col` $op ?";
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
    
    public function find($id) {
        $this->where('id', '=', $id);
        $sql = $this->buildQuery();
        $stmt = $this->app->query($sql, $this->params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $this->createModel($result) : null;
    }
    
    public function findOne($conditions = [], $params = []) {
        if (!empty($conditions)) {
            foreach ($conditions as $col => $value) {
                $this->where($col, '=', $value);
            }
        }
        $this->params = array_merge($this->params, $params);
        $this->limit(1);
        $sql = $this->buildQuery();
        $stmt = $this->app->query($sql, $this->params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $this->createModel($result) : null;
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
        
        $sql = $this->buildQuery();
        $stmt = $this->app->query($sql, $this->params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([$this, 'createModel'], $results);
    }
    
    public function create($data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO `$this->table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->app->query($sql, array_values($data));
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
    
    public function count() {
        $sql = $this->buildQuery(true);
        $stmt = $this->app->query($sql, $this->params);
        return $stmt->fetchColumn();
    }
    
    public function exists() {
        return $this->count() > 0;
    }
    
    public function first() {
        return $this->limit(1)->findOne();
    }
    
    public function last() {
        return $this->orderBy('id', 'DESC')->limit(1)->findOne();
    }
    
    public function paginate($page = 1, $perPage = 20) {
        $total = $this->count();
        $lastPage = ceil($total / $perPage);
        $page = max(1, min($page, $lastPage));
        
        $this->limit($perPage)->offset(($page - 1) * $perPage);
        $items = $this->findAll();
        
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage
        ];
    }
    
    private function buildWhereClause() {
        if (empty($this->conditions)) {
            return '';
        }
        
        $whereParts = [];
        foreach ($this->conditions as $cond) {
            list($col, $op, $val) = $cond;
            if ($op == 'IN' || $op == 'BETWEEN') {
                $whereParts[] = "`$col` $op $val";
            } else {
                $whereParts[] = "`$col` $op ?";
            }
        }
        return " WHERE " . implode(' AND ', $whereParts);
    }
    
    private function createModel($data) {
        if ($this->model && class_exists($this->model)) {
            $model = new $this->model($data);
            $model->setApp($this->app);
            return $model;
        }
        
        $model = new JoguModel($this->app, $this->table, $data);
        return $model;
    }
}

// ============ MODEL CLASS ============

class JoguModel {
    protected $app;
    protected $table;
    protected $data = [];
    protected $original = [];
    protected $exists = false;
    protected $relations = [];
    
    public function __construct($data = []) {
        $this->data = $data;
        $this->original = $data;
        if (!empty($data)) {
            $this->exists = true;
        }
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
        return $this->data[$key] ?? null;
    }
    
    public function __set($key, $value) {
        $this->data[$key] = $value;
    }
    
    public function __isset($key) {
        return isset($this->data[$key]);
    }
    
    public function toArray() {
        return $this->data;
    }
    
    public function toJson() {
        return json_encode($this->data);
    }
    
    public function save() {
        if ($this->exists) {
            // Update
            $changed = [];
            foreach ($this->data as $key => $value) {
                if (!isset($this->original[$key]) || $this->original[$key] !== $value) {
                    $changed[$key] = $value;
                }
            }
            
            if (empty($changed)) {
                return true;
            }
            
            $this->app->table($this->table)
                ->where('id', '=', $this->data['id'] ?? 0)
                ->update($changed);
                
            $this->original = $this->data;
            return true;
        } else {
            // Insert
            $result = $this->app->table($this->table)->create($this->data);
            if ($result) {
                $this->data = $result->toArray();
                $this->original = $this->data;
                $this->exists = true;
                return true;
            }
            return false;
        }
    }
    
    public function delete() {
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
    
    public function hasMany($related, $foreignKey = null) {
        $foreignKey = $foreignKey ?: strtolower($this->table) . '_id';
        return $this->app->table($related)
            ->where($foreignKey, '=', $this->data['id'] ?? 0)
            ->setModel($related);
    }
    
    public function belongsTo($related, $foreignKey = null) {
        $foreignKey = $foreignKey ?: strtolower($related) . '_id';
        return $this->app->find($related, $this->data[$foreignKey] ?? null);
    }
    
    public function hasOne($related, $foreignKey = null) {
        return $this->belongsTo($related, $foreignKey);
    }
    
    public function belongsToMany($related, $through = null, $foreignKey = null, $otherKey = null) {
        $through = $through ?: strtolower($this->table) . '_' . strtolower($related);
        $foreignKey = $foreignKey ?: strtolower($this->table) . '_id';
        $otherKey = $otherKey ?: strtolower($related) . '_id';
        
        $ids = $this->app->table($through)
            ->where($foreignKey, '=', $this->data['id'] ?? 0)
            ->select($otherKey)
            ->findAll();
            
        $ids = array_column($ids, $otherKey);
        
        if (empty($ids)) {
            return [];
        }
        
        return $this->app->table($related)
            ->whereIn('id', $ids)
            ->setModel($related)
            ->findAll();
    }
}

// ============ ROUTE GROUP CLASS ============

class JoguGroup {
    private $app;
    private $prefix;
    
    public function __construct($app, $prefix) {
        $this->app = $app;
        $this->prefix = rtrim($prefix, '/');
    }
    
    public function get($pattern, $callback) {
        $this->app->get($this->prefix . '/' . ltrim($pattern, '/'), $callback);
        return $this;
    }
    
    public function post($pattern, $callback) {
        $this->app->post($this->prefix . '/' . ltrim($pattern, '/'), $callback);
        return $this;
    }
    
    public function put($pattern, $callback) {
        $this->app->put($this->prefix . '/' . ltrim($pattern, '/'), $callback);
        return $this;
    }
    
    public function delete($pattern, $callback) {
        $this->app->delete($this->prefix . '/' . ltrim($pattern, '/'), $callback);
        return $this;
    }
    
    public function any($pattern, $callback) {
        $this->app->any($this->prefix . '/' . ltrim($pattern, '/'), $callback);
        return $this;
    }
    
    public function resource($name, $controller) {
        $this->app->resource($this->prefix . '/' . $name, $controller);
        return $this;
    }
}

// ============ BASIC CONTROLLER ============

class JoguController {
    protected $app;
    
    public function __construct($app = null) {
        $this->app = $app ?: Jogu::instance();
    }
    
    protected function render($view, $data = []) {
        return $this->app->render($view, $data);
    }
    
    protected function json($data) {
        return $this->app->json($data);
    }
    
    protected function redirect($url) {
        return $this->app->redirect($url);
    }
    
    protected function input($key, $default = null) {
        return $this->app->input($key, $default);
    }
    
    protected function allInput() {
        return $this->app->allInput();
    }
}

// ============ EXAMPLE USAGE ============
/*
// Database configuration - MySQL
$app = new Jogu([
    'debug' => true,
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'database' => 'test',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4'
    ]
]);

// Or SQLite
$app->setupDatabase('sqlite:database.sqlite');

// Define a model
class User extends JoguModel {
    public function getFullName() {
        return $this->first_name . ' ' . $this->last_name;
    }
    
    public function posts() {
        return $this->hasMany('Post');
    }
}

// Routes
$app->get('/', function($app) {
    $users = $app->table('users')->findAll();
    return $app->render('home.php', ['users' => $users]);
});

$app->get('/user/{id}', function($params) {
    $user = Jogu::instance()->find('users', $params['id']);
    return $params['app']->json($user);
});

// Resource routes
$app->resource('users', 'UserController');

// Group routes
$app->group('/admin', function($group) {
    $group->get('/', function() {
        return "Admin dashboard";
    });
    $group->resource('posts', 'AdminPostController');
});

// ORM examples
$user = $app->create('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

$users = $app->table('users')
    ->where('age', '>', 18)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->findAll();

// Model with relationships
$user = new User();
$user->name = 'Jane Doe';
$user->email = 'jane@example.com';
$user->save();

$posts = $user->posts()->findAll();

// Run the app
$app->run();
*/
?>
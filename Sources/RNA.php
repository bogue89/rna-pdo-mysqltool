<?php
class RNA
{
	private static $__instances = array();

	public static function getConnection($config = null)
	{
		if (!is_array($config)) {
			trigger_error('No proper configuration.');
		}
		$db = $config['driver'].':'.$config['user'].'@'.$config['host'].':'.$config['database'];

		if (isset(self::$__instances[$db])) {
			return self::$__instances[$db];
		}

		$class = get_called_class().ucfirst($config['driver']);

		return self::$__instances[$db] = new $class($config);
	}
}
class RNAMysql {
	
	public $connected = false;
	public $debug = false;

	private $pdo;
	private $pdo_statement;
	
	private $options = array(
		'fields' => array('*'),
		'values' => array(),
		'as' => null,
		'table' => null,
		'conditions' => array(),
		'group' => null,
		'order' => null,
		'limit' => null
	);
	private $schema_structure = array(
		'field' => 'name',
		'type' => 'type',
		'null' => 'null',
		'key' => 'key'
	);
	
	private $query_types = array('select', 'insert', 'update', 'delete');
	private $operators = array('=','<>','<=>','>=','<=','>','<','LIKE','NOT');
	private $log = array(
		'query' => null,
		'data' => null,
		'took' => null,
	);
	function __construct($config) {
		extract($config);

		if (!isset($encoding)) {
			$encoding = 'utf8mb4';
		}
		if (!isset($port)) {
			$port = "8889";
		}
		$this->connect("mysql:host=$host;port=$port;dbname=$database;charset=$encoding", $user, $password);
	}
	public function connect($pdo_config, $user, $password) {
		try
		{
			$this->pdo = new PDO($pdo_config, $user, $password);
		    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch (PDOException $e)
		{
			trigger_error($e->getMessage());
		}
		$this->connected = true;
	}
	public function query($query, $data=array()) {

		$this->pdo_statement = $this->pdo->prepare($query);
		
		$this->executeStatement($this->pdo_statement, $data);
	
		return $this->fetch($this->pdo_statement);
	}
	public function find($table, $options = array()) {
		$options = array_merge($this->options, $options);
		$options['table'] = $table;
		
		$this->log['query'] = $query = $this->renderQuery("select", $options, $data);
		$this->log['data'] = $data;

		$this->pdo_statement = $this->pdo->prepare($query);
		if(!empty($data))
		foreach($data as $name => $value) {
			$this->pdo_statement->bindValue($name, $value);	
		}
		$this->executeStatement($this->pdo_statement);
	
		return $this->fetch($this->pdo_statement);
	}
	public function insert($table, $data) {
		$options = array();
		$options['table'] = $table;
		$options['fields'] = array_keys($data);
		$options['values'] = $data;
		$data = array();
		$query = $this->renderQuery("insert", $options, $data);
		$this->pdo_statement = $this->pdo->prepare($query);
		if(!empty($data))
		foreach($data as $name => $value) {
			$this->pdo_statement->bindValue($name, $value);	
		}
		return $this->executeStatement($this->pdo_statement);
	}
	public function update($table, $data, $conditions=array()) {
		$options = array();
		$options['conditions'] = $conditions;
		$options['table'] = $table;
		$options['values'] = $data;
		$data = array();
		$query = $this->renderQuery("update", $options, $data);
		$this->pdo_statement = $this->pdo->prepare($query);
		if(!empty($data))
		foreach($data as $name => $value) {
			$this->pdo_statement->bindValue($name, $value);	
		}
		return $this->executeStatement($this->pdo_statement);
	}
	public function delete($table, $conditions) {
		$options = array();
		$options['conditions'] = $conditions;
		$options['table'] = $table;
		$data = array();
		$query = $this->renderQuery("delete", $options, $data);
		$this->pdo_statement = $this->pdo->prepare($query);
		if(!empty($data))
		foreach($data as $name => $value) {
			$this->pdo_statement->bindValue($name, $value);	
		}
		return $this->executeStatement($this->pdo_statement);
	}
	public function fetch($statement) {
		$results = array();
		if($statement->columnCount())
		foreach($statement->fetchAll(PDO::FETCH_NUM) as $key => $result) 
		{
			$results[$key] = array();
			foreach($result as $col => $value) 
			{
				$meta = $statement->getColumnMeta($col);
				$results[$key][$meta['table']][$meta['name']] = $value;
			}
		}
		return $results;
	}
	public function fields($table) {
		if($table) {
			$statement = $this->pdo->prepare("DESCRIBE $table");
			$this->executeStatement($statement);
			return $statement->fetchAll(PDO::FETCH_COLUMN);	
		}
		return null;
	}
	public function getPrimary($table) {
		if($table) {
			$schema = $this->schema($table);
			$primary = null;
			foreach($schema as $column) {
				if($column['key'] == "PRI" || $column['key'] == "PRIMARY") {
					$primary = $column['name'];
				}
			}
			return $primary;
		}
		return null;
	}
	public function lastInsertId() {
		return $this->pdo->lastInsertId();
	}
	public function schema($table) {
		if($table) {
			$statement = $this->pdo->prepare("DESCRIBE $table");
			$this->executeStatement($statement);
			$schema = array();
			foreach($statement->fetchAll(PDO::FETCH_ASSOC)  as $key => $desc) {
				foreach($desc as $name => $value) {
					if(isset($this->schema_structure[strtolower($name)])) {
						$schema[$key][$this->schema_structure[strtolower($name)]] = $value;
					}
				}
			}
			return $schema;
		}
		return null;
	}
	private function executeStatement($statement, $data=null) {
	
		$this->log['took'] = -microtime();
		$return = $statement->execute($data);
		$this->log['took'] += microtime();
		
		return $return;
	}
	private function renderQuery($type, $options, &$data) {
		$type = strtolower($type);
		
		if($type=="select") {
			$fields = $this->renderFields($options['fields'], $data);
			$as = $this->renderAS($options['as']);
			$conditions = $this->renderConditions($options['conditions'], $data);
			$limit = $this->renderLimitOffset($options['limit']);
			$group = $this->renderGroup($options['group']);
			$order = $this->renderOrder($options['order']);
			$table = trim($options['table']);
			return "SELECT $fields FROM $table$as WHERE $conditions$group$order$limit";
		}
		if($type=="insert") {
			$fields = $this->renderFields($options['fields']);
			$values = $this->renderValues($options['values'], $data);
			$table = trim($options['table']);
			return "INSERT INTO $table ($fields) VALUES ($values)";
		}
		if($type=="update") {
			$values = $this->renderSetValues($options['values'], $data);
			$conditions = $this->renderConditions($options['conditions'], $data);
			$table = trim($options['table']);
			return "UPDATE $table SET $values WHERE $conditions";
		}
		if($type=="delete") {
			$conditions = $this->renderConditions($options['conditions'], $data);
			$table = trim($options['table']);
			return "DELETE FROM $table WHERE $conditions";
		}
	}
	private function renderFields($fields=array()) {
		$sql_format = "";
		$i = 0;
		foreach($fields as $field) {
			$field = trim($field);
			$sql_format.= $i==0 ? "$field":",$field";
			$i++;
		}
		return $sql_format;
	}
	private function renderValues($values=array(), &$data) {
		$sql_format = ":";
		$sql_format .= implode(",:", array_keys($values));
		foreach($values as $field => $value) {
			$data[":$field"] = $value;
		}
		return $sql_format;
	}
	private function renderAs($as=null) {
		return $as ? " AS $as":"";
	}
	private function renderConditions($conditions=array(), &$data) {
		$sql_format = "(";
		$i = 0;
		if(empty($conditions)) {
			$sql_format .="1 = 1";
		}
		foreach($conditions as $field => $value) {
			$clean_field = trim(str_replace($this->operators, "", $field));
			if($value===null) {
				if(preg_match("/".implode("|", $this->operators)."/i", $field, $matches)) {
					$sql_format.= $i==0 ? "$clean_field IS NOT NULL":" AND $clean_field IS NOT NULL";
				} else {
					$sql_format.= $i==0 ? "$clean_field IS NULL":" AND $clean_field IS NULL";
				}
			} else {
				if(preg_match("/".implode("|", $this->operators)."/i", $field, $matches)) {
					$sql_format.= $i==0 ? "$field :$clean_field":" AND $field :$clean_field";
				} else {
					$sql_format.= $i==0 ? "$field = :$clean_field":" AND $field = :$clean_field";
				}
				$data[":$clean_field"] = $value;	
			}
			$i++;
		}
		return $sql_format.")";
	}
	private function renderSetValues($values=array(), &$data) {
		$sql_format = "";
		$i = 0;
		foreach($values as $field => $value) {
			$clean_field = trim(str_replace($this->operators, "", $field));
			$sql_format.= $i==0 ? "$clean_field = :$clean_field":", $clean_field = :$clean_field";
			$data[":$clean_field"] = $value;
			$i++;
		}
		return $sql_format;
	}
	private function renderLimitOffset($limit=null) {
		$sql_format = "";
		if($limit) {
			$offset = 0;
			$limit_offset = explode(',', $limit);
			if(isset($limit_offset[0])) {
				$limit = $limit_offset[0];
			}
			if(isset($limit_offset[1])) {
				$offset = $limit_offset[1];
			}
			$sql_format = " LIMIT $limit OFFSET $offset";
		}
		return $sql_format;
	}
	private function renderGroup($group=null) {
		return $group ? " GROUP BY $group":"";
	}
	private function renderOrder($order=null) {
		return $order ? " ORDER BY $order":"";
	}
	public function log() {
		$this->log['took'] = microtime($this->log['took']);
		return $log;
	}
}
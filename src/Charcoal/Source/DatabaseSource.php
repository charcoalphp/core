<?php

namespace Charcoal\Source;

use \Charcoal\Source\AbstractSource as AbstractSource;

use \PDO as PDO;
use \Exception as Exception;
use \InvalidArgumentException as InvalidArgumentException;

// From `charcoal-core`
use \Charcoal\Charcoal as Charcoal;
use \Charcoal\Model\ModelInterface as ModelInterface;
use \Charcoal\Source\DatabaseSourceConfig as DatabaseSourceConfig;

/**
* Database Source, through PDO.
*/
class DatabaseSource extends AbstractSource
{
    const DEFAULT_DB_HOSTNAME = 'localhost';
    const DEFAULT_DB_TYPE = 'mysql';

    /**
    * @var string $_database_ident
    */
    private $_database_ident;
    /**
    * @var DatabaseSourceConfig $_database_config
    */
    private $_database_config;

    /**
    * @var string $_table
    */
    private $_table = null;

    private static $_dbs = [];

    /**
    * @var ModelInterface
    */
    private $_model = null;

    /**
    * @param ModelInterface $model
    * @return Source Chainable
    */
    public function set_model(ModelInterface $model)
    {
        $this->_model = $model;
        return $this;
    }

    /**
    * @throws Exception if not model was previously set
    * @return Model
    */
    public function model()
    {
        if ($this->_model === null) {
            throw new Exception('No model set.');
        }
        return $this->_model;
    }

    /**
    * @param string $database_ident
    * @throws InvalidArgumentException if ident is not a string
    * @return DatabaseSource Chainable
    */
    public function set_database_ident($database_ident)
    {
        if (!is_string($database_ident)) {
            throw new InvalidArgumentException('set_database() expects a string as database ident');
        }
        $this->_database_ident = $database_ident;
        return $this;
    }

    /**
    * Get the current database ident.
    * If null, then the project's default (from `Charcoal::config()` will be used.)
    *
    * @return string
    */
    public function database_ident()
    {
        if ($this->_database_ident === null) {
            return Charcoal::config()->default_database();
        }
        return $this->_database_ident;
    }

    /**
    * @param array $database_config
    * @throws Exception
    * @return DatabaseSource Chainable
    */
    public function set_database_config($database_config)
    {
        if (!is_array($database_config)) {
            throw new Exception('Database config needs to be an array.');
        }
        $this->_database_config = $database_config;
        return $this;
    }

    /**
    * @return mixed
    */
    public function database_config()
    {
        if ($this->_database_config === null) {
            $ident = $this->database_ident();
            return Charcoal::config()->database_config($ident);
        }
        return $this->_database_config;
    }

    /**
    * @throws InvalidArgumentException if argument is not a string
    */
    public function set_table($table)
    {
        if (!is_string($table)) {
            throw new InvalidArgumentException('set_table() expects a string as table');
        }
        $this->_table = $table;

        return $this;
    }

    /**
    * @throws Exception if the table was not set
    */
    public function table()
    {
        if ($this->_table === null) {
            throw new Exception('Table was not set.');
        }
        return $this->_table;
    }

    /**
    * Create a table from a model's metadata.
    *
    * @return boolean Success / Failure
    */
    public function create_table()
    {
        if ($this->table_exists()) {
            // Table already exists
            return true;
        }

        $model = $this->model();
        $metadata = $model->metadata();
        $fields = $this->_get_model_fields($model);

        $fields__sql = [];
        foreach ($fields as $field) {
            $fields_sql[] = $field->sql();
        }

        $defaults = $metadata['data'];

        $q = 'CREATE TABLE  `'.$this->table().'` ('."\n";
        $q .= implode(',', $fields_sql);
        $key = $model->key();
        if ($key) {
            $q .= ', PRIMARY KEY (`'.$key.'`) '."\n";
        }
        // @todo add indexes for all defined list constraints (yea... tough job...)
        $q .= ') ENGINE = MYISAM DEFAULT CHARSET=utf8 COMMENT=\''.addslashes($metadata['name']).'\';';
        $res = $this->db()->query($q);

        return true;
    }

    /**
    * Alter an existing table to match the model's metadata.
    *
    * @return boolean Success / Failure
    */
    public function alter_table()
    {
        if (!$this->table_exists()) {
            return false;
        }

        $fields = $this->_get_model_fields($this->model());

        $cols = $this->table_structure();

        foreach ($fields as $field) {
            $ident = $field->ident();

            if (!array_key_exists($ident, $cols)) {
                // The key does not exist at all.
                $q = 'ALTER TABLE `'.$this->table().'` ADD '.$field->sql();
                $res = $this->db()->query($q);
            } else {
                // The key exists. Validate.
                $col = $cols[$ident];
                $alter = false;
                if (strtolower($col['Type']) != strtolower($field->sql_type())) {
                    $alter = true;
                }
                if ((strtolower($col['Null']) == 'no') && !$field->allow_null()) {
                    $alter = true;
                }
                if ((strtolower($col['Null']) != 'no') && $field->allow_null()) {
                    $alter = true;
                }
                if ($col['Default'] != $field->default_val()) {
                    $alter = true;
                }

                if ($alter === true) {
                    $q = 'ALTER TABLE `'.$this->table().'` CHANGE `'.$ident.'` '.$field->sql();
                    $res = $this->db()->query($q);
                }

            }
        }

        return true;
    }

    /**
    * @return boolean
    */
    public function table_exists()
    {
        $q = 'SHOW TABLES LIKE \''.$this->table().'\'';
        $res = $this->db()->query($q);
        $table_exists = $res->fetchColumn(0);

        // Return as boolean
        return !!$table_exists;
    }

    /**
    * Get the table columns information.
    *
    * @return array
    */
    public function table_structure()
    {
        $q = 'SHOW COLUMNS FROM `'.$this->table().'`';
        $res = $this->db()->query($q);
        $cols = $res->fetchAll((PDO::FETCH_GROUP|PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC));
        return $cols;
    }

    /**
    * @throws Exception if the database is not set.
    */
    public function db($database_ident = null)
    {
        // If no database ident was passed in parameter, use the class database or the config databases
        if ($database_ident === null) {
            $database_ident = $this->database_ident();
        }

        // If the handle was already created, reuse from static $dbh variable
        if (isset(self::$_dbs[$database_ident])) {
            return self::$_dbs[$database_ident];
        }

        $db_config = $this->database_config();

        $db_hostname = isset($db_config['hostname']) ? $db_config['hostname'] : self::DEFAULT_DB_HOSTNAME;
        $db_type = isset($db_config['type']) ? $db_config['type'] : self::DEFAULT_DB_TYPE;
        // ... The other parameters are required. @todo Really?

        try {
            $database = $db_config['database'];
            $username = $db_config['username'];
            $password = $db_config['password'];

            // Set UTf-8 compatibility by default. Disable it if it is set as such in config
            $extra_opts = [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'];
            if (isset($db_config['disable_utf8']) && $db_config['disable_utf8']) {
                $extra_opts = null;
            }

            $db = new PDO($db_type.':host='.$db_hostname.';dbname='.$database, $username, $password, $extra_opts);

            // Set PDO options
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($db_type == 'mysql') {
                $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }

        } catch (PDOException $e) {
            throw new Exception('Error setting up database');
        }

        self::$_dbs[$database_ident] = $db;

        return self::$_dbs[$database_ident];
    }

    /**
    * Get all the fields of a model.
    *
    * @todo Move this method in StorableTrait or AbstractModel
    */
    private function _get_model_fields(ModelInterface $model, $properties = null)
    {
        $metadata = $model->metadata();
        if ($properties === null) {
            $properties = array_keys($model->metadata()->properties());
        }
        
        $fields = [];
        foreach ($properties as $property_ident) {
            $p = $model->p($property_ident);
            $v = $model->property_value($property_ident);
            $p->set_val($v);
            if (!$p || !$p->active()) {
                continue;
            }
            $fields = array_merge($fields, $p->fields());
        }
        return $fields;
    }

    /**
    * ConfigurableTrait > create_config()
    */
    public function create_config($data = null)
    {
        $config = new DatabaseSourceConfig();
        if ($data !== null) {
            $config->set_data($data);
        }
        return $config;
    }

    /**
    * @param mixed $ident
    * @param StoreableInterface $item Optional item to load into
    * @throws Exception
    * @return StorableInterface
    */
    public function load_item($ident, StorableInterface $item = null)
    {
        if ($item !== null) {
            $this->set_model($item);
        } else {
            $item = clone($this->model());
        }

        $q = '
            SELECT
                *
            FROM
               `'.$this->table().'`
            WHERE
               `'.$this->model()->key().'`=:ident
            LIMIT
               1';

        $binds = [
            'ident'=>$ident
        ];
        $sth = $this->db_query($q, $binds);
        if ($sth === false) {
            throw new Exception('Error');
        }

        $data = $sth->fetch();
        if ($data) {
            $item->set_flat_data($data);
        }

        return $item;
    }

    /**
    * Save an item (create a new row) in storage.
    *
    *
    * @param StorableInterface $item The object to save
    * @throws Exception if a database error occurs
    * @return mixed The created item ID, or false in case of an error
    */
    public function save_item(StorableInterface $item)
    {
        if ($this->table_exists() === false) {
            // @todo - Optionnally turn off for some models
            $this->create_table();
        }

        if ($item !== null) {
            $this->set_model($item);
        }
        $model = $this->model();

        $table_structure = array_keys($this->table_structure());

        $fields = $this->_get_model_fields($model);

        $keys = [];
        $values = [];
        $binds = [];
        $binds_types = [];
        foreach ($fields as $f) {
            $k = $f->ident();
            if (in_array($k, $table_structure)) {
                $keys[] = '`'.$k.'`';
                $values[] = ':'.$k.'';
                $binds[$k] = $f->val();
                $binds_types[$k] = $f->sql_pdo_type();
            }
        }

        $q = '
            INSERT
                INTO
            `'.$this->table().'`
                ('.implode(', ', $keys).')
            VALUES
                ('.implode(', ', $values).')';

        $res = $this->db_query($q, $binds, $binds_types);

        if ($res === false) {
            throw new Exception('Could not save item');
        } else {
            if ($model->id()) {
                return $model->id();
            } else {
                return $this->db()->lastInsertId();
            }
        }
    }

    /**
    * Update an item in storage.
    *
    * @param StorableInterface $item The object to update
    * @param array $properties The list of properties to update, if not all
    * @return boolean Success / Failure
    */
    public function update_item(StorableInterface $item, $properties = null)
    {
        if ($item !== null) {
            $this->set_model($item);
        }
        $model = $this->model();

        $table_structure = array_keys($this->table_structure());

        $fields = $this->_get_model_fields($model, $properties);

        $updates = [];
        $binds = [];
        $binds_types = [];
        foreach ($fields as $f) {
            $k = $f->ident();
            if (in_array($k, $table_structure)) {
                if ($k !== $model->key()) {
                    $updates[] = '`'.$k.'` = :'.$k;
                }
                $binds[$k] = $f->val();
                $binds_types[$k] = $f->sql_pdo_type();
            }
        }

        $q = '
            UPDATE
                `'.$this->table().'`
            SET
                '.implode(", \n\t", $updates).'
            WHERE
                `'.$model->key().'`=:id
            LIMIT
                1';

        $res = $this->db_query($q, $binds, $binds_types);

        if ($res === false) {
            return false;
        } else {
            return true;
        }
    }

    /**
    * Delete an item from storage
    *
    * @param StorableInterface $item Optional item to delete. If none, the current model object will be used.
    * @throws Exception
    */
    public function delete_item(StorableInterface $item = null)
    {
        if ($item !== null) {
            $this->set_model($item);
        }
        $model = $this->model();

        if (!$model->id()) {
            throw new Exception('Can not delete item. No ID.');
        }

        $q = '
            DELETE FROM
                `'.$this->table().'`
            WHERE
                `'.$model->key().'` = :id
            LIMIT
                1';

        $binds = [
            'id'=>$model->id()
        ];

        $res = $this->db_query($q, $binds);

        if ($res === false) {
            return false;
        } else {
            return true;
        }
    }

    /**
    * Execute a SQL query, with PDO, and returns the PDOStatement.
    *
    * If the query fails, this method will return false.
    *
    * @param string $q The SQL query to executed
    * @param array $binds
    * @param array $binds_types
    * @return PDOStatement|false The PDOStatement, or false in case of error
    */
    protected function db_query($q, $binds = [], $binds_types = [])
    {
        $sth = $this->db()->prepare($q);
        if (!empty($binds)) {
            foreach ($binds as $k => $v) {
                if ($binds[$k] === null) {
                    $binds[$k] = 'NULL';
                } else if (!is_scalar($binds[$k])) {
                    $binds[$k] = json_encode($binds[$k]);
                }
                $type = isset($binds_types[$k]) ? $binds_types[$k] : PDO::PARAM_STR;
                $sth->bindParam(':'.$k, $binds[$k], $type);
            }
        }
        $err = $sth->execute();
        if ($err === false) {
            return false;
        }

        return $sth;
    }
}

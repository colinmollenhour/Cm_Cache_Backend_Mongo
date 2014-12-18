<?php
/**
 * @author     Colin Mollenhour (http://colin.mollenhour.com)
 * @copyright  Copyright (c) 2013 Colin Mollenhour (http://colin.mollenhour.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Cm_Cache_Backend_Mongo extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{

    const DEFAULT_SERVER     = 'mongodb://localhost:27017/?journal=false&w=1&wTimeoutMS=20000';
    const DEFAULT_DBNAME     = 'cm_cache';
    const DEFAULT_COLLECTION = 'cm_cache';

    const FIELD_DATA     = 'd';
    const FIELD_TAGS     = 't';
    const FIELD_MODIFIED = 'm';
    const FIELD_EXPIRE   = 'e';

    /** @var \Mongo */
    protected $_conn;

    /** @var \MongoDB */
    protected $_db;

    /** @var \MongoCollection */
    protected $_collection;

    /**
     * Available options
     *
     * server         => (string) MongoClient server connection string
     * dbname         => (string) Name of the database to use
     * collection     => (string) Name of the collection to use
     * ensure_index   => (bool)   Ensure indexes exist after each connection (can disable after indexes exist)
     *
     * @var array available options
     */
    protected $_options = array(
        'server'     => self::DEFAULT_SERVER, // See http://us1.php.net/manual/en/mongoclient.construct.php
        'dbname'     => self::DEFAULT_DBNAME,
        'collection' => self::DEFAULT_COLLECTION,
        'ensure_index' => TRUE,
    	'check_utf8'	=> FALSE
    );

    /**
     * @param array $options
     * @return \Cm_Cache_Backend_Mongo
     */
    public function __construct($options)
    {
        if (!extension_loaded('mongo')) {
            Zend_Cache::throwException('The MongoDB extension must be loaded for using this backend !');
        }
        parent::__construct($options);
        
        $this->_conn       = new MongoClient($this->_options['server']);
        $this->_db         = $this->_conn->selectDB($this->_options['dbname']);
        $this->_collection = $this->_db->selectCollection($this->_options['collection']);

        if ($this->_options['ensure_index']) {
            $this->_collection->ensureIndex(
                array(self::FIELD_TAGS => 1),
                array('background' => true)
            );
            $this->_collection->ensureIndex(
                array(self::FIELD_EXPIRE => 1),
                array('background' => true, 'expireAfterSeconds' => 0)
            );
        }
    }
    
    /**
     * Expires a record (only used for testing purposes)
     * @param string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->remove($id);
    }
    
    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param  string  $id  Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|bool cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        return $this->_getField($id, self::FIELD_DATA, $doNotTestCacheValidity);
    }
    
    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return mixed|bool (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        $modified = $this->_getField($id, self::FIELD_MODIFIED, FALSE);
        return $modified ? $modified->sec : FALSE;
    }

    /**
     * @param string $data  Data to cache
     * @param string $id    Cache id
     * @param array $tags   Array of strings, the cache record will be tagged by each string entry
     * @param bool|int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $lifetime = $this->getLifetime($specificLifetime);
        $data = array(
            self::FIELD_DATA => $data,
            self::FIELD_TAGS => $tags,
            self::FIELD_MODIFIED => new MongoDate,
        );
        if ($lifetime) {
            $data[self::FIELD_EXPIRE] = new MongoDate;
            $data[self::FIELD_EXPIRE]->sec += $lifetime;
        }
        return $this->_update($id, array('$set' => $data), array('upsert' => TRUE));
    }
    
    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */    
    public function remove($id)
    {
        $result = $this->_remove(array('_id' => $id), FALSE);
        return $result && $result['n'];
    }

    /**
     * Clean some cache records (protected method used for recursive stuff)
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param string $mode Clean mode
     * @param  array $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if cleaned
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if ( ! $tags && ! ($mode == Zend_Cache::CLEANING_MODE_ALL || $mode == Zend_Cache::CLEANING_MODE_OLD)) {
            return TRUE;
        }
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                return $this->_remove(array());
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                return $this->_remove(array(self::FIELD_EXPIRE => array('$lt' => new MongoDate)));
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                return $this->_remove(array(self::FIELD_TAGS => array('$all' => $tags)));
                break;                
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                return $this->_remove(array(self::FIELD_TAGS => array('$nin' => $tags)));
                break;                
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                return $this->_remove(array(self::FIELD_TAGS => array('$in' => $tags)));
                break;
            default:
                throw new Zend_Cache_Exception('Invalid mode for clean() method');
                break;
        }
    }
        
    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        return $this->_getIds(array());
    }
    
    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */    
    public function getTags()
    {
        return $this->_collection->distinct(self::FIELD_TAGS);
    }
    
    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */    
    public function getIdsMatchingTags($tags = array())
    {
        return $this->_getIds(array(self::FIELD_TAGS => array('$all' => $tags)));
    }
    
    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */    
    public function getIdsNotMatchingTags($tags = array())
    {
        return $this->_getIds(array(self::FIELD_TAGS => array('$nin' => $tags)));
    }
    
    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */    
    public function getIdsMatchingAnyTags($tags = array())
    {
        return $this->_getIds(array(self::FIELD_TAGS => array('$in' => $tags)));
    }
    
    /**
     * Free disk space is the only limit
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */    
    public function getFillingPercentage()
    {
        return 0;
    }
    
    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */    
    public function getMetadatas($id)
    {
        $doc = $this->_collection->findOne(array('_id' => $id), array(self::FIELD_EXPIRE, self::FIELD_TAGS, self::FIELD_MODIFIED));
        if ($doc) {
            $expire = $doc[self::FIELD_EXPIRE]; /* @var $expire \MongoDate */
            $modified = $doc[self::FIELD_MODIFIED]; /* @var $modified \MongoDate */
            return array(
                'expire' => $expire->sec,
                'tags' => $doc[self::FIELD_TAGS],
                'mtime' => $modified->sec,
            );
        }
        return FALSE;
    }
    
    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */    
    public function touch($id, $extraLifetime)
    {
        $expire = $this->_getField($id, self::FIELD_EXPIRE, FALSE);
        if ($expire) {
            $expire->sec += $extraLifetime;
            return $this->_update($id, array('$set' => array(self::FIELD_EXPIRE => $expire)));
        }
        return FALSE;
    }
    
    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => false,
            'tags' => true,
            'expired_read' => false,
            'priority' => false,
            'infinite_lifetime' => true,
            'get_list' => true
        );
    }

    /**
     * @param string $id
     * @param array $data
     * @param array $options
     * @return bool
     */
    protected function _update($id, $data, $options = array())
    {
        try {
        	
        	if (is_array($data)) {
	        	array_walk_recursive($data, array($this, 'checkUtf8OnSave'));
        	} else {
        		$this->checkUtf8OnSave($data);
        	}
        	
            $result = $this->_collection->update(array('_id' => $id), $data, $options);
            if ($result === TRUE || $result['ok']) {
                return TRUE;
            }
        } catch (Exception $e) {
        }
        return FALSE;
    }
    
    public function checkUtf8OnSave(&$value)
    {
    	if (!preg_match('!!u', $value)) {
    		$value = new MongoBinData($value);
    	}
    }

    /**
     * @param array $criteria
     * @param bool $returnBool
     * @return bool
     */
    protected function _remove($criteria, $returnBool = TRUE)
    {
        try {
            $result = $this->_collection->remove($criteria);
            if ($result === TRUE || $result['ok']) {
                return $returnBool ? TRUE : $result;
            }
        } catch (Exception $e) {
        }
        return FALSE;
    }

    /**
     * @param array $query
     * @return array
     */
    protected function _getIds($query)
    {
        try {
            $cursor = $this->_collection->find($query, array('_id' => 1));
            $ids = array();
            while ($doc = $cursor->getNext()) {
                $ids[] = $doc['_id'];
            }
            return $ids;
        } catch (Exception $e) {
        }
        return FALSE;
    }

    /**
     * @param string $id
     * @param string $field
     * @param bool $doNotTestCacheValidity
     * @return bool|string|MongoDate
     */
    protected function _getField($id, $field, $doNotTestCacheValidity)
    {
        $query = array('_id' => $id);
        if ( ! $doNotTestCacheValidity) {
            $query[self::FIELD_EXPIRE] = array('$not' => array('$lt' => new MongoDate));
        }
        try {
            $doc = $this->_collection->findOne($query, array($field => 1));
            if ($doc) {
            	if (is_array($doc[$field])) {
	            	array_walk_recursive($doc[$field], array($this, 'checkUtf8OnLoad'));
            	} else {
            		$this->checkUtf8OnLoad($doc[$field]);
            	}
                return $doc[$field];
            }
        } catch (Exception $e) {
        }
        return FALSE;
    }
    
    public function checkUtf8OnLoad(&$value)
    {
    	if ($value instanceof MongoBinData) {
    		$value = $value->bin;
    	}
    }
    

}

<?php
/**
 * @copyright   2010-2013, The Titon Project
 * @license     http://opensource.org/licenses/bsd-license.php
 * @link        http://titon.io
 */

namespace Titon\Db\Mongo;

use Titon\Db\Query\ResultSet\AbstractResultSet;
use Titon\Db\Query;
use \MongoCursor;

/**
 * Accepts a MongoCursor or MongoDB command response.
 *
 * @package Titon\Db\Mongo
 */
class MongoResultSet extends AbstractResultSet {

    /**
     * Direct query command.
     *
     * @type array
     */
    protected $_command;

    /**
     * Cursor returned from select statements.
     *
     * @type \MongoCursor
     */
    protected $_cursor;

    /**
     * Response from a MongoDB command.
     *
     * @type array
     */
    protected $_response;

    /**
     * Store the result of a MongoDB command, either a MongoCursor or array response.
     *
     * @param \MongoCursor|array $response
     * @param \Titon\Db\Query $query
     */
    public function __construct($response, Query $query = null) {
        parent::__construct($query);

        if ($response instanceof MongoCursor) {
            $this->_cursor = $response;
            $this->_params = $response->info();

            if ($explain = $response->explain()) {
                $this->_time = $explain['millis'];
            }

        } else {
            $params = [];

            if (isset($response['params'])) {
                $params = $response['params'];
                unset($response['params']);
            }

            if ($query) {
                $params['ns'] = $query->getTable();
            }

            if (isset($response['command'])) {
                $this->_command = $response['command'];
                unset($response['command']);
            }

            $this->_response = $response;
            $this->_params = $params;
            $this->_executed = isset($response['ok']);
            $this->_success = $this->hasExecuted() ? (bool) $response['ok'] : false;

            if (!$query || in_array($query->getType(), [Query::UPDATE, Query::INSERT, Query::DELETE, Query::MULTI_INSERT])) {
                $this->_count = isset($response['n']) ? (int) $response['n'] : 0;
            } else {
                $this->_count = 1;
            }

            $this->_time = number_format(microtime() - $response['startTime'], 5);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function count() {
        if ($this->_cursor) {
            return $this->_cursor->count();

        } else if (isset($this->_response['n'])) {
            return $this->_response['n'];
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function execute() {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function find() {
        if (isset($this->_response['retval'])) {
            return $this->_response['retval'];

        } else if (isset($this->_response['results'])) {
            return $this->_response['results'];

        } else if (isset($this->_response['values'])) {
            return $this->_response['values'];
        }

        $cursor = $this->getCursor();
        $results = [];

        if (!$cursor) {
            return $results;
        }

        while ($cursor->hasNext()) {
            $results[] = $cursor->getNext();
        }

        return $results;
    }

    /**
     * Return the database command array.
     *
     * @return array
     */
    public function getCommand() {
        return $this->_command;
    }

    /**
     * Return the MongoDB result cursor.
     *
     * @return \MongoCursor
     */
    public function getCursor() {
        return $this->_cursor;
    }

    /**
     * Return the query response array.
     *
     * @return array
     */
    public function getResponse() {
        return $this->_response;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatement() {
        $query = $this->getQuery();
        $params = $this->getParams();

        // Command query
        if ($command = $this->getCommand()) {
            return sprintf('db.runCommand(%s);', json_encode($command));
        }

        $statement = 'db.' . $params['ns'] . '.';
        $attributes = $query->getAttributes();

        switch ($query->getType()) {
            case Query::INSERT:
                unset($params['values']['_id']);
                $statement .= sprintf('insert(%s)', json_encode($params['values']));
            break;

            case Query::MULTI_INSERT:
                $statement .= sprintf('insert(%s)', json_encode(array_map(function($value) {
                    unset($value['_id']);
                    return $value;
                }, $params['values'])));
            break;

            case Query::SELECT:
                $where = [];
                $order = [];

                if ($query->getGroupBy()) {
                    $statement .= sprintf('group(%s, function(){}, ["items":[]], null, %s, null)', json_encode($params['groupBy']), json_encode($params['where']));

                } else {
                    if (is_array($params['query'])) {
                        if (isset($params['query']['$query'])) {
                            $where = $params['query']['$query'];
                        } else {
                            $where = $params['query'];
                        }

                        if (isset($params['query']['$orderby'])) {
                            $order = $params['query']['$orderby'];
                        }
                    }

                    $statement .= sprintf('find(%s, %s)', json_encode($where), json_encode($params['fields']));
                }

                if ($order) {
                    $statement .= sprintf('.sort(%s)', json_encode($order));
                }

                if ($limit = $query->getLimit()) {
                    $statement .= sprintf('.limit(%s)', $limit);
                }

                if ($offset = $query->getOffset()) {
                    $statement .= sprintf('.skip(%s)', $offset);
                }

                if (!empty($query->isCount)) {
                    $statement .= '.count()';
                }
            break;

            case Query::UPDATE:
                $statement .= sprintf('update(%s, %s, %s)', json_encode($params['where']), json_encode($params['fields']), json_encode($attributes));
            break;

            case Query::DELETE:
                $statement .= sprintf('remove(%s, %s)', json_encode($params['where']), empty($attributes['justOne']) ? 'false' : 'true');
            break;

            case Query::TRUNCATE:
                $statement .= 'remove()';
            break;

            case Query::CREATE_TABLE:
                $statement = sprintf('db.createCollection(%s, %s)', json_encode($params['name']), json_encode($attributes));
            break;

            case Query::CREATE_INDEX:
                $statement .= sprintf('ensureIndex(%s, %s)', json_encode($params['fields']), json_encode($attributes));
            break;

            case Query::DROP_TABLE:
                $statement .= 'drop()';
            break;

            case Query::DROP_INDEX:
                $statement .= sprintf('deleteIndex(%s)', json_encode($params['fields']));
            break;

            default:
                $statement = '(unknown statement)';
            break;
        }

        return $statement . ';';
    }

    /**
     * {@inheritdoc}
     */
    public function save() {
        if ($this->isSuccessful()) {
            return $this->_count;
        }

        return false;
    }

}
<?php
/**
 * @copyright   2010-2013, The Titon Project
 * @license     http://opensource.org/licenses/bsd-license.php
 * @link        http://titon.io
 */

namespace Titon\Db\Mongo\Type;

use Titon\Db\Driver\Type\IntType;
use \MongoInt32;

/**
 * Represents a 32 bit integer data type.
 *
 * @package Titon\Db\Mongo\Type
 */
class Int32Type extends IntType {

    /**
     * {@inheritdoc}
     */
    public function from($value) {
        return (string) $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getName() {
        return self::INT . 32;
    }

    /**
     * {@inheritdoc}
     */
    public function to($value) {
        if ($value instanceof MongoInt32) {
            return $value;
        }

        return new MongoInt32((string) $value);
    }

}
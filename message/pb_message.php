<?
/**
 * Including of all files needed to parse messages
 * @author Nikolai Kordulla
 */
require_once(dirname(__FILE__). '/' . 'encoding/pb_base128.php');
require_once(dirname(__FILE__). '/' . 'type/pb_enum.php');
require_once(dirname(__FILE__). '/' . 'type/pb_string.php');
require_once(dirname(__FILE__). '/' . 'type/pb_int.php');
/**
 * Abstract Message class
 * @author Nikolai Kordulla
 */
abstract class PBMessage
{
    const WIRED_VARINT = 0;
    const WIRED_64BIT = 1;
    const WIRED_STRING = 2;
    const WIRED_START_GROUP = 3;
    const WIRED_END_GROUP = 4;

    var $base128;

    // here are the field types
    var $fields = array();
    // the values for the fields
    var $values = array();

    // type of the class
    var $wired_type = 2;

    // the value of a class
    var $value = null;

    // modus byte or string parse (byte for productive string for better reading and debuging)
    // 1 = byte, 2 = String
    const MODUS = 1;

    /**
     * Constructor - initialize base128 class
     */
    public function __construct()
    {
        $this->value = $this;
        $this->base128 = new base128varint(PBMessage::MODUS);
    }

    /**
     * Get the wired_type and field_type
     * @param $number as decimal
     * @return array wired_type, field_type
     */
    public function get_types($number)
    {
        $binstring = decbin($number);
        $types = array();
        $low = substr($binstring, strlen($binstring) - 3, strlen($binstring));
        $high = substr($binstring,0, strlen($binstring) - 3) . '0000';
        $types['wired'] = bindec($low);
        $types['field'] = bindec($binstring) >> 3;
        return $types;
    }

    /**
     * Just built packages and make hex to bin
     * @param the message string
     */
    private function built_packages($message)
    {
        $ret = array();
        if (PBMessage::MODUS == 1)
        {
            $newmessage = '';
            $package = '';
            // now convert to hex
            $newpart = '';
            $mess_length = mb_strlen($message);
            for ($i = 0; $i < $mess_length; ++$i)
            {
                $value = decbin(ord($message[$i]));

                if ($value >= 10000000)
                {
                    // now fill to eight with 00
                    $package .= $value;
                }
                else
                {
                    // now fill to length of eight with 0
                    $value = substr('00000000', 0, 8 - strlen($value) % 8) . $value;
                    $ret[] = $package . $value;
                    $package = '';
                }
            }
            return $ret;
        }


        $package = '';
        $mess_length = mb_strlen($message);
        for ($i = 0; $i < $mess_length; $i=$i+2)
        {
            $value = decbin(hexdec(substr($message,$i, 2)));

            if (strlen($value) == 8)
            {
                // now fill to eight with 00
                $package .= $value;
            }
            else
            {
                // now fill to length of eight with 0
                $value = substr('00000000', 0, 8 - strlen($value) % 8) . $value;
                $package .= $value;
                $ret[] = $package;
                $package = '';
            }
        }
        return $ret;
    }

    /**
     * Encodes a Message
     * @return string the encoded message
     */
    public function SerializeToString($rec=-1)
    {
        $string = '';
        // wired and type
        if ($rec > -1)
        {
            $string .= $this->base128->set_value($rec << 3 | $this->wired_type);
        }


        $stringinner = '';

        foreach ($this->fields as $index => $field)
        {
            if (is_array($this->values[$index]) && count($this->values[$index]) > 0)
            {
                // make serialization for every array
                foreach ($this->values[$index] as $array)
                {
                    $newstring = '';
                    $newstring .= $array->SerializeToString($index);

                    $stringinner .= $newstring;
                }
            }
            else if ($this->values[$index] != null)
            {
                // wired and type
                $newstring = '';
                $newstring .= $this->values[$index]->SerializeToString($index);

                $stringinner .= $newstring;
            }
        }

        if ($this->wired_type == PBMessage::WIRED_STRING && $rec > -1)
        {
            $stringinner = $this->base128->set_value(mb_strlen($stringinner) / PBMessage::MODUS) . $stringinner;
        }

        return $string . $stringinner;
    }

    /**
     * Decodes a Message and Built its things
     *
     * @param message as stream of hex example '1a 03 08 96 01'
     */
    public function ParseFromString($message)
    {
        if (PBMessage::MODUS != 1)
        {
            $message = str_replace(' ','', $message);
        }
        //$type = 1;
        $array = $this->built_packages($message);
        // no messtype set
        $messtypes = array();
        $length = 0;
        $this->_ParseFromArray($array);
    }

    /**
     * Internal function
     */
    public function ParseFromArray(&$array)
    {
        // first byte is length
        $first = array_shift($array);
        $length = $this->base128->get_value($first);
        $newlength  = 0;
        $newarray = array();
        $i = 0;
        $a_length = count($array);
        while ($newlength < $length && $i < $a_length)
        {
            $newlength += strlen($array[$i]) / 8;
            ++$i;
        }
        // just take the splice from this array
        $this->_ParseFromArray(array_splice($array, 0, $i));
        return $this;
    }

    /**
     * Internal function
     */
    private function _ParseFromArray(&$array)
    {
        while (!empty($array))
        {
            // number from base128
            $first = array_shift($array);
            $number = $this->base128->get_value($first);

            // now get the message type
            $messtypes = $this->get_types($number);

            // now make method test
            if (!isset($this->fields[$messtypes['field']]))
            {
                // field is unknown so just ignore it
                // throw new Exception('Field ' . $messtypes['field'] . ' not present ');
                if ($messtypes['wired'] == PBMessage::WIRED_STRING)
                    $consume = new PBString();
                else if ($messtypes['wired'] == PBMessage::WIRED_VARINT)
                    $consume = new PBInt();
                // perhaps send a warning out
                $consume->parseFromArray($array);
                continue;
            }

            // now array or not
            if (is_array($this->values[$messtypes['field']]))
            {
                $this->values[$messtypes['field']][] = new $this->fields[$messtypes['field']]();
                $index = count($this->values[$messtypes['field']]) - 1;
                if ($messtypes['wired'] != $this->values[$messtypes['field']][$index]->wired_type)
                    throw new Exception('Expected type:' . $messtypes['wired'] . ' but had ' . $this->fields[$messtypes['field']]->wired_type);
                $this->values[$messtypes['field']][$index]->ParseFromArray($array);
            }
            else
            {
                $this->values[$messtypes['field']] = new $this->fields[$messtypes['field']]();
                if ($messtypes['wired'] != $this->values[$messtypes['field']]->wired_type)
                    throw new Exception('Expected type:' . $messtypes['wired'] . ' but had ' . $this->fields[$messtypes['field']]->wired_type);
                $this->values[$messtypes['field']]->ParseFromArray($array);
            }

        }
    }

    /**
     * Add an array value
     * @param int - index of the field
     */
    protected function _add_arr_value($index)
    {
        return $this->values[$index][] = new $this->fields[$index]();
    }

    /**
     * Set an value
     * @param int - index of the field
     * @param Mixed value
     */
    protected function _set_value($index, $value)
    {
        if (gettype($value) == 'object')
        {
            $this->values[$index] = $value;
        }
        else
        {
            $this->values[$index] = new $this->fields[$index]();
            $this->values[$index]->value = $value;
        }
    }

    /**
     * Get a value
     * @param id of the field
     */
    protected function _get_value($index)
    {
        if ($this->values[$index] == null)
            return null;
        return $this->values[$index]->value;
    }

    /**
     * Get array value
     * @param id of the field
     * @param value
     */
    protected function _get_arr_value($index, $value)
    {
        return $this->values[$index][$value];
    }

    /**
     * Get array size
     * @param id of the field
     */
    protected function _get_arr_size($index)
    {
        return count($this->values[$index]);
    }
}
?>
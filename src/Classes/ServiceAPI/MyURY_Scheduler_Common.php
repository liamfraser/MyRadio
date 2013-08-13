<?php
/**
 * Provides the Scheduler Common class for MyURY
 * @package MyURY_Scheduler
 */

/**
 * The Scheduler_Common class is used to provide common resources for the MyURY Scheduler classes
 * @version 04092012
 * @author Lloyd Wallis <lpw@ury.org.uk>
 * @package MyURY_Scheduler
 * @uses \Database
 * 
 */

abstract class MyURY_Scheduler_Common extends ServiceAPI {

  protected static $metadata_keys = array();
  
  protected $metadata;
  
  /**
   * Gets the id for the string representation of a type of metadata
   */
  public static function getMetadataKey($string) {
    self::cacheMetadataKeys();
    if (!isset(self::$metadata_keys[$string])) {
      throw new MyURYException('Metadata Key ' . $string . ' does not exist');
    }
    return self::$metadata_keys[$string]['id'];
  }

  /**
   * Gets whether the type of metadata is allowed to exist more than once
   */
  public static function isMetadataMultiple($id) {
    self::cacheMetadataKeys();
    foreach (self::$metadata_keys as $key) {
      if ($key['id'] == $id)
        return $key['multiple'];
    }
    throw new MyURYException('Metadata Key ID ' . $id . ' does not exist');
  }

  protected static function cacheMetadataKeys() {
    if (empty(self::$metadata_keys)) {
      self::initDB();
      $r = self::$db->fetch_all('SELECT metadata_key_id AS id, name, allow_multiple AS multiple FROM metadata.metadata_key');
      foreach ($r as $key) {
        self::$metadata_keys[$key['name']]['id'] = (int) $key['id'];
        self::$metadata_keys[$key['name']]['multiple'] = ($key['multiple'] === 't');
      }
    }
  }

  protected static function getCreditName($credit_id) {
    self::initDB();
    $r = self::$db->fetch_one('SELECT name FROM people.credit_type WHERE credit_type_id=$1 LIMIT 1', array((int) $credit_id));
    if (empty($r))
      return 'Contrib';
    return $r['name'];
  }

  protected static function formatTimeHuman($time) {
    date_default_timezone_set('UTC');
    $stime = date(' H:i', $time['start_time']);
    $etime = date('H:i', $time['start_time'] + $time['duration']);
    date_default_timezone_set('Europe/London');
    return self::getDayNameFromID($time['day']) . $stime . ' - ' . $etime;
  }

  protected static function getDayNameFromID($day) {
    switch ($day) {
      case 0:
        return 'Mon';
      case 1:
        return 'Tue';
      case 2:
        return 'Wed';
      case 3:
        return 'Thu';
      case 4:
        return 'Fri';
      case 5:
        return 'Sat';
      case 6:
        return 'Sun';
        break;
      default:
        throw new MyURYException('Invalid Day ID ' . $day);
    }
  }

  /**
   * 
   * @param int $term_id The term to check for
   * @param Array $time:
   * day: The day ID (0-6) to check for
   * start_time: The start time in seconds since midnight
   * duration: The duration in seconds
   * 
   * Return: Array of conflicts with week # as key and show as value
   */
  protected static function getScheduleConflicts($term_id, $time) {
    self::initDB();
    $conflicts = array();
    $date = MyURY_Scheduler::getTermStartDate($term_id);
    //Iterate over each week
    for ($i = 1; $i <= 10; $i++) {
      //Get the start and end times
      $start = $date + $time['start_time'];
      $end = $date + $time['start_time'] + $time['duration'];
      //Query for conflicts
      $r = self::getScheduleConflict($start, $end);
      
      //If there's a conflict, log it
      if (!empty($r)) $conflicts[$i] = $r['show_season_id'];
      
      //Increment week
      $date += 3600*24*7;
    }
    return $conflicts;
  }
  
  /**
   * Returns a schedule conflict between the given times, if one exists
   * @param int $start Start time
   * @param int $end End time
   * @return Array empty if no conflict, show information otherwise
   */
  protected static function getScheduleConflict($start, $end) {
    $start = CoreUtils::getTimestamp($start);
    $end = CoreUtils::getTimestamp($end);
    
    return self::$db->fetch_one('SELECT show_season_id FROM schedule.show_season_timeslot
        WHERE (start_time <= $1 AND start_time + duration > $1)
        OR (start_time > $1 AND start_time < $2)', array($start, $end));
    
  }
  
  /**
   * Returns the Term currently available for Season applications.
   * Users can only apply to the current term, or one week before the next one
   * starts.
   * 
   * @return int|null Returns the id of the term or null if no active term
   */
  public static function getActiveApplicationTerm() {
    $return = self::$db->fetch_column('SELECT termid FROM terms
      WHERE start <= $1 AND finish >= NOW() LIMIT 1',
            array(CoreUtils::getTimestamp(strtotime('+28 Days'))));
    return $return[0];
  }
  
  /**
   * Sets a *text* metadata key to the specified value. Does not work for image metadata.
   * 
   * If any value is the same as an existing one, no action will be taken.
   * If the given key has is_multiple, then the value will be added as a new, additional key.
   * If the key does not have is_multiple, then any existing values will have effective_to
   * set to the effective_from of this value, effectively replacing the existing value.
   * This will *not* unset is_multiple values that are not in the new set.
   * 
   * @param String $string_key The metadata key
   * @param mixed $value The metadata value. If key is_multiple and value is an array, will create instance
   * for value in the array.
   * @param int $effective_from UTC Time the metavalue is effective from. Default now.
   * @param int $effective_to UTC Time the metadata value is effective to. Default NULL (does not expire).
   * @param String $table The metadata table.<br>
   * Should be one of show_metadata, season_metadata or timeslot_metadata
   * @param String $id_field The ID field in the metadata table.<br>
   * Should be one of show_id, show_season_id or show_season_timeslot_id.
   */
  public function setMeta($string_key, $value, $effective_from = null, $effective_to = null,
          $table = null, $id_field = null) {
    if ($table != 'show_metadata'
            && $table != 'season_metadata'
            && $table != 'timeslot_metadata') {
      throw new MyURYException('Table must be specified and valid!');
    }
    if ($id_field != 'show_id'
            && $id_field != 'show_season_id'
            && $id_field != 'show_season_timeslot_id') {
      throw new MyURYException('ID Field must be specified and valid!');
    }
    $meta_id = self::getMetadataKey($string_key); //Integer meta key
    $multiple = self::isMetadataMultiple($meta_id); //Bool whether multiple values are allowed
    if ($effective_from === null) $effective_from = time();
    
    //Check if value is different
    if ($multiple) {
      if (is_array($value)) {
        foreach ($value as $k => $v) {
          if (in_array($v, $this->getMeta($string_key)) !== false) {
            //This is a pre-existing value
            unset($value[$k]);
          }
        }
        if (empty($value)) {
          //Nothing's changed
          return false;
        }
      } else {
        if (in_array($value, $this->getMeta($string_key)) !== false) {
          //This is a pre-existing value
          return false;
        }
      }
    } else {
      //Not multiple key
      if (is_array($value)) {
        //Can't have an array for a single value
        throw new MyURYException('Tried to set multiple values for a single-instance metadata key!');
      }
      if ($value == $this->getMeta($string_key)) {
        //Value not changed
        return false;
      }
      //Okay, expire old value.
      self::$db->query('UPDATE schedule.'.$table.' SET effective_to = $1
        WHERE metadata_key_id=$2 AND '.$id_field.'=$3',
              array(CoreUtils::getTimestamp($effective_from), $meta_id, $this->getID()));
    }
    
    $sql = 'INSERT INTO schedule.'.$table
      .' (metadata_key_id, '.$id_field.', memberid, approvedid, metadata_value, effective_from, effective_to) VALUES ';
    $params = array($meta_id, $this->getID(), User::getInstance()->getID(), CoreUtils::getTimestamp($effective_from),
        $effective_to == null ? null : CoreUtils::getTimestamp($effective_to));
    
    if (is_array($value)) {
      $param_counter = 6;
      foreach ($value as $v) {
        $sql .= '($1, $2, $3, $3, $'.$param_counter.', $4, $5),';
        $params[] = $v;
        $param_counter++;
      }
      //Remove the extra comma
      $sql = substr($sql, 0, -1);
    } else {
      $sql .= '($1, $2, $3, $3, $6, $4, $5)';
      $params[] = $value;
    }
    
    self::$db->query($sql, $params);
    
    if ($multiple && is_array($value)) {
      foreach ($value as $v) {
        if (!in_array($v, $this->metadata[$meta_id])) {
          $this->metadata[$meta_id][] = $v;
        }
      }
    } else {
      $this->metadata[$meta_id] = $value;
    }
    
    return true;
  }
  
  private function __construct(){}

}

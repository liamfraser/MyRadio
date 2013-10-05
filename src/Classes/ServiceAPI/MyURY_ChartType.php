<?php
/**
 * Provides the MyURY_ChartType class for MyURY
 * @package MyURY_Core
 */

/**
 * The ChartType class fetches information about types of chart.
 * @version 20130426
 * @author Matt Windsor <matt.windsor@ury.org.uk>
 * @package MyURY_Charts
 * @uses \Database
 */
class MyURY_ChartType extends MyURY_Type {
  /**
   * The singleton store for ChartType objects
   * @var MyURY_ChartType[]
   */
  private static $chart_types = [];

  /**
   * The numeric ID of the chart type.
   * @var Int
   */
  private $chart_type_id;


  /**
   * The list of IDs of MyURY_ChartReleases for this chart type.
   * @var Int
   */
  private $chart_release_ids;

  /**
   * Constructs a new MyURY_ChartType from the database.
   *
   * You should generally use MyURY_ChartType::getInstance instead.
   *
   * @param $chart_type_id  The numeric ID of the chart type.
   *
   * @return The chart type with the given ID.
   */
  protected function __construct($chart_type_id) {
    $this->chart_type_id = $chart_type_id;

    $chart_type_data = self::$db->fetch_one(
      'SELECT *
       FROM music.chart_type
       WHERE chart_type_id = $1;',
      [$chart_type_id]
    );
    if (empty($chart_type_data)) {
      throw new MyUryException('The specified Chart Type does not seem to exist.');
      return;
    }

    parent::construct_type($chart_type_data['name'], $chart_type_data['description']);

    $this->chart_release_ids = self::$db->fetch_column(
      'SELECT chart_release_id
       FROM music.chart_release
       WHERE chart_type_id = $1
       ORDER BY submitted DESC;',
      [$chart_type_id]
    );
  }

  /**
   * Retrieves the MyURY_ChartType with the given numeric ID.
   *
   * @param $chart_type_id  The numeric ID of the chart type.
   *
   * @return The chart type with the given ID.
   */
  public static function getInstance($chart_type_id=-1) {
    self::__wakeup();

    if (!is_numeric($chart_type_id)) {
      throw new MyURYException(
        'Invalid Chart Type ID!',
        MyURYException::FATAL
      );
    }

    if (!isset(self::$chart_types[$chart_type_id])) {
      self::$chart_types[$chart_type_id] = new self($chart_type_id);
    }
    return self::$chart_types[$chart_type_id];
  }

  /**
   * Retrieves all current chart types.
   *
   * @return array  An array of all active chart types.
   */
  public function getAll() {
    $chart_type_ids = self::$db->fetch_column(
      'SELECT chart_type_id
       FROM music.chart_type
       ORDER BY chart_type_id ASC;',
      []
    );
    $chart_types = [];
    foreach ($chart_type_ids as $chart_type_id) {
      $chart_types[] = self::getInstance($chart_type_id);
    }
    return $chart_types;
  }

  /**
   * Retrieves the unique ID of this chart type.
   *
   * @return The chart type ID.
   */
  public function getID() {
    return $this->chart_type_id;
  }

  /**
   * Retrieves the number of releases made under this chart type.
   *
   * @return int The release count.
   */
  public function getNumberOfReleases() {
    return sizeof($this->chart_release_ids);
  }
  
  /**
   * Retrieves the releases made under this chart type.
   *
   * @return array The chart releases.
   */
  public function getReleases() {
    $chart_releases = [];
    foreach ($this->chart_release_ids as $chart_release_id) {
      $chart_releases[] = MyURY_ChartRelease::getInstance($chart_release_id, $this);
    }
    return $chart_releases;
  }

  /**
   * Sets the name of this chart type.
   *
   * @param string $name  The new name of the chart type.
   *
   * @return This object, for method chaining.
   */
  public function setName($name) {
    if (empty($name)) {
      throw new MyURYException('Chart type name must not be empty!');
    }

    $this->name = $name;
    self::$db->query(
      'UPDATE music.chart_type
       SET name = $1
       WHERE chart_type_id = $2;',
      [$name, $this->getID()]
    );

    return $this;
  }

  /**
   * Sets the description of this chart type.
   *
   * @param string $description  The new description of the chart type.
   *
   * @return This object, for method chaining.
   */
  public function setDescription($description) {
    if (empty($description)) {
      throw new MyURYException('Chart type description must not be empty!');
    }

    $this->description = $description;
    self::$db->query(
      'UPDATE music.chart_type
       SET description = $1
       WHERE chart_type_id = $2;',
      [$description, $this->getID()]
    );

    return $this;
  }

  /**
   * Converts this chart type to a table data source.
   *
   * @return array  The object as a data source.
   */
  public function toDataSource() {
    return [
      'name' => $this->getName(),
      'description' => $this->getDescription(),
      'releases' => [
        'display' => 'text',
        'value' => $this->getNumberOfReleases(),
        'title' => 'Click to see releases for this chart type.',
        'url' => CoreUtils::makeURL(
          'Charts',
          'listChartReleases',
          ['chart_type_id' => $this->getID()]
        )
      ],
      'editlink' => [
        'display' => 'icon',
        'value' => 'script',
        'title' => 'Edit Chart Type',
        'url' => CoreUtils::makeURL(
          'Charts',
          'editChartType',
          ['chart_type_id' => $this->getID()]
        )
      ],
    ];
  }
}

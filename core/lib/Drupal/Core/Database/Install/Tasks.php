<?php

namespace Drupal\Core\Database\Install;

use Drupal\Core\Database\Database;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Database installer structure.
 *
 * Defines basic Drupal requirements for databases.
 */
abstract class Tasks {

  /**
   * The name of the PDO driver this database type requires.
   *
   * @var string
   */
  protected $pdoDriver;

  /**
   * Structure that describes each task to run.
   *
   * @var array
   *
   * Each value of the tasks array is an associative array defining the function
   * to call (optional) and any arguments to be passed to the function.
   */
  protected $tasks = [
    [
      'function'    => 'checkEngineVersion',
      'arguments'   => [],
    ],
    [
      'arguments'   => [
        'CREATE TABLE {drupal_install_test} (id int NOT NULL PRIMARY KEY)',
        'Drupal can use CREATE TABLE database commands.',
        'Failed to <strong>CREATE</strong> a test table on your database server with the command %query. The server reports the following message: %error.<p>Are you sure the configured username has the necessary permissions to create tables in the database?</p>',
        TRUE,
      ],
    ],
    [
      'arguments'   => [
        'INSERT INTO {drupal_install_test} (id) VALUES (1)',
        'Drupal can use INSERT database commands.',
        'Failed to <strong>INSERT</strong> a value into a test table on your database server. We tried inserting a value with the command %query and the server reported the following error: %error.',
      ],
    ],
    [
      'arguments'   => [
        'UPDATE {drupal_install_test} SET id = 2',
        'Drupal can use UPDATE database commands.',
        'Failed to <strong>UPDATE</strong> a value in a test table on your database server. We tried updating a value with the command %query and the server reported the following error: %error.',
      ],
    ],
    [
      'arguments'   => [
        'DELETE FROM {drupal_install_test}',
        'Drupal can use DELETE database commands.',
        'Failed to <strong>DELETE</strong> a value from a test table on your database server. We tried deleting a value with the command %query and the server reported the following error: %error.',
      ],
    ],
    [
      'arguments'   => [
        'DROP TABLE {drupal_install_test}',
        'Drupal can use DROP TABLE database commands.',
        'Failed to <strong>DROP</strong> a test table from your database server. We tried dropping a table with the command %query and the server reported the following error %error.',
      ],
    ],
  ];

  /**
   * Results from tasks.
   *
   * @var array
   */
  protected $results = [
    'fail' => [],
    'pass' => [],
  ];

  /**
   * Ensure the PDO driver is supported by the version of PHP in use.
   */
  protected function hasPdoDriver() {
    return in_array($this->pdoDriver, \PDO::getAvailableDrivers());
  }

  /**
   * Assert test as failed.
   */
  protected function fail($message) {
    $this->results['fail'][] = $message;
  }

  /**
   * Assert test as a pass.
   */
  protected function pass($message) {
    $this->results['pass'][] = $message;
  }

  /**
   * Check whether Drupal is installable on the database.
   */
  public function installable() {
    return $this->hasPdoDriver() && empty($this->error);
  }

  /**
   * Return the human-readable name of the driver.
   */
  abstract public function name();

  /**
   * Return the minimum required version of the engine.
   *
   * @return
   *   A version string. If not NULL, it will be checked against the version
   *   reported by the Database engine using version_compare().
   */
  public function minimumVersion() {
    return NULL;
  }

  /**
   * Run database tasks and tests to see if Drupal can run on the database.
   *
   * @return array
   *   A list of error messages.
   */
  public function runTasks() {
    // We need to establish a connection before we can run tests.
    if ($this->connect()) {
      foreach ($this->tasks as $task) {
        if (!isset($task['function'])) {
          $task['function'] = 'runTestQuery';
        }
        if (method_exists($this, $task['function'])) {
          // Returning false is fatal. No other tasks can run.
          if (FALSE === call_user_func_array([$this, $task['function']], $task['arguments'])) {
            break;
          }
        }
        else {
          $this->fail(t("Failed to run all tasks against the database server. The task %task wasn't found.", ['%task' => $task['function']]));
        }
      }
    }
    return $this->results['fail'];
  }

  /**
   * Check if we can connect to the database.
   */
  protected function connect() {
    try {
      // This doesn't actually test the connection.
      Database::setActiveConnection();
      // Now actually do a check.
      Database::getConnection();
      $this->pass('Drupal can CONNECT to the database ok.');
    }
    catch (\Exception $e) {
      $this->fail(t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist, and have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname and port number?</li></ul>', ['%error' => $e->getMessage()]));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Run SQL tests to ensure the database can execute commands with the current user.
   */
  protected function runTestQuery($query, $pass, $fail, $fatal = FALSE) {
    try {
      Database::getConnection()->query($query);
      $this->pass(t($pass));
    }
    catch (\Exception $e) {
      $this->fail(t($fail, ['%query' => $query, '%error' => $e->getMessage(), '%name' => $this->name()]));
      return !$fatal;
    }
  }

  /**
   * Check the engine version.
   */
  protected function checkEngineVersion() {
    // Ensure that the database server has the right version.
    // We append '-AnyName' to the minimum version for comparison purposes, so
    // that engines that append a package name or other build information to
    // their version strings still pass. For example, MariaDB might report its
    // version as '10.2.7-MariaDB' or '10.2.7+maria' or similar.
    // version_compare() treats '-' and '+' as equivalent, and non-numeric
    // parts other than conventional stability specifiers (dev, alpha, beta,
    // etc.) as equal to each other and less than numeric parts and stability
    // specifiers. In other words, 10.2.7-MariaDB, 10.2.7+maria, and
    // 10.2.7-AnyName are all equal to each other and less than 10.2.7-alpha.
    // This means that by appending '-AnyName' for the comparison check, that
    // alpha and other pre-release versions of the minimum will pass this
    // check, which isn't ideal; however, people running pre-release versions
    // of database servers should know what they're doing, whether Drupal warns
    // them or not.
    // @see https://www.php.net/manual/en/function.version-compare.php
    if ($this->minimumVersion() && version_compare(Database::getConnection()->version(), $this->minimumVersion() . '-AnyName', '<')) {
      $this->fail(t("The database server version %version is less than the minimum required version %minimum_version.", ['%version' => Database::getConnection()->version(), '%minimum_version' => $this->minimumVersion()]));
    }
  }

  /**
   * Return driver specific configuration options.
   *
   * @param $database
   *   An array of driver specific configuration options.
   *
   * @return
   *   The options form array.
   */
  public function getFormOptions(array $database) {
    // Use reflection to determine the driver name.
    // @todo https:///www.drupal.org/node/3123240 Provide a better way to get
    //   the driver name.
    $reflection = new \ReflectionClass($this);
    $dir_parts = explode(DIRECTORY_SEPARATOR, dirname(dirname($reflection->getFileName())));
    $driver = array_pop($dir_parts);

    $form['database'] = [
      '#type' => 'textfield',
      '#title' => t('Database name'),
      '#default_value' => empty($database['database']) ? '' : $database['database'],
      '#size' => 45,
      '#required' => TRUE,
      '#states' => [
        'required' => [
          ':input[name=driver]' => ['value' => $driver],
        ],
      ],
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => t('Database username'),
      '#default_value' => empty($database['username']) ? '' : $database['username'],
      '#size' => 45,
      '#required' => TRUE,
      '#states' => [
        'required' => [
          ':input[name=driver]' => ['value' => $driver],
        ],
      ],
    ];

    $form['password'] = [
      '#type' => 'password',
      '#title' => t('Database password'),
      '#default_value' => empty($database['password']) ? '' : $database['password'],
      '#required' => FALSE,
      '#size' => 45,
    ];

    $form['advanced_options'] = [
      '#type' => 'details',
      '#title' => t('Advanced options'),
      '#weight' => 10,
    ];

    global $install_state;
    // @todo https://www.drupal.org/project/drupal/issues/3110839 remove PHP 7.4
    //   work around and add a better message for the migrate UI.
    $profile = $install_state['parameters']['profile'] ?? NULL;
    $db_prefix = ($profile == 'standard') ? 'drupal_' : $profile . '_';
    $form['advanced_options']['prefix'] = [
      '#type' => 'textfield',
      '#title' => t('Table name prefix'),
      '#default_value' => empty($database['prefix']) ? '' : $database['prefix'],
      '#size' => 45,
      '#description' => t('If more than one application will be sharing this database, a unique table name prefix – such as %prefix – will prevent collisions.', ['%prefix' => $db_prefix]),
      '#weight' => 10,
    ];

    $form['advanced_options']['host'] = [
      '#type' => 'textfield',
      '#title' => t('Host'),
      '#default_value' => empty($database['host']) ? 'localhost' : $database['host'],
      '#size' => 45,
      // Hostnames can be 255 characters long.
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['advanced_options']['port'] = [
      '#type' => 'number',
      '#title' => t('Port number'),
      '#default_value' => empty($database['port']) ? '' : $database['port'],
      '#min' => 0,
      '#max' => 65535,
    ];

    return $form;
  }

  /**
   * Validates driver specific configuration settings.
   *
   * Checks to ensure correct basic database settings and that a proper
   * connection to the database can be established.
   *
   * @param $database
   *   An array of driver specific configuration options.
   *
   * @return
   *   An array of driver configuration errors, keyed by form element name.
   */
  public function validateDatabaseSettings($database) {
    $errors = [];

    // Verify the table prefix.
    if (!empty($database['prefix']) && is_string($database['prefix']) && !preg_match('/^[A-Za-z0-9_.]+$/', $database['prefix'])) {
      $errors[$database['driver'] . '][prefix'] = t('The database table prefix you have entered, %prefix, is invalid. The table prefix can only contain alphanumeric characters, periods, or underscores.', ['%prefix' => $database['prefix']]);
    }

    return $errors;
  }

  /**
   * Translates a string to the current language or to a given language.
   *
   * @see \Drupal\Core\StringTranslation\TranslatableMarkup::__construct()
   */
  protected function t($string, array $args = [], array $options = []) {
    return new TranslatableMarkup($string, $args, $options);
  }

  /**
   * Determines if there is an active connection.
   *
   * @return bool
   *   TRUE if there is at least one database connection established, FALSE
   *   otherwise.
   */
  protected function isConnectionActive() {
    return Database::isActiveConnection();
  }

  /**
   * Returns the database connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection.
   */
  protected function getConnection() {
    return Database::getConnection();
  }

}

<?php

final class Ladder {
	protected $db;
	protected $options;

	public static $show_sql = FALSE;

	public function __construct($migrate_to, $simulate = FALSE) {
		$this->db = Database::factory();

		while ($this->db->next_database()) {
			$this->db->check_migrations_table();
			try {
				$this->migrate($migrate_to, $simulate);
			} catch (Exception $e) {
				echo "\nERROR: ", $e->getMessage(), "\n\n";
			}
		}
	}

	/**
	 * Check the version is at least the passed-in one.
	 * @param $version string Minimum version number required.
	 */
	public static function check_version_min($version) {
		// If our version is less than the requested, throw an exception.
		if (version_compare(LADDER_VERSION, $version, '<')) {
			throw new Exception(sprintf(
				'Failed version check. Required %s, but using %s.',
				$version,
				LADDER_VERSION
			));
		}
	}

	/**
	 * Find all migrations that haven't been applied and run them.
	 */
	public function migrate($migrate_to, $simulate = FALSE) {
		$current_migration = $this->db->get_current_migration();

		if ($migrate_to == $current_migration)
			throw new Exception('Already at migration '.$migrate_to);

		if ($migrate_to < $current_migration) {
			$method = 'down';
			$sort = 'rsort';
		} else {
			$method = 'up';
			$sort = 'sort';
		};

		$migration_rows = self::select(
			sprintf(
				'SELECT `migration` from `%s` ORDER BY `migration`',
				$this->db->get_migrations_table()
			),
			'migration'
		);
		$migration_files = glob(APPPATH.'migrations/*.php');

		if ($migrate_to == 99999)
			$migrate_to = 'latest';

		echo "\n", ucfirst($method), sprintf('grading `%s` from %d to %s', $this->db->name, $current_migration, $migrate_to), "\n";

		// Sort the items so to run them in order.
		$sort($migration_files);

		$this->db->show_sql = TRUE;

		foreach ($migration_files as $file_path) {
			$file_name = basename($file_path);
			list($migration_id, $migration_name) = explode('-', $file_name, 2);

			// Ignore invalid or 0 ids.
			if ((int) $migration_id === 0)
				continue; 

			// Don't run ones that we've not been told to...
			if ($method == 'up' AND ($migration_id > $migrate_to))
				continue;
			elseif ($method == 'down' AND (($migration_id <= $migrate_to) OR ($migration_id > $current_migration)))
				continue;

			// Skip migrations when upgrading that are already applied.
			if ($method == 'up' AND in_array((int) $migration_id, $migration_rows))
				continue;

			// Skip migrations when downgrading that were not previously applied to the db.
			if ($method == 'down' AND ! in_array((int) $migration_id, $migration_rows))
				continue;

			// Translate filename to classname.
			$migration_name = implode('_', array_map('ucfirst', explode('_', strtolower(substr($migration_name, 0, -4)))));
			$migration_name = $migration_name.'_Migration_'.$migration_id;

			if ($simulate === TRUE)
				echo '(simulated) ';
				
			echo "\t", $migration_name, '->', $method, "\n";

			sql::reset_defaults();

			try {
				$prefixed_method = '_'.$method;
				require_once($file_path);
				$mig = new $migration_name($this->db);
				if (! (bool) $simulate) {
					$mig->$prefixed_method();

					// Run the test method if there is one and we're meant to.
					global $params;
					if ($method === 'up' AND (bool) $params['run-tests'] AND method_exists($mig, 'test')) {
						$mig->execute();
						$mig->test();
					}
				}
				unset($mig);

				// Either the migration succeeded, or we're in simulate mode.
				if ($method == 'up') {
					$this->db->query(sprintf(
						'INSERT INTO `%s` SET `migration`=%d, `applied`=NOW()',
						$this->db->get_migrations_table(),
						$migration_id
					));
				} else {
					$this->db->query(sprintf(
						'DELETE FROM `%s` WHERE `migration`=%d',
						$this->db->get_migrations_table(),
						$migration_id
					));
				}
			} catch (Exception $e) {
				echo "\n\tERROR: ", $e->getMessage(), "\n";
			}
		}
	}


	public static function select($sql, $field = FALSE, $value = FALSE) {
		$res = Database::factory()->query($sql);
		
		if ($res === TRUE)
			throw new Exception('Invalid query for select: '.$sql);

		$rows = array();

		if ((bool) $field AND $value === FALSE) {
			// Single-value indexed array
			while ($row = mysql_fetch_object($res))
				$rows[] = $row->$field;
			return $rows;
		} elseif ((bool) $field AND (bool) $value) {
			// name => value pairing
			while ($row = mysql_fetch_object($res))
				$rows[$row->$field] = $row->$value;
			return $rows;
		} elseif (! (bool) $field AND (bool) $value) {
			// value => row pairing (id => object)
			while ($row = mysql_fetch_object($res))
				$rows[$row->$value] = $row;
			return $rows;
		} else {
			// Straight array.
			while ($row = mysql_fetch_object($res))
				$rows[] = $row;
			return $rows;
		}
	}
}

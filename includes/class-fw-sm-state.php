<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The migration state — one autoloaded-off option holding everything the runner
 * needs to pick the job back up in a fresh PHP process.
 *
 * There is at most ONE migration at a time. That is a deliberate constraint: two
 * concurrent migrations would contend for the same temporary tables and the same
 * archive, and the complexity of supporting them buys nothing a queue does not
 * already give us.
 *
 * Everything in here must survive serialization and mean the same thing in a
 * request that shares no memory with the one that wrote it. Keep it small, keep
 * it scalar, and never park an object handle in it.
 */
class FW_SM_State {

	const OPTION = 'fw_sm_state';

	/** @var array|null In-request cache so a slice doing twenty reads hits the DB once. */
	private static $cache = null;

	/**
	 * The shape of a fresh migration.
	 *
	 * @param string   $direction 'export' or 'import'.
	 * @param string[] $stages    Selected stage names, in run order.
	 * @param array    $options   Migration options (find/replace pairs, archive path, …).
	 *
	 * @return array
	 */
	public static function create( $direction, array $stages, array $options = [] ) {
		$stages[] = FW_SM_Stage::FINALIZE;

		$state = [
			'id'          => wp_generate_password( 12, false ),
			'direction'   => $direction,
			'status'      => 'running',
			'started_at'  => time(),
			'updated_at'  => time(),
			'started_by'  => get_current_user_id(),
			'error'       => '',
			'log'         => [],
			'options'     => $options,
			'initialized' => false,
			'total'       => [ 'processed_bytes' => 0, 'target_bytes' => 0 ],
			'stages'      => [],
			// Scratch space for whichever stage is mid-flight. Reset per stage.
			'cursor'      => [],
		];

		foreach ( array_values( array_unique( $stages ) ) as $stage ) {
			$state['stages'][] = [
				'stage'       => $stage,
				'initialized' => false,
				'processed'   => false,
				'total'       => [ 'processed_bytes' => 0, 'target_bytes' => 0 ],
			];
		}

		self::save( $state );

		return $state;
	}

	/**
	 * @return array|null Null when no migration exists.
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$state = get_option( self::OPTION, null );

		self::$cache = is_array( $state ) && ! empty( $state['id'] ) ? $state : null;

		return self::$cache;
	}

	/**
	 * @param array $state
	 *
	 * @return void
	 */
	public static function save( array $state ) {
		$state['updated_at'] = time();

		self::$cache = $state;

		// Never autoload: this can hold a few KB of cursor data and is only read
		// by the runner and the migration screen.
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Merge a partial update into the current state.
	 *
	 * @param array $changes
	 *
	 * @return array|null The updated state, or null when there is no migration.
	 */
	public static function update( array $changes ) {
		$state = self::get();

		if ( null === $state ) {
			return null;
		}

		$state = array_merge( $state, $changes );

		self::save( $state );

		return $state;
	}

	/**
	 * Append a line to the migration log.
	 *
	 * The log is a UI affordance and a support aid, not an audit trail — it is
	 * capped so a migration that retries a thousand times cannot grow the option
	 * without bound.
	 *
	 * @param string $message
	 *
	 * @return void
	 */
	public static function log( $message ) {
		$state = self::get();

		if ( null === $state ) {
			return;
		}

		$state['log'][] = [ 'at' => time(), 'message' => (string) $message ];

		if ( count( $state['log'] ) > 200 ) {
			$state['log'] = array_slice( $state['log'], -200 );
		}

		self::save( $state );
	}

	/**
	 * Is a migration currently running (as opposed to finished, failed or absent)?
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::get();

		return null !== $state && 'running' === $state['status'];
	}

	/**
	 * Mark the migration finished, failed or cancelled.
	 *
	 * @param string $status 'complete' | 'failed' | 'cancelled'
	 * @param string $error  Message, when failing.
	 *
	 * @return void
	 */
	public static function finish( $status, $error = '' ) {
		$state = self::get();

		if ( null === $state ) {
			return;
		}

		$state['status']      = $status;
		$state['error']       = (string) $error;
		$state['finished_at'] = time();

		self::save( $state );

		/**
		 * Fires once a migration has stopped, whatever the outcome.
		 *
		 * @param string $status 'complete' | 'failed' | 'cancelled'
		 * @param array  $state  The final migration state.
		 */
		do_action( 'fw_ext_site_migration_finished', $status, $state );
	}

	/**
	 * Drop the migration record entirely.
	 *
	 * @return void
	 */
	public static function clear() {
		self::$cache = null;
		delete_option( self::OPTION );
	}

	/**
	 * Read a value out of the per-stage scratch cursor.
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public static function cursor( $key, $default = null ) {
		$state = self::get();

		return $state['cursor'][ $key ] ?? $default;
	}

	/**
	 * Write values into the per-stage scratch cursor.
	 *
	 * @param array $values
	 *
	 * @return void
	 */
	public static function set_cursor( array $values ) {
		$state = self::get();

		if ( null === $state ) {
			return;
		}

		$state['cursor'] = array_merge( (array) $state['cursor'], $values );

		self::save( $state );
	}

	/**
	 * Empty the scratch cursor — called on every stage boundary so one stage
	 * cannot read a stale row pointer left by the last one.
	 *
	 * @return void
	 */
	public static function reset_cursor() {
		$state = self::get();

		if ( null === $state ) {
			return;
		}

		$state['cursor'] = [];

		self::save( $state );
	}
}

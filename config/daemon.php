<?php
/**
 * Example Taskdaemon Config
 *
 * Simple example config for TaskDaemon.
 *
 * This file is part of TaskDaemon.
 *
 * TaskDaemon is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * TaskDaemon is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with TaskDaemon.  If not, see <http://www.gnu.org/licenses/>.
 */
return array(
	'default' => array(
		// The instance to use when reconnecting to the database if the connection drops.
		'database_instance' => 'default',

		// Maximum number of tasks that can be executed at the same time (parallel)
		'max' => 4,

		// Sleep time (in microseconds) when there's no active task. Note that there is a floor for this value, cant set to 0.
		'sleep' => 5000000, // 5 seconds

		// Save the PID file in this location
		'pid_file' => sys_get_temp_dir() . '/TaskDaemon_default.pid',

		// Show debug messages?
		'debug' => FALSE,
	),
);
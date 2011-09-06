<?php
/**
 * Daemon class
 *
 * Provides the basics for launching daemons from the controller in cli mode.
 *
 * Require:
 * - PHP 5.3.0+
 * - PHP PCNTL (http://www.php.net/manual/en/book.pcntl.php)
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
class Daemon
{
	/**
	 * Whether or not to show debug messages
	 *
	 * @var bool
	 */
	static public $DEBUG = FALSE;

	/**
	 * Static class to launch the daemon a bit easier
	 *
	 * @param array $config
	 */
	static public function launch(Array $config=null)
	{
		// Get a new instance
		$inst = new self();

		// Now lets set some stuff
		$inst->_config = $config;

		// Now run the daemon
		$inst->run();
	}

	/**
	 * Check to see if a specific Process id (PID) is running
	 *
	 * @param int $pid
	 */
	static public function ProcessIsRunning($pid)
	{
		return file_exists("/proc/".$pid);
	}

	static public function ProcessKill($pid)
	{
		return posix_kill($pid, SIGTERM);
	}

	/**
	 * Holds the loaded config information.
	 *
	 * @var array
	 */
	public $_config = array();

	/**
	 * Flag to termainate the daemon process.
	 *
	 * @var bool
	 */
	protected $_sigterm = false;

	/**
	 * Array of childern currently running
	 *
	 * @var array
	 */
	protected $_pids = array();

	/**
	 * Queue of signals to be sent at a later time.
	 *
	 * @var array
	 */
	protected $_signalQueue=array();


	public function __construct()
	{
		ob_implicit_flush();
		ignore_user_abort(true);

		// Setup
		ini_set("max_execution_time", "0");
		ini_set("max_input_time", "0");
		set_time_limit(0);

		// Signal handler
		pcntl_signal(SIGCHLD, array($this, 'sig_handler'));
		pcntl_signal(SIGTERM, array($this, 'sig_handler'));

		// Disable any errors from coming into the echo.
		ini_set('display_errors', 'off');
		ini_set('log_errors', 'on');
		error_reporting(E_ALL);
	}

	public function run()
	{
		// At first start lets sleep.
		sleep(3);

//		try {
			// Loop until we are told to die.
			while (!$this->_sigterm)
			{
				// See if we are within our defined child limits.
				if(count($this->_pids) >= $this->_config['max'])
				{
					// Now lets pause.
					$this->iterate();
					continue; // Restart.
				}

				// Lets get the next task
				if(($task = Mongo_Queue::getNextTask(FALSE, FALSE)) !== false)
				{
					// Write log to prevent memory issues
					Kohana::$log->write();

					// Open the db connection
					$db_task = new Model_Queue($task['_id']);
					
					// Fork process to execute task
					$pid = pcntl_fork();

					if ($pid == -1) // We failed, hard
					{
						Kohana::$log->add(Log::CRITICAL, 'TaskDaemon: Could not spawn child task process.');
						Kohana::$log->write();
						exit(1);
					}
					elseif ($pid) // Parent so add the pid to the list
					{
						// Parent - add the child's PID to the running list
						$this->_pids[$pid] = time();

						// Lets flag this task as running with the proper pid.
						$db_task->pid = $pid;

						$db_task->save();

						// Close the db connection
						unset($db_task);

						/*// In the event that a signal for this pid was caught before we get here, it will be in our signalQueue array
			            // So let's go ahead and process it now as if we'd just received the signal
			            if(isset($this->signalQueue[$pid])){
							echo "found $pid in the signal queue, processing it now \n";
			                $this->sig_handler(SIGCHLD, $pid, $this->_signalQueue[$pid]);
			                unset($this->_signalQueue[$pid]);
			            }*/


						// Now lets pause.
						//$this->iterate(2000000);
					}
					else // We are child so lets do it!
					{
						// We need to detach from the master process and become our own master process.
						if (posix_setsid() == -1)
						{
						    Kohana::$log->add(Log::CRITICAL, 'TaskDaemon: Could not detach from terminal.');
						    Kohana::$log->write();
							exit(1);
						}

						Mongo_Queue::run($task);
						
						// We are done.
						unset($task);
						exit(0);
					}
				}
				else
				{
					// Lets not run the clean up all the time as it is not that important.
					if(date("i") % 10 == 0)
					{
						Kohana::$log->add(Log::NOTICE, "Clean up time!");
						Kohana::$log->write();
						
						// Check to make sure all tasks are still running
						Mongo_Queue::checkRunningTasks();

						// Clear out all the old, completed tasks.
						// Mongo_Queue::clearCompleted();

						// Remove any failed tasks from the task listing
						// Tasks::clearFailed();
					}
				}

				// Dispatch any signals, used instead of ticks=1.
				pcntl_signal_dispatch();

				// Now lets pause.
				$this->iterate();
			}

			// Loop has died so lets do some cleaning up.
			$this->clean();

			Kohana::$log->add(Log::NOTICE, "Taskdaemon exited!");

			// Write log to prevent memory issues
			Kohana::$log->write();

			exit(0);
		/*
		}
		catch (Exception $e)
		{
			Kohana::$log->add(Log::ERROR, 'TaskDaemon: '.$e->getCode().' msg: '. $e->getMessage());

			// Write log to prevent memory issues
			Kohana::$log->write();

			// Flag the task as ran, but with error.
			// Tasks::ranTask($task->task_id, true, $e->getMessage());

			exit(1);
		}
		*/
	}

	/*
	 * Signal handler. Handles kill & child died signal
	 */
	public function sig_handler($signo, $pid=null, $status=null)
	{
		/*//If no pid is provided, that means we're getting the signal from the system.  Let's figure out
        //which child process ended
        if(!$pid)
        {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        // Make sure we get all of the exited children
        while($pid > 0)
        {
            if($pid && isset($this->_pids[$pid]))
            {
                $exitCode = pcntl_wexitstatus($status);

                if($exitCode != 0)
                {
                    echo "$pid exited with status ".$exitCode."\n";
                }
                unset($this->_pids[$pid]);
            }
            elseif($pid)
            {
                //Oh no, our job has finished before this parent process could even note that it had been launched!
                //Let's make note of it and handle it when the parent process is ready for it
                echo "..... Adding $pid to the signal queue ..... \n";
                $this->_signalQueue[$pid] = $status;
            }

            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }*/


		switch ($signo)
		{
			case SIGCHLD:
				// Child died signal
				while(($pid = pcntl_wait($status, WNOHANG || WUNTRACED)) > 0)
				{
					// remove pid from list
					unset($this->_pids[$pid]);
				}
			break;

			case SIGTERM:
			case SIGKILL:
				// Kill signal
				$this->_sigterm = TRUE;
			break;

			default:
				if(self::$DEBUG)
				{
					Kohana::$log->add(Log::DEBUG, 'TaskDaemon: Sighandler '.$signo);
				}
				break;
		}
	}

	/*
	 * Performs clean up. Tries (several times if neccesary)
	 * to kill all children
	 */
	protected function clean()
	{
		$tries = 0;

		while ($tries++ < 10 && count($this->_pids))
		{
			$this->kill_all();
			sleep(1);
		}

		/*if (count($this->_pids))
		{
			Kohana::$log->add(Log::ERROR,'TaskDaemon: Could not kill all children');
			Kohana::$log->write();
		}*/

		// Now lets set all the tasks to not running since they are all dead now.
		$tasks = new Model_Queue();
		$db = $tasks->db();
		$job = $db->command(
			array(
				'findandmodify' => self::$collectionName,
				'query' => array('pid' => array('$gt' => 0)),
				'update' => array('$inc' => array('pid' => 0)), 
				'upsert' => true,
				'new' => true
			));

		// Remove PID file
		if(file_exists($this->_config['pid_file']))
		{
			unlink($this->_config['pid_file']);
		}
	}

	/*
	 * Tries to kill all running children
	 */
	protected function kill_all()
	{
		foreach ($this->_pids as $pid => $time)
		{
			self::ProcessKill($pid);
			usleep(1000);
		}

		return count($this->_pids) === 0;
	}

	/**
	 * Tell the loop to "sleep" for the specified time.  If not passed will use
	 * the defined sleep value from the config.
	 *
	 * @param int $ms
	 */
	protected function iterate($ms=null)
	{
		// Let's sleep on it.
		usleep(((!is_null($ms))?$ms:$this->_config['sleep']));

		clearstatcache();
		return true;
	}
}
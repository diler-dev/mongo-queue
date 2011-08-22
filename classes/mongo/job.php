<?php

abstract class Mongo_Job
{
	public static function later($delay = 0, $batch = false)
	{
		return self::at(time() + $delay, $batch);
	}

	public static function at($time = null, $batch = false)
	{
		if ($time === null) $time = time();
		$className = get_called_class();
		return new Mongo_Functor($className, $time, $batch);
	}

	public static function batchLater($delay = 0)
	{
		return self::later($delay, true);
	}

	public static function batchAt($time = null)
	{
		return self::at($time, true);
	}

	public static function run()
	{
		return Mongo_Queue::run(get_called_class());
	}

	public static function count()
	{
		return Mongo_Queue::count(get_called_class());
	}
}

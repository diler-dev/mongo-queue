<?php

abstract class Mongo_Queue
{
	public static $context = null;
	public static $collectionName = 'queue';

	public static function push($className, $methodName, $parameters, $when, $batch = false)
	{
		$task = new Model_Queue();
		
		if (!$batch)
		{
			$task->object_class = $className;
			$task->object_method = $methodName;
			$task->parameters = $parameters;
			$task->when = $when;
			$task->locked = NULL;
			$task->locked_at = NULL;
			$task->batch = 1;
			$task->save();
		}
		else
		{	
			$db = $task->db();
			
			$job = $db->command(
				array(
					'findandmodify' => self::$collectionName,
					'query' => array('object_class' => $className, 'object_method' => $methodName, 'parameters' => $parameters, 'locked' => null),
					'update' => array('$inc' => array('batch' => 1)), 
					'upsert' => true,
					'new' => true
				));
			
			if ($job['ok'])
			{
				$job = $job['value'];

				if (!isset($job['when']) || $job['when'] > $when)
				{
					$job['when'] = $when;
					$task->load_values($job, true);
					$task->save();
				}
			}
		}
	}

	public static function hasRunnable($class_name = null, $method_name = null)
	{
		$model = new Model_Queue();
		
		$query = array('when' => array('$lte' => time()), 'locked' => null);
	
		if ($class_name)
			$query['object_class'] = $class_name;

		if ($method_name)
			$query['object_method'] = $method_name;
	
		return ($model->load($query) != null);
	}

	public static function count()
	{
		$model = new Model_Queue();
		$collection = $model->collection();
		
		$query = array('when' => array('$lte' => time()), 'locked' => null);
		
		return $collection->count($query);
	}

	public static function run($class_name = null, $method_name = null)
	{
		$model = new Model_Queue();
		$db = $model->db();

		$query = array('when' => array('$lte' => time()), 'locked' => null);
	
		if ($class_name)
			$query['object_class'] = $class_name;
	
		if ($method_name)
			$query['object_method'] = $method_name;
	
		$job = $db->command(
			array(
				"findandmodify" => self::$collectionName,
				"query" => $query,
				"sort" => array('when' => 1),
				"update" => array('$set' => array('locked' => true, 'locked_at' => time()))
			));
		
		if ($job['ok'])
		{
			$jobRecord = $job['value'];
			$jobID = $jobRecord['_id'];

			// run the job
			if (isset($jobRecord['object_class']))
			{
				$className = $jobRecord['object_class'];
				$method = isset($jobRecord['object_method']) ? $jobRecord['object_method'] : 'perform';
				$parameters = isset($jobRecord['parameters']) ? $jobRecord['parameters'] : array();
				
				if (self::$context)
				{
					foreach (self::$context as $key => $value)
					{
						if (property_exists($className, $key)){
							$SomeStaticProperty = new ReflectionProperty($className, $key);
							$SomeStaticProperty->setValue($value);
						}
					}
				}
				
				call_user_func_array(array(new $className, $method), $parameters); 
			}

			// remove the job from the queue
			$model->collection()->remove(array('_id' => $jobID));

			return true;
		}

		return false;
	}
}
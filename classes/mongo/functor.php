<?php

class Mongo_Functor
{
	protected $when;
	protected $className = null;
	
	public function __construct($className, $when, $batch)
	{
		$this->className = $className;
		$this->when = $when;
		$this->batch = $batch;
	}
	public function __call($method, $args)
	{
		Mongo_Queue::push($this->className, $method, $args, $this->when, $this->batch);
	}
}


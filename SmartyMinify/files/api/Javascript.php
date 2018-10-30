<?php

/**
 * Javascript
 *
 * @copyright	2017 Polevik yurii
 * @author		 Polevik yurii
 *
 */
 

class Javascript extends Simpla
{
	
	protected $events = array();
	
	protected $gzip_level = null;
	
	protected $cache_dir = 'js/';
	
	protected $min_filesize = 2000;
	
	protected $order_num = 0;

	/*
	* Регистрация js фал(а|ов)
	* @param $id
	* @param $files
	* @param integer $priority
	*/
	public function add_files($id, $files, $priority=10)
	{
		$event = $this->get_event($id);
		
		foreach((array) $files as $path)
		{
			$file = trim($path, '/ ');
			$path = VQMod::modCheck($this->config->root_dir . $file);
			if(is_file($path))
				$event->data[$path] = (object) array('type'=>'file', 'time'=>filemtime($path), 'original'=>$file, 'event'=>$event->id);
		}

		if(!$event->data)
			return false;
		
		$event->priority = intval($priority);
		
		return $this->events[$event->id] = $event;
	}

	/*
	* Регистрация произвольного js кода
	* @param $id
	* @param string $code
	* @param integer $priority
	*/
	public function add_code($id, $code, $priority=10)
	{
		$event = $this->get_event($id);
		
		$code = trim(preg_replace('#^<script([^>]*?)>(.+)</script>$#is', '\\2', trim($code)));
		
		if(!trim($code))
			return false;

		$event->data[$code] = (object) array('type'=>'code', 'time'=>0, 'event'=>$event->id);
		$event->priority = intval($priority);
		
		return $this->events[$event->id] = $event;
	}
	
	/*
	* Отмена регистрации js фал(а|ов) или кода
	* @param $events
	*/
	public function unplug($events)
	{
		if(is_string($events))
			$events = array_map('trim', explode(',', $events));
		
		foreach((array) $events as $id)
			if($id) unset($this->events[$id]);
	}
	
	
	/*
	* Вывод js фал(а|ов) или кода
	* @param $event
	*/
	public function render($event_id=null, $minify=null, $combine=true)
	{
		
		if(is_null($minify))
			$minify = $this->config->minify_js;
		
		// тут может использоваться сжатие
		// проверим поддерживается ли оно браузером
		if(is_null($this->gzip_level))
		{
			$this->gzip_level = 0;
			
			// Создаем паку если не существует
			$this->cache_dir =  $this->config->minify_cache_dir . $this->cache_dir;
			if(!is_dir($this->config->root_dir . $this->cache_dir))
				mkdir($this->config->root_dir . $this->cache_dir, 0755, true);
				
			
			if($this->config->minify_gzip_level > 0 && isset($_SERVER['HTTP_ACCEPT_ENCODING']))
			{
				if (stripos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false)
				{
					if(function_exists('ob_gzhandler') && !ini_get('zlib.output_compression'))
					{
						$this->gzip_level = $this->config->minify_gzip_level;
					}
				}
			}
		}

		// Если заданы айди ресурсов отдадим только их
		$events = array();
		if(!is_null($event_id))
		{
			if(is_string($event_id))
				$event_id = array_map('trim', explode(',', $event_id));
			
			$events = array_intersect_key($this->events, array_fill_keys((array) $event_id, 0));
			$event_id = array_keys($events);
		}
		else
		{
			$events = $this->events;
		}


		if(count($events) > 1)
			uasort($events, array($this, 'sort_priority_callback'));
			
		$events_data = array();
		foreach($events as $ev)
			$events_data = array_merge($events_data, $ev->data);

		$this->unplug(array_keys($events));

		$result = '';
		
		//Если нет ничего для вывода
		if(empty($events_data))
			return $result;

		
		// Все выключено отдаем оригиналы 
		if(!$combine && !$minify)
		{
			foreach($events_data as $js=>$data)
			{
				if($data->type == 'code')
					 $result .= $this->render_tag($js);
				 else
					 $result .= $this->render_tag(false, $data->original);
			}
		}
		else // Что то включено
		{
			
			// Если не пакуем данные в 1 файл
			if(!$combine)
			{
				foreach($events_data as $js => $e)
				{
					if($e->type == 'code')
						$prefix = $e->event;
					else
						$prefix = pathinfo($e->original, PATHINFO_FILENAME);
					
					$result .= $this->proteced(array($js => $e), $prefix, $minify);
				}
			}
			else // Пакуем в все в 1 файл
			{
				
				$prefix = 'pack' . count($events_data);
				if(count($events_data)==1)
				{
					$e = reset($events_data);
					if($e->type == 'code')
						$prefix = $e->event;
					else
						$prefix = pathinfo($e->original, PATHINFO_FILENAME);
				}
				elseif(is_array($event_id))
				{
					if(count($event_id) <= 2)
						$prefix = implode('-', $event_id);
					else
						$prefix = 'events' . count($event_id);
				}
				$result = $this->proteced($events_data, $prefix, $minify);
			}
		}
		
	
		return $result;
	}

	
	
	protected function proteced($data, $prefix, $minify)
	{
		$min_ext = '.min';
		$subext = ($minify ? $min_ext:'');
		
		if(substr($prefix, -4) == $min_ext)
		{
			$subext = $min_ext;
			$prefix = substr($prefix, 0, -4);
		}

		list($cacheFile, $cachePath) = $this->get_cacheFile($data, $prefix, $subext);

		// Нет основного кеш-файла 
		if(!is_file($cachePath))
		{
			$content = $this->minify($data, $cachePath, $minify);
		}
		else
		{
			$content = false;
			if(filesize($cachePath) < $this->min_filesize)
				$content = file_get_contents($cachePath);
		}

		if($this->gzip_level && !$content)
			$cacheFile = $cacheFile.'.gz'.$this->gzip_level;
		
		return $this->render_tag($content, $cacheFile);
	}

	
	
	protected function get_event($event_id)
	{
		if(isset($this->events[$event_id]))
			return $this->events[$event_id];
		
		$event = new stdClass();
		$event->id = $event_id;			
		$event->data = array();			
		$event->order = $this->order_num++;			
		return $event;
	}
			

	
	public function render_tag($content, $js_file=null)
	{
		$tag = '<script type="text/javascript"';
		
		if($content)
		{
			$tag .= '>'.$content;
		}
		elseif(!is_null($js_file))
		{
			$baseurl = '/'; 
			if(is_string($this->config->minify_baseurl))
				$baseurl = ($this->config->minify_baseurl == 'hostname' ? $this->config->root_url . '/' : $this->config->minify_baseurl);

			$tag .= ' src="' . $baseurl . $js_file.'">';
		}
		else
		{
			return '';
		}
		
		return $tag . '</script>';
	}
	
	protected function minify($data, $cache, $minify)
	{
		// Используем сжатие
		if($minify)
		{
			require_once $this->config->root_dir . '/resize/MatthiasMullie/autoload.php';
			
			$minifier = new MatthiasMullie\Minify\JS(array_keys($data));
			$content = $minifier->minify($cache);
		}
		else // Просто клеим
		{
			$content = '';
			foreach($data as $js => $ev)
				$content .= ($ev->type=='file' ? file_get_contents($js):$js);
				
			file_put_contents($cache, $content);
		}
		
		// Если контент меньше min_filesize то отдаем его в html
		if(strlen($content) > $this->min_filesize)
			$content = false;

		// Возвращаем контент или false (если отдаем файлами)
		return $content;
	}

	/*
	* Формируем название кеш-файла исходя из параметров
	*/
	protected function get_cacheFile($data, $prefix, $subext='')
	{
		$key = $this->hash(var_export($data, 1));
		$cacheFile = $this->cache_dir . $prefix . '_' . $key . $subext . '.js';
		return array($cacheFile, $this->config->root_dir . $cacheFile);
	}
			
			
	/*
	* Хеширование строки
	*/
	protected function hash($str)
	{
		$hash = crc32($str);
		return ($hash>=0 ? $hash:sprintf('%u', $hash));
	}
		
		
	/* 
	* Сортируем массив по приоритету 
	*/
	public function sort_priority_callback($a, $b)
	{
		if ($a->priority == $b->priority)
			return ($a->order < $b->order) ? -1 : 1;

		return ($a->priority < $b->priority) ? 1 : -1;
	}
	

}

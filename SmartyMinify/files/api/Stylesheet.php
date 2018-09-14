<?php

/**
 * Stylesheet
 *
 * @copyright	2017 Polevik yurii
 * @author		 Polevik yurii
 *
 */
 

class Stylesheet extends Simpla
{
	
	protected $events = array();
	
	protected $gzip_level = null;
	
	protected $cache_dir = 'css/';
	
	protected $min_filesize = 256;
	
	protected $order_num = 0;
	
	// lessphp
	protected $less_used = false;
	protected $less_object = null;

	/*
	* Регистрация css фал(а|ов)
	* @param $id
	* @param $files
	* @param integer $priority
	*/
	public function add_files($id, $files, $priority=10, $less=false)
	{
		$event = $this->get_event($id);
		
		foreach((array) $files as $path)
		{
			$file = trim($path, '/ ');
			$path = VQMod::modCheck($this->config->root_dir . $file);
			if(is_file($path))
				$event->data[$path] = (object) array('type'=>'file', 'time'=>($less ? 0: filemtime($path)), 'original'=>$file, 'event'=>$event->id, 'less'=>$less);
		}

		if(!$event->data)
			return false;
		
		$event->priority = intval($priority);

		if($less)
			$this->less_used = true;
		
		return $this->events[$event->id] = $event;
	}

	/*
	* Регистрация произвольного css кода
	* @param $id
	* @param string $code
	* @param integer $priority
	*/
	public function add_code($id, $code, $priority=10, $less=false)
	{
		$event = $this->get_event($id);
		
		if(!$code = trim($code))
			return false;
		
		$event->data[$code] = (object) array('type'=>'code', 'time'=>0, 'event'=>$event->id, 'less'=>$less);
		$event->priority = intval($priority);
		
		if($less)
			$this->less_used = true;
		
		return $this->events[$event->id] = $event;
	}
	
	/*
	* Отмена регистрации css фал(а|ов) или кода
	* @param $id
	*/
	public function unplug($id)
	{
		unset($this->events[$id]);
	}
	
	
	/*
	* Вывод css фал(а|ов) или кода
	* @param $event
	*/
	public function render($event_id=null, $minify=null, $combine=true)
	{
		
		if(is_null($minify))
			$minify = $this->config->minify_css;
		
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

		// Если задан айди ресурса отдадим только его
		if(!is_null($event_id))
		{
			if(isset($this->events[$event_id]))
			{
				$events_data = $this->events[$event_id]->data;
				// Очищаем от повторного рендеринга
				$this->unplug($event_id);
			}
		}
		else
		{
			uasort($this->events, array($this, 'sort_priority_callback'));
			
			$events_data = array();
			foreach($this->events as $ev)
				$events_data = array_merge($events_data, $ev->data);
			
			// Очищаем от повторного рендеринга
			$this->events = array();
		}

		$result = '';
		
		//Если нет ничего для вывода
		if(empty($events_data))
			return $result;

		
		// Все выключено отдаем оригиналы 
		if(!$combine && !$minify)
		{
			foreach($events_data as $css=>$data)
			{
				if($data->less)
					$this->verify_less($css, $data);
				
				if($data->type == 'code')
					 $result .= $this->render_tag($css);
				 else
					 $result .= $this->render_tag(false, $data->original);
			}
		}
		else // Что то включено
		{
			
			// Если не пакуем данные в 1 файл
			if(!$combine)
			{
				foreach($events_data as $css => $e)
				{
					if($e->type == 'code')
						$prefix = $e->event;
					else
						$prefix = pathinfo($e->original, PATHINFO_FILENAME);
					
					$result .= $this->proteced(array($css => $e), $prefix, $minify);
				}
			}
			else // Пакуем в все в 1 файл
			{
				
				$prefix = 'pack';
				if(count($events_data)==1)
				{
					$e = reset($events_data);
					if($e->type == 'code')
						$prefix = $e->event;
					else
						$prefix = pathinfo($e->original, PATHINFO_FILENAME);
				}
				elseif(!is_null($event_id))
				{
					$prefix = $event_id;
				}
				$result = $this->proteced($events_data, $prefix, $minify);
			}
		}
		
	
		return $result;
	}

	
	
	protected function proteced($data, $prefix, $minify)
	{
		
		if($minify && substr($prefix, -4)!=='.min')
			$prefix .= '.min';

		list($cacheFile, $cachePath) = $this->get_cacheFile($data, $prefix);

		
		// Есть less. Проверим его кеши
		$less_verify = array();
		
		if($this->less_used)
		{
			$new_data = array();
			foreach($data as $css=>$_data)
			{
				if($_data->less)
					$less_verify[] = $this->verify_less($css, $_data);

				$new_data[$css] = $_data;
			}
			$data = $new_data;						
		}
		
		// Что то не так в кеше less. Обновим
		if(in_array(false, $less_verify) && is_file($cachePath))
			unlink($cachePath);
		
		// Нет основного кеш-файла 
		if(!is_file($cachePath))
		{
			$minifier = $this->get_minifier(array_keys($data), $minify);
			$content = $minifier->minify($cachePath);
			
			// Если контент меньше min_filesize то отдаем его в html
			if(strlen($content) > $this->min_filesize)
				$content = false;
			
			if($this->gzip_level && !$content)
				$cacheFile = $cacheFile.'.gz'.$this->gzip_level;
		}
		else
		{
			$content = false;
			if(filesize($cachePath) < $this->min_filesize)
				$content = file_get_contents($cachePath);
			elseif($this->gzip_level)
				$cacheFile = $cacheFile.'.gz'.$this->gzip_level;
		}

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
			

	
	/*
	* Обрабока less синтаксиса
	*/
	protected function verify_less(&$resource, &$data)
	{
		$valid = true;
		
		try {

			$key = $this->hash(var_export($data, 1));
		
			if($data->type == 'code')
				$prefix = $data->event;
			else
				$prefix = pathinfo($data->original, PATHINFO_FILENAME);
			
			list($outputFile, $outputPath) = $this->get_cacheFile($data, $prefix . '.less');
			
			$cachePath = $outputPath . '.cache';

			// нету кеша или конечного файла
			if(!is_file($outputPath) || !is_readable($cachePath) || !is_array($cache = @unserialize(file_get_contents($cachePath))))
			{
				$valid = false;
			}
			else
			{
				foreach ($cache['files'] as $fname => $ftime) 
				{
					if(!is_file($fname) or filemtime($fname) > $ftime)
					{
						$valid = false;
						break;
					}
				}
				
			}
			
			// Less изменился
			if(!$valid)
			{
				include_once $this->config->root_dir . '/resize/less/lessc.inc.php';
				$cache = lessc::cexecute($resource);
				
				if($data->type == 'file')
				{
					// Подменим путь к прикладным файлам
					$minifier = $this->get_minifier($cache['compiled'], false);
					$minifier->setRootSource($data->original);
					$cache['compiled'] = $minifier->minify($outputPath);
				}
				else
				{
					file_put_contents($outputPath, $cache['compiled']);
				}
				unset($cache['compiled']); // Для уменьшения размера кеша 
				file_put_contents($cachePath, serialize($cache));
			}
				
			$resource = $outputPath;
			$data->original = $outputFile;
		}
		catch (exception $e) 
		{
			trigger_error("Less error: " . $e->getMessage(), E_USER_ERROR);
			$valid = false;
		}

		return $valid;
	}
	
	
	
	
	
	/*
	* Получение MatthiasMullie minify object
	*/
	protected function get_minifier($data, $minify)
	{
		// Используем сжатие
		require_once $this->config->root_dir . '/resize/MatthiasMullie/autoload.php';
		
		if($minify)
		{
			$minifier = new MatthiasMullie\Minify\CSS($data);
			
			// Выключаем импорт прикладных файлов. Вскоре доработаю и их кеширвоание
			$minifier->setMaxImportSize(0);
			$minifier->setImportExtensions(array());
		}
		else // Просто клеим
			$minifier = new MatthiasMullie\Minify\CSSPacker($data);
			
		// Возвращаем контент	
		return $minifier;
	}

	
	
	/*
	* Формируем название кеш-файла исходя из параметров
	*/
	protected function get_cacheFile($data, $prefix)
	{
		$key = $this->hash(var_export($data, 1));

		$cacheFile = $this->cache_dir . $key . '_' . $prefix . '.css';
		return array($cacheFile, $this->config->root_dir . $cacheFile);
	}
			
			
			
	protected function render_tag($content, $css_file=null)
	{
		if($content)
		{
			return '<style type="text/css">' . $content . '</style>';
		}
		elseif(!is_null($css_file))
		{
			$baseurl = '/'; 
			if(is_string($this->config->minify_baseurl))
				$baseurl = ($this->config->minify_baseurl == 'hostname' ? $this->config->root_url . '/' : $this->config->minify_baseurl);
			
			return '<link href="' . $baseurl . $css_file . '" rel="stylesheet"/>';
		}
		
		return '';
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

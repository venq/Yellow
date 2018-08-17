<?php
	require_once('classes/database.php');
	require_once('classes/facebook.php');
	require_once('classes/googlemaps.php');
	require_once('classes/elastic.php');
	require_once('classes/page.php');
	require_once('classes/event.php');
	// require_once('classes/mobile_detect.php');
	require_once('classes/user.php');
	use GeoIp2\Database\Reader;
	
	class ui {
		private $ts;
		public $preffered_language = [];
		
		public $memcache, $ad = false;
		public $request, $pages = [], $url, $referer, $session, $geo, $host, $csrf, $term, $language, $language_ui;
		public $mysqli = [];
		// public $ismobile = false;
		public $amp = false;
		public $ampcompatible = false;
		public $now = null;
		public $session_require = false;
		// GM324%^vodka
		// https://yellow.place/ru/mastershield-paint-protection-window-tint-palm-desert-ca-usa
		// https://yellow.place/ru/emerson%01middle-school%03-enid-ok-usa
		final public function __construct($conf) {
			$this->conf = $conf;
			$this->ts = microtime(1);
			$this->time = (int)$this->ts;
			$this->month = date('m', $this->time);
			$this->year = date('Y', $this->time);
			$this->day = date('d', $this->time);
			$this->term = isset($_SERVER['TERM']); 									// флаг запуска с командной строки
			$this->ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
			$this->useragent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
			$this->host = isset($_SERVER['HTTP_HOST']) && strlen($_SERVER['HTTP_HOST'])<=128 ? mb_strtolower($_SERVER['HTTP_HOST']) : $this->conf->main_host;
			$this->request = $this->read_request($_REQUEST);
			if (($json_request = json_decode(file_get_contents('php://input'),TRUE)) && is_array($json_request)) $this->request = array_merge($this->request, $json_request);
			$this->memcache = new Memcache();
			if (!$this->memcache->addServer($this->conf->memcache->host,$this->conf->memcache->port)) die('Server is too busy now. Please try again later...');
			$this->db = new database($this);  // local
			$this->url = isset($_SERVER['REQUEST_URI']) ? rawurldecode($_SERVER['REQUEST_URI']) : '';
			// $pu = parse_url($this->url); $this->pages = preg_split("/[\/]/",trim(rawurldecode($pu['path']),'/'));
			$this->pages = preg_split("/\//", rawurldecode(trim($this->mb_parse_url($this->url),'/')));
			// print_r($_SERVER); $this->pages = preg_split("/[\/]/",trim(preg_replace('/[\?\&].+/','',$this->url),"/"));
			// print_r(parse_url($this->url, PHP_URL_PATH));
			// print_r($this->mb_parse_url($this->url));
			// print_r($this->pages);exit;
			if (preg_match('/\?.*?[<>]/i',rawurldecode($this->url))) { $this->log('xss', $this->url); exit;	} 			// XSS check
			$this->referer = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : '';
			$this->bot = preg_match('/(Googlebot|Yandex|Mail.Ru|Mail.RU_Bot|Yahoo|bingbot|AhrefsBot|coccocbot|SemrushBot|BLEXBot|proximic|GrapeshotCrawler|linkdexbot|AddThis.com|Mediapartners-Google|Sogou web spider)/', $this->useragent);
			$this->fromsearch = preg_match('/(google|yandex|bing|mail.ru|yahoo|coccocbot)/', $this->referer);
			$this->session_require = !$this->bot; // нужна ли сессия или нет
			$this->elastic = new elastic($this);
			$this->language = $this->conf->default_language;
			$this->facebook = new facebook($this);
			$this->googlemaps = new googlemaps($this);
			$this->page = new page($this);
			$this->event = new event($this);
			$this->session_check(isset($_COOKIE[$this->conf->session]) ? $_COOKIE[$this->conf->session] : false);
			$this->languages = $this->languages();
			$this->preffered_language = [$this->conf->default_language];
			
			if ($preffered_languages = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? preg_split('/[;,]/',$_SERVER['HTTP_ACCEPT_LANGUAGE']) : null) {
				foreach ($preffered_languages as $pl) {
					$pl = mb_strtolower(mb_substr($pl, 0, 2));
					if (isset($this->languages[$pl]) && $this->languages[$pl]->language_isactive == 1 && !in_array($pl, $this->preffered_language))
						$this->preffered_language[] = $pl;
				}
			}
			// $detect = new Mobile_Detect;
			// $this->ismobile = $detect->isMobile() && !$detect->isTablet();
			// $this->ampcompatible = $this->ismobile && ($detect->is('Chrome') || $detect->is('Firefox') || $detect->is('Edge') || $detect->is('Safari') || $detect->is('Opera'));
			// $this->amp = $this->conf->dev && $this->ismobile;
			$this->google_ads = !$this->conf->dev || $this->request('ads');
			/*
			if ($this->request('iddqd') == 'ok') {
				echo '<pre>';print_r($this->session);exit;
			}
			*/
			// '<pre>';print_r($this->session);exit;
			$this->country = isset($this->session['country']) ? $this->session['country'] : null;
			$this->city = isset($this->session['city']) ? $this->session['city'] : null;
			
			$this->user = new user($this);

		}
		
		final public function mb_parse_url($url) {
			$enc_url = preg_replace_callback(
					'%[^:/@?&=#]+%usD',
					function ($matches) {
							return urlencode($matches[0]);
					},
					$url
			);
			
			return parse_url($enc_url, PHP_URL_PATH);
    }
		
		final public function memget($f) {
			return $this->memcache->get($this->conf->session.$f);
		}
		
		final public function memgetversion($f) {
			return ($r = $this->memget($f)) && $r->data && $r->version == $this->conf->version ? $r : null;
		}
		
		final public function memset($f,$v,$t = 0) {
			return $this->memcache->set($this->conf->session.$f, $v, MEMCACHE_COMPRESSED, $t);
		}

		final public function memsetversion($f, $v) {
			$r = (object)[];
			$r->version = $this->conf->version;
			$r->data = $v;
			$this->memset($f, $r);
			return $v;
		}

		final public function memdel($f) {
			return $this->memcache->delete($this->conf->session.$f);
		}

		final public function __destruct() {
			if ($this->term)
				return;
			$this->session['language'] = $this->language;
			if (isset($this->session['session_id']) && !$this->bot) {
				$this->session['time'] = time();
				$this->memset("SESSION".$this->session['session_id'], $this->session, $this->conf->session_time);
				// $this->log('session', round((microtime(1) - $this->ts)*1000) . 'ms '.$this->useragent.' '.implode(',',$this->preffered_language).(isset($_COOKIE[$this->conf->session])&&$_COOKIE[$this->conf->session]?' HAVESESS':' NOSESS'), true);
			}
			$this->log('pagegen', round((microtime(1) - $this->ts)*1000) . 'ms '.$this->useragent.' '.implode(',',$this->preffered_language).(isset($_COOKIE[$this->conf->session])&&$_COOKIE[$this->conf->session]?' HAVESESS':' NOSESS'), true);
		}

		final public function session_delete() {
			if ($this->session && isset($this->session['session_id'])) {
				$this->memdel($this->session['session_id']);
				$this->session = NULL;
			}
		}

		final private function session_check($session_id) {
			if (!$this->session_require)
				return;
			if (!$session_id || mb_strlen($session_id) != 128 || (!$this->session = $this->memget("SESSION".$session_id)) || $this->session['ip'] != $this->ip) {
				$this->session = [];
				$session_id = hash("sha512",time().rand(0,100000));
				while ($this->memget("SESSION".$session_id))
					$session_id = $this->secret($this->hash(64));
				$this->session['session_id'] = $session_id;
				$this->session['ip'] = $this->ip;
				$this->session['csrf'] = $this->secret($this->hash(64));
				if (
					($geo = isset($_SERVER['REMOTE_ADDR']) ? $this->geo_reader($_SERVER['REMOTE_ADDR']) : null)
					&&
					isset($geo->location->latitude)
					&&
					isset($geo->location->longitude)
					&&
					($nearest = $this->elastic->city_nearest_geo($geo))
				) {
					$this->session['city_id'] = $nearest->city_id;
					$this->session['country'] = $this->session['city'] = $this->session['city_id'] ? $nearest : null;
					$this->session['lat'] = $geo->location->latitude;
					$this->session['lon'] = $geo->location->longitude;					
				}
			}
			if ($this->session && !$this->term)
				setcookie($this->conf->session, $this->session['session_id'], time()+$this->conf->session_time, "/", $this->conf->cookie_domain);
		}
		
		final public function geo_reader($ip) {
			
			if (!isset($this->conf->geoip->reader) || !$this->conf->geoip->reader)
				return null;
			require_once 'classes/geoip2.phar';
			$reader = new Reader($this->conf->geoip->reader);
			$city = null;
			try {
				$city = $reader->city($ip);
			} catch(Exception $e) {
				$this->log('geo_reader', 'Cannot find IP in geo database: '.$ip);
			}			
			return $city;
		}
		
		final public function city_set($latitude, $longitude) {
			if (
				($nearest = $this->elastic->city_near($latitude, $longitude))
				&&
				isset($nearest->city_id)
				&&
				(
					!isset($this->session['city_id']) || $this->session['city_id'] != $nearest->city_id
				)
			) {
				$this->session['city_id'] = $nearest->city_id;
				$this->session['country'] = $this->session['city'] = $this->session['city_id'] ? $nearest : null;
			}
			$this->session['lat'] = $latitude;
			$this->session['lon'] = $longitude;
		}
		
		final public function secret($t) {
			return hash("sha512",$this->conf->secret.$t);
		}
		
		final public function languages () {
			if (!$languages = $this->memget('languages')) {
				$languages = $this->db->data('select * from `language` order by language_id', 'language_code');
				$this->memset('languages', $languages);
			}
			return $languages;
		}
		
		final public function preffered_language() {
			$preffered_language = $this->conf->default_language;
			
			if ($this->preffered_language)
				foreach ($this->preffered_language as $language)
					if ($language != $this->conf->default_language)	{
						$preffered_language = $language;
						break;
					}
			return $preffered_language;
		}
		
		final public function language_set($language) {
			if (
				isset($this->languages[$language])
				&&
				($l = $this->languages[$language])
			) {
				$this->language = $l->language_code;
				$this->language_id = $l->language_id;
				// setlocale(LC_TIME, $l->language_locale);
				// echo $l->language_locale . " " . strftime("%l L");exit;
				return true;
			}
			return false;
		}
		
		final public function available_on($language) {
			return $language != $this->language && in_array($language, $this->preffered_language);
		}
		
		final private function read_request($arr) {
			$request = [];
			foreach ($arr as $key=>$value) {
				if (is_array($value))
					$request[$key] = $this->read_request($value);
				else
					$request[$key] = stripslashes($value);
			}
			return $request;
		}
		
		final public function value($params, $r) {
			return isset($params[$r]) ? $params[$r] : '';
		}

		final public function request($r) {
			return isset($this->request[$r]) ? $this->request[$r] : '';
		}

		final public function params($a) {
			$p = '';
			if ($a) foreach ($a as $k=>$v)
				if ($v != "")
					$p .= ($p ? '&' : '?').$k.'='.rawurlencode($v);
			return $p;
		}
		
		final public function ft($s) {
			return htmlspecialchars($s);
			// $strHtml = html_entity_decode($strHtml,ENT_QUOTES, 'utf-8');
		}

		final public function pt($s) {
			return nl2br(htmlspecialchars($s));
		}
		
		final public function timeconvert($time, $from_timezone, $format = 'Y-m-d H:i:s', $to_timezone = 'UTC') {
			return
				$from_timezone && $to_timezone
				&&
				($dtzfrom = new DateTimeZone($from_timezone))
				&&
				($dtzto = new DateTimeZone($to_timezone))
				&&
				($dt =  DateTime::createFromFormat($format, $time, $dtzfrom))
				&&
				$dt->setTimezone($dtzto)
					? $dt
					: DateTime::createFromFormat($format, $time)
			;
		}
		
		final public function timelocaltoutc($time, $timezone, $format = 'Y-m-d H:i:s') {
			return $this->timeconvert($time, $timezone, $format, 'UTC');
		}
		
		final public function timeutctolocal($time, $timezone, $format = 'Y-m-d H:i:s') {
			return $this->timeconvert($time, 'UTC', $format, $timezone);
		}

		final public function fbtoformat($fb, $format = 'Y-m-d H:i:s') {
			return
				($dt = new DateTime())
				&&
				($t = strtotime($fb))
				&&
				$dt->setTimestamp($t)
					? $dt->setTimezone(new DateTimeZone('UTC'))->format($format)
					: null
			;
		}
		
		final public function now($timezone = 'UTC') {
			try {
				return $this->now ? $this->now : ($this->now = new DateTime('NOW', new DateTimeZone($timezone)));
			} catch(Exception $e) {
				$this->log('timezone', 'Bad timezone '.$timezone);
			}			
			return $this->now ? $this->now : ($this->now = new DateTime('NOW'));
		}

		final public function qt($d) {
			if ($dt = DateTime::createFromFormat('d.m.Y', $d))
				return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
			else
				return $this->ft($d);
		}

		final public function dt($d) {
			if ($dt = DateTime::createFromFormat('Y-m-d', $d, new DateTimeZone('UTC')))
				return $dt->setTimezone(new DateTimeZone($this->conf->timezone))->format('d.m.Y');
			else
				return $this->ft($d);
		}
		
		final public function dte($datetime) {
			return DateTime::createFromFormat('Y-m-d H:i:s', $datetime, new DateTimeZone('UTC'));
		}

		final public function st($d, $time = false, $today = false, $seconds = true) {
			if ($dt = $this->dte($d)) {
				if ($today) {
					$dt->setTimezone(new DateTimeZone($this->conf->timezone));
					$now = new DateTime();
					if (
						$now->format('d.m.Y') == $dt->format('d.m.Y')
					)
						return 'Šodien'.($time?$dt->format(' H:i'.($seconds?':s':'')):'');
					elseif (
						$now->add(DateInterval::createFromDateString('yesterday'))
						&&
						$now->format('d.m.Y') == $dt->format('d.m.Y')
					)
						return 'Vakar'.($time?$dt->format(' H:i'.($seconds?':s':'')):'');
					else
						return $dt->setTimezone(new DateTimeZone($this->conf->timezone))->format($time?'d.m.Y H:i'.($seconds?':s':''):'d.m.Y');
				} else
					return $dt->setTimezone(new DateTimeZone($this->conf->timezone))->format($time?'d.m.Y H:i'.($seconds?':s':''):'d.m.Y');
			} else
				return $this->ft($d);
		}

		final public function datediff($from, $to) {
			return 
				($f = $this->dte($from))
				&&
				($t = $this->dte($to))
				? $f->diff($t)
				: null
			;
		}
		
		final public function stf($d, $f = 'c') {
			if ($dt = DateTime::createFromFormat('Y-m-d H:i:s', $d, new DateTimeZone('UTC'))) {
					return $dt->setTimezone(new DateTimeZone($this->conf->timezone))->format($f);
			} else
				return $this->ft($d);
		}

		
		final public function nt ($n, $d = 2) {
			return number_format($n, $d, ',', ' ');
		}

		final public function msg($v, $language = false) {
			static $msg;
			$language = $language ? $language : $this->language;
			if (!$msg)
				include("msg.php");
			return isset($msg[$v][$language]) ? $msg[$v][$language] : (isset($msg[$v][$this->conf->default_language])?$msg[$v][$this->conf->default_language]:'#'.$v.'#');
		}
		
		final public function msgf($v) {
			static $msg;
			$args = func_get_args();array_shift($args);
			if (!$msg)
				include("msg.php");
			
			return isset($msg[$v][$this->language]) ? $msg[$v][$this->language]($args) : (isset($msg[$v][$this->conf->default_language]) ? $msg[$v][$this->conf->default_language]($args) :  '#'.$v.'#');
		}

		final public function tmpl_file($name, $vars = false, $language = false) {
			$f = 'tmpl_'.$name;
			return $this->conf->dev && isset($this->request['dump'])
				? '<pre>'.print_r($vars, true).'</pre>'
				: $this->tmpl_parse(
						(
							$this->conf->dev
								? file_get_contents($this->conf->dir.'/'.$name)
								: (($r = $this->memgetversion($f)) ? $r->data : $this->memsetversion($f, file_get_contents($this->conf->dir.'/'.$name)))
						),
						$vars,
						$language
					)
			;
		}

		final public function tmpl_parse($in, $vars = false, $language = false) {
			if (!$vars) return $in;
			return preg_replace(
				[
					'/\s*?<block:(.*?)>(.*?)\s*<\/block:(\\1)>\s*?/se',
					'/\s*?<if:([a-zA-Z_\-0-9]+?)>(.*?)<\/if:(\\1)>\s*?/se',
					'/\s*?<ifnot:([a-zA-Z_\-0-9]+?)>(.*?)<\/ifnot:(\\1)>\s*?/se',
					'/\s*?<if:([a-zA-Z_\-0-9]+?):(.*?):(.*?)>\s*?/se', // '/<if:(.+?):(.*?):(.*?)>/se',
					'/<var:(.*?)(?R)?>/se',
					'/<msg:(.*?)>/se'
				],
				[
					'$this->tmpl_parse_block(\'\\2\', $this->tmpl_vr($vars, \'\\1\'))',
					'$this->tmpl_if($vars, \'\\1\', \'\\2\', true)',
					'$this->tmpl_if($vars, \'\\1\', \'\\2\', false)',
					'$this->tmpl_ifstr($vars, \'\\1\', \'\\2\', \'\\3\')',
					'$this->tmpl_vr($vars, \'\\1\')',
					'$this->msg(\'\\1\', $language)'
				],
				$in
			);
		}

		final private function tmpl_vr($a, $b) { 
			return isset ($a[$b]) ? $a[$b] : "#var:$b#"; 
		}

		final private function tmpl_if($b, $if, $in, $i) {
			return
				($i && isset($b[$if]) && $b[$if])
				||
				(!$i && (!isset($b[$if]) || !$b[$if]))
					? $this->tmpl_parse(stripslashes($in), $b)
					: '';
		}
		
		final private function tmpl_ifstr($vars, $if, $t, $f) { 
			return (isset ($vars[$if]) && $vars[$if]==true) ? $t : $f; 
		}

		final public function tmpl_parse_block($in, $vars) {
			$in = stripslashes($in);
			$out = '';
			if (is_array($vars))
				if (count($vars))
					foreach ($vars as $v) if (is_array($v)) $out .= $this->tmpl_parse($in,$v); else; 
				elseif (!isset($vars[0]) || (isset($vars[0]) && $vars[0]!==false))
					return $in;
			return $out;
		}

		final public function body($p = []) {
			$section = isset($p['section']) ? $p['section'] : false;
			if (isset($this->session['csrf']))
				$p['meta'][] = [
					'name'		=> 'csrf',
					'content'	=> $this->ft($this->session['csrf'])
				];
			
			if (isset($p['base'])) {
				foreach ($this->languages as $l)
					if ($l->language_isactive == 1 && $l->language_code != $this->language)
						$p['linkrel'][] = [
							'rel'				=> 'alternate',
							'href'			=> '/'.$l->language_code.$p['base'],
							'hreflang'	=> $l->language_code
						];
			}

			$linkrel = null;
			if (isset($p['linkrel']) && is_array($p['linkrel']))
				foreach ($p['linkrel'] as $l)
					if (!isset($l['valid']) || $l['valid'])			
						$linkrel[] = [
							'rel' => $l['rel'],
							'media' => isset($l['media']) ? $l['media'] : false,
							'type' => isset($l['type']) ? $l['type'] : false,
							'title' => isset($l['title']) ? $l['title'] : false,
							'href' => isset($l['href']) ? $l['href'] : false,
							'hreflang' => isset($l['hreflang']) ? $l['hreflang'] : false
						];
			// echo '<pre>';print_r($p['linkrel']);exit;
			$meta = false;
			if (isset($p['meta']) && is_array($p['meta']))
				foreach ($p['meta'] as $l)
					if (!isset($l['valid']) || $l['valid'])
						$meta[] = [
							'name' => isset($l['name']) ? $l['name'] : false,
							'property' => isset($l['property']) ? $l['property'] : false,
							'itemprop' => isset($l['itemprop']) ? $l['itemprop'] : false,
							'content' => isset($l['content']) ? $l['content'] : false
						];
			$lang = null;
			// if ($this->preffered_language)
			foreach ($this->languages as $l)
				$lang[] = [
					'language_code' => $this->ft($l->language_code),
					'language_name' => $this->ft($l->language_name),
					'active'				=> $l->language_code == $this->language,
					'base'					=> isset($p['base']) && $p['base'] ? $p['base'] : ''
				];

			
			return $this->tmpl_file('tmpl/body.tmpl', [
				'dev'											=> $this->conf->dev,
				'host'										=> $this->conf->home,
				'q'												=> $this->ft($this->request('q')),
				'country'									=> $this->country,
				'country_slug'						=> $this->country ? $this->ft($this->country->country_slug) : '',
				'country_name'						=> $this->country ? $this->ft($this->country->country_name) : '',
				'language'								=> $this->ft($this->language),
				'title'										=> isset($p['title']) ? $this->ft($p['title']) : (isset($this->conf->default_title) ? $this->conf->default_title : ''),
				'linkrel'									=> $linkrel,
				'meta'										=> $meta,
				'style'										=> isset($p['style']) ? $p['style'] : false,
				'lang'										=> $lang,
				'body'										=> isset($p['body']) ? $p['body'] : '',
				'version'									=> $this->conf->version,
				'js'											=> isset($p['js']) && $p['js'],
				'recaptcha'								=> isset($p['recaptcha']) && $p['recaptcha'],
				'share'										=> isset($p['share']) && $p['share'],
				'google_ads'							=> $this->google_ads && isset($p['google_ads']) && $p['google_ads'],
				'google_analytic'					=> !$this->conf->dev,
				'year'										=> $this->ft($this->year),
				
				'user_isauthorized'				=> $this->user->authorized,
				'user_fullname'						=> $this->ft($this->user->user_fullname),
				'site_key'								=> isset($this->conf->recaptcha->site_key) ? $this->conf->recaptcha->site_key : '',
				
				'cover_src'								=> isset($p['cover_src']) ? $p['cover_src'] : false,
				'cookie_message'					=> !isset($this->session['cookie_accepted']) || !$this->session['cookie_accepted'],
			]);
		}

		final public function admin_body($p = []) {
			if (!$this->admin->authorized)
				$this->page403();
			else {
				$section = isset($p['section']) ? $p['section'] : false;
				return $this->tmpl_file('tmpl/a/body.tmpl', [
					'dev'											=> $this->conf->dev,
					'host'										=> $this->conf->home,
					'language'								=> $this->ft($this->language),
					'title'										=> isset($p['title']) ? $this->ft($p['title']) : (isset($this->conf->default_title) ? $this->conf->default_title : ''),
					'body'										=> isset($p['body']) ? $p['body'] : '',
					'version'									=> $this->conf->version,
					'admin_fullname'					=> $this->ft($this->admin->admin_fullname),
					'active_submit'						=> $section == 'submit',
					'active_suggest'					=> $section == 'suggest',
					'active_feedback'					=> $section == 'feedback',
					'active_abuse'						=> $section == 'abuse',
					'active_page'							=> $section == 'page',
					'active_group'						=> $section == 'group',
					'active_category'					=> $section == 'category',
					'active_country'					=> $section == 'country',
					'active_city'							=> $section == 'city',
					'active_admin'						=> $section == 'admin',
					'active_user'							=> $section == 'user',
					'active_claim'						=> $section == 'claim',
					'active_disavow'					=> $section == 'disavow',
					'active_stat'							=> $section == 'stat',
					'admin_issuper'						=> $this->admin->admin_issuper,
					'submit_new'							=> ($r = $this->db->row('select count(*) as cnt from `submit` where submit_isprocessed = 0')) ? $r->cnt : 0,
					'suggest_new'							=> ($r = $this->db->row('select count(*) as cnt from `suggest` where suggest_isprocessed = 0')) ? $r->cnt : 0,
					'feedback_new'						=> ($r = $this->db->row('select count(*) as cnt from `feedback` where feedback_isprocessed = 0')) ? $r->cnt : 0,
					'abuse_new'								=> ($r = $this->db->row('select count(*) as cnt from `abuse` where abuse_isprocessed = 0')) ? $r->cnt : 0,
				]);
			}
		}

		final public function redirect($url) {
			header("location: ".$url);
			$this->log('redirect',$url);
			exit;
		}
	  
		final public function hash($length = 12, $pattern = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ") {
			$len = strlen($pattern);
			$key = '';
			for($i=0;$i<$length;$i++){$key .= $pattern{rand(0,$len-1)};}
			return $key;
		}
	  
		final public function page300($url) {
			header("HTTP/1.1 300 Multiple Choices");
			header("Location: ".$url);
			$this->log('page300',$url);
			exit;
		}

		final public function page301($url) {
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: ".$url);
			$this->log('page301',$url);
			exit;
		}

		final public function page403() {
			$this->log('page403','',true);
			header("HTTP/1.1 403 Forbidden");
			echo $this->body([
				'search'				=> false,
				'body' => $this->tmpl_file('tmpl/403.tmpl', [
					'language'			=> $this->ft($this->language),
				])
			]);
			exit;
		}

		final public function page404() {
			$this->log('page404','',true);
			$group = null;
			header("HTTP/1.1 404 Not found");
			echo $this->body([
				'search'				=> false,
				'body' => $this->tmpl_file('tmpl/404.tmpl', [
					'language'			=> $this->ft($this->language),
					'group'					=> $group,
				])
			]);
			exit;
		}
		
		final public function ajax404() {
			$this->log('ajax404','',true);
			return $this->tmpl_file('tmpl/ajax/404.tmpl');
		}

		final public function ajax403() {
			$this->log('ajax403','',true);
			return $this->tmpl_file('tmpl/ajax/403.tmpl');
		}
		
		final public function api403() {
			return $this->api(['error_code'=>2, 'error' => 'Access denied']);
		}

		final public function api404() {
			return $this->api(['error_code'=>1, 'error' => 'Unkwnown api call']);
		}

		final public function page410() {
			$this->log('page410','',true);
			header("HTTP/1.1 410 Gone");
			echo $this->body([
				'search'				=> false,
				'body' => $this->tmpl_file('tmpl/410.tmpl', [
					'language'			=> $this->ft($this->language),
				])
			]);
			exit;
		}
		
		final public function log($source, $m = '', $server = false, $uri = true) {
			$file = $this->conf->tmp_dir.'/'.$source.'.'.date('Y.m.d',time());
			if (
				(
					($new = !file_exists($file))
					||
					is_writable($file)
				)
				&&
				($out = fopen($file,'a+'))
			) {
				fwrite($out,
					date('H:i:s',time()).
					($uri && !$this->term ? " " . $_SERVER['REMOTE_ADDR']." ".$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'] : "").
					" ".
					(isset($_SERVER["HTTP_REFERER"]) ? 'REFERER: ' . $_SERVER["HTTP_REFERER"] . ' ' : '').
					$m.
					"\n"
				);
				fclose($out);
				if ($new) $this->chmod($file);
			}
			return true;
		}
		
		final public function applog($l) {
			return $this->log('applog', $l, true);
		}
		
		final public function log_start($log_process) {
			return
				$this->db->query('delete from log where log_process = '.$this->db->format($log_process))
				&&
				$this->log_db(0, $log_process, "Started")
			;
		}
		
		final public function log_done($log_process) {
			return $this->log_db(0, $log_process, "Done");
		}
		
		final public function log_notice($log_process, $log, $district_id = false) {
			return $this->log_db(0, $log_process, $log, $district_id);
		}
		
		final public function log_warning($log_process, $log, $district_id = false) {
			return $this->log_db(1, $log_process, $log, $district_id);
		}

		final public function log_error($log_process, $log, $district_id = false) {
			return $this->log_db(2, $log_process, $log, $district_id);
		}
		
		final public function chmod($file) {
			if (!is_writable($file)) return false;
			if (is_dir($file)) {
				if (isset($this->conf->gid)) chgrp($file, $this->conf->gid);
				chmod($file, 0775);
				$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($file));
				foreach ($items as $file) {
					if (isset($this->conf->gid)) chgrp($file, $this->conf->gid);
						chmod($file, 0775);
				}
			} else {
				if (isset($this->conf->gid)) chgrp($file, $this->conf->gid);
				chmod($file, 0660);
			}
			return true;
		}
		
		final public function check_dir($target_directory, $rights = 0777) {
			$done = false;
			$dirs = explode('/', $target_directory);
			$dir = '';
			foreach ($dirs as $part) {
				$dir .= $part . '/';
				if (!is_dir($dir)) {
					if (strlen($dir)>0) {
						$old = umask(0);
						$done =
							mkdir($dir, $rights)
							&&
							(!isset($this->conf->gid) || chgrp($dir, $this->conf->gid))
						;
						umask($old);
						if (!$done)
							break;
					}
				} else
					$done = true;
			}
			return $done;			
		}
		
		final public function fwrite($file, $l, $new = false) {
			if (
				(
					!file_exists($file)
					||
					is_writable($file)
				)
				&&
				($out = fopen($file, $new ? 'w' : 'a'))
			) {
				$len = fwrite($out, $l);
				fclose($out);
				return $len;
			}
			return false;
		}

		
		final public function debug($m) {
			$this->log('debug',print_r($m, true), false, false);
		}
		
		function api ($out = []) {
			header('Content-Type: application/json; charset=utf-8');
			if (!isset($out['error_code'])) $out['error_code'] = (int)0;
			$api = json_encode($out, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
			$this->log('api',$_SERVER['REQUEST_URI']."\nRequest:\n" . print_r($this->request,true)."\nResponse:\n".$api);
			echo $api;
			exit(0);
		}

		final public function api_error($error_code, $errors = []) {
			return $errors
				? $this->api(['error_code' => $error_code, 'error' => $this->msg('api_'.$error_code.'_error'), 'errors' => $errors])
				: $this->api(['error_code' => $error_code, 'error' => $this->msg('api_'.$error_code.'_error')])
			;
		}
		
		final public function plural($number, $one, $two, $five) {
			if (($number - $number % 10) % 100 != 10) {
				if ($number % 10 == 1) {
					$result = $one;
				} elseif ($number % 10 >= 2 && $number % 10 <= 4) {
					$result = $two;
				} else {
					$result = $five;
				}
			} else {
				$result = $five;
			}
			return $result;
		}
		
		final public function valid_csrf() {
			return 
				(isset($_SERVER['HTTP_X_CSRF_TOKEN']) && $_SERVER['HTTP_X_CSRF_TOKEN'] == $this->session['csrf'])
				||
				(isset($this->request['csrf']) && $this->request['csrf'] == $this->session['csrf'])
			;
		}
		
		function ucfirst($str, $all = false, $lower = false) {
			$str = $lower ? mb_strtolower($str) : $str;
			return $all ? preg_replace('/(\b\w)/seu','mb_strtoupper(\'\\1\')',$str) : preg_replace('/^(\b\w)/seu','mb_strtoupper(\'\\1\')',$str);
		}
		
		final public function pages($i) {
			return isset($this->pages[$i]) ? $this->pages[$i] : false;
		}
		
		final public function acronym($string, $max = 2) {
			$string = trim(preg_replace('/\(.*\)/','',$string));
			$acronym = "";
			if (mb_strlen($string)>0 && ($words = explode(" ", $string, $max))) {
				foreach ($words as $w)
					$acronym .= mb_substr($w,0,1);
			}
			return $this->ft(mb_strtoupper($acronym));
		}
		
		final public function filter($filters, $filter, $default = null, $request = true) {
			return isset($filters[$filter]) ? $filters[$filter] : ($request && isset($this->request[$filter]) ? $this->request[$filter] : $default);
		}

		final public function month($month) {
			return isset($this->conf->months->{$month}) ? $this->ft($this->conf->months->{$month}) : '???'.$month.'???';
		}
		
		final public function ymd($ny, $nm, $d) {
			$i = $ny*12 + $nm + $d - 1;
			$ny = floor($i/12);
			$nm = $i - $ny*12 + 1;
			return [$ny, $nm];
		}
		
		final public function reconnect() {
			foreach ($this->mysqli as &$m) {
				$m->connected = false;
			}
		}
		
		final public function validate_string_char($s, $len = 128, $numbers = true, $spaces = false) {
			return (int)$len>0 ? preg_match('/^[a-z\_\-'.($numbers?'0-9':'').($spaces?'\s':'').']{1,'.(int)$len.'}$/i',$s) : false;
		}
		
		final public function validate_string_char_all($s, $len = 128) {
			return (int)$len>0 ? preg_match('/^[ -~]{1,'.(int)$len.'}$/i',$s) : false;
		}
		
		final public function validate_float($v) {
			return filter_var($v, FILTER_VALIDATE_FLOAT);
		}
		
		final public function normalize_float($v) {
			return preg_replace('/,/','.', $v);
		}

		final public function validate_integer($v) {
			return filter_var($v, FILTER_VALIDATE_INT);
		}

		final public function validate_date($v, $format) {
			return ($d = DateTime::createFromFormat($format, $v)) && $d->format($format) == $v;
		}

		final public function validate_date_dmy($v) {
			return $this->validate_date($v, 'd.m.Y');
		}
		
		final public function validate_url($v) {
			return
				filter_var(idn_to_ascii($v), FILTER_VALIDATE_URL)
				||
				filter_var('http://'.idn_to_ascii($v), FILTER_VALIDATE_URL)
			;
		}
		
		final public function validate_string($s, $len = 128) {
			return mb_strlen($s) <= $len;
		}

		final public function validate_text($s) {
			return $this->validate_string($s, 65535);
		}

		final public function validate_email($v) {
			return $this->validate_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL);
		}
		
		final public function validate_phone($v) {
			return preg_match('/^\+?[\d\s-]{4,}$/i', $v);
		}

		final public function system_get($system_name) {
			return ($r = $this->db->row('select system_value from `system` where system_name = '.$this->db->format($system_name))) ? $r->system_value : null;
		}

		final public function system_set($system_name, $system_value) {
			return $this->db->query('insert into `system` (system_name, system_value) values ('.$this->db->format($system_name).', '.$this->db->format($system_value).') on duplicate key update system_value = '.$this->db->format($system_value));
		}
		
		final public function home() {
			return
				(isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https')
				.
				(isset($this->conf->home) ? ':' . $this->conf->home : '')
			;
		}
		
		final public function file_url($url) {
			return $this->home() . $this->conf->filedb->url . $url;
		}
		
		final public function markdown($text) {
			require_once("classes/parsedown.php");
			$parsedown = new Parsedown();
			return $parsedown->text($text);
		}
		
		final public function download($url, $file) {
			set_time_limit(0);
			$user_agent = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36';
			/*
			$ctx = stream_context_create([
				'http'=> [
					'timeout' 		=> 10,  // 10 seconds
					'user_agent'	=> $user_agent,
				],
				'https'=> [
					'timeout' => 10,  // 10 seconds
					'user_agent'	=> $user_agent,
				],
			]);
			$this->log('download','started: '.$url);
			$content = file_get_contents($url, false, $ctx);
			$this->log('download','done: '.$url);
			return $content ? $this->fwrite($file, $content) : null;
			*/
			// 
			
			if (!$fp = fopen ($file, 'w+'))
				return false;
			$ch = curl_init(str_replace(" ","%20",$url));
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_FILE, $fp); 
			curl_setopt($ch,CURLOPT_USERAGENT, $user_agent);
			// curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			
			// curl_setopt($ch, CURLOPT_VERBOSE, true);
			// curl_setopt($ch, CURLOPT_STDERR, fopen('php://stderr', 'w'));
			
			curl_exec($ch); 
			curl_close($ch);
			fclose($fp);
			return true;
			
		}
		
		final public function curl_get($url, $header = []) {
			$user_agent = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36';
			$ch = curl_init();
			
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			if ($header)
				curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			$body = curl_exec($ch); 
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$info = curl_getinfo($ch);
			$error = curl_errno($ch);
			curl_close($ch);
			return (object)[
				'code'	=> $code,
				'info'	=> $info,
				'body'  => $body
			];
		}
		
		final public function facebook_shares($url) {
			return
				($r = $this->curl_get('http://graph.facebook.com/?id='.rawurlencode($url), ['Accept: application/json']))
				&&
				$r->code == 200
				&&
				$r->body
				&&
				($j = json_decode($r->body))
				&&
				isset($j->share->share_count)
					? $j->share->share_count
					: null
			;
		}
		
		final public function place() {
			$q = $this->request('q');
			$city_slug = $this->request('city_slug');
			$country_slug = $this->request('country_slug');
			$place = '';
			if (
				$city_slug
				&&
				$country_slug
				&&
				($y = $this->page->country_get_by_slug($country_slug))
				&&
				($t = $this->page->city_get_by_slug($y->country_id, $city_slug))
			)
				$place = $t->city_name . ', ' . $y->country_name;
			return $place;
		}
		
		final public function distance() {
			return ($distance = $this->request('distance')) && in_array($distance, $this->conf->distance) ? $distance : $this->conf->distance_default;
		}
		
		final public function distance_out($d) {
			return round($d,2);
		}
		
		final public function price_range($pr) {
			if (preg_match('/^\$\$\$\$/',$pr))
				return $this->msg('price_range_very_expensive');
			elseif (preg_match('/^\$\$\$/',$pr))
				return $this->msg('price_range_expensive');
			elseif (preg_match('/^\$\$/',$pr))
				return $this->msg('price_range_normal');
			elseif (preg_match('/^\$/',$pr))
				return $this->msg('price_range_cheap');
			return '-';
		}
		
		final public function replace4byte($string) {
			return preg_replace('%(?:
						\xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
					| [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
					| \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
			)%xs', '', $string);    
		}
		
		final public function urlize($n) {
			return mb_strtolower(trim(preg_replace('/\-+/','-',preg_replace('/[\_\=\]\[\{\}\@\#\$\%\^\&\*\+\/\'\-\>\<\"\s\,\.\:\;\n\t\r\)\(\?\!\|\\\]+/','-',trim($n))),"-"));
		}
		
		
		final public function page_mask($page_id) {
			$s = floor($page_id/100000000);
			$d = floor(($page_id - $s*100000000)/10000);	
			return $s.'/'.$d;
		}
		
		final public function day_of_week($num) {
			return $this->msg('day_of_week_'.$num);
		}
		
		final public function month_short($num) {
			return $this->msg('month_short_'.$num);
		}
		
		final public function city_name($t) {
			return $t->city_name . ($t->city_state ? ' ('.$t->city_state.')' : '');
		}
		
	}
?>
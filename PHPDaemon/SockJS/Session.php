<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class Session {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var \PHPDaemon\Request\Generic */
	public $route;

	/** @var \PHPDaemon\Structures\StackCallbacks */
	public $onWrite;

	public $id;

	public $appInstance;


	/** @var array */
	public $buffer = [];
	
	/** @var bool */
	public $finished = false;

	/** @var bool */
	public $flushing = false;
	
	/** @var int */
	public $timeout = 60;
	public $server;


	protected $finishTimer;
	
	protected $timer;

	protected function toJson($m) {
		return json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * @param $route
	 * @param $appInstance
	 * @param $authKey
	 */
	public function __construct($appInstance, $id, $server) {
		$this->onWrite   = new StackCallbacks;
		$this->id     = $id;
		$this->appInstance = $appInstance;
		$this->server = $server;
		$this->finishTimer = setTimeout([$this, 'finishTimer'], $this->timeout * 1e6);
		
		$this->appInstance->subscribe('c2s:' . $this->id, [$this, 'c2s']);
		$this->appInstance->subscribe('poll:' . $this->id, [$this, 'poll']);
	}

	public function c2s($redis) {
		list (, $chan, $msg) = $redis->result;
		if ($msg === '') {
			return;
		}
		$this->onFrame($msg, \PHPDaemon\Servers\WebSocket\Pool::STRING);
	}

	public function onFrame($msg, $type) {
		$frames = json_decode($msg, true);
		if (!is_array($frames)) {
			return;
		}
		foreach ($frames as $frame) {
			$this->route->onFrame($frame, \PHPDaemon\Servers\WebSocket\Pool::STRING);
		}
	}

	public function poll($redis) {
		Timer::setTimeout($this->finishTimer, $this->timeout * 1e6); 
		$this->flush();
	}
	
	/**
	 * @TODO DESCR
	 */
	public function onHandshake() {
		$this->sendPacket('o');
		$this->route->onHandshake();
		$this->timer = setTimeout(function($event) {
			$this->sendPacket('h');
			$event->timeout();
		}, 15e6);
	}

	/**
	 * @TODO DESCR
	 */
	public function onWrite() {
		if ($this->finished) {
			return;
		}
		$this->onWrite->executeAll($this->route);
		if (method_exists($this->route, 'onWrite')) {
			$this->route->onWrite();
		}
	}

	/**
	 * @TODO DESCR
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->onFinish();
	}

	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
		$this->appInstance->unsubscribe('c2s:' . $this->id, [$this, 'c2s']);
		$this->appInstance->unsubscribe('poll:' . $this->id, [$this, 'poll']);
		if (isset($this->route)) {
			$this->route->onFinish();
		}
		unset($this->route);
		Timer::remove($this->finishTimer);
		$this->finishTimer = null;
	}

	/**
	 * @TODO DESCR
	 * @param $timer
	 */
	public function finishTimer($timer) {
		$this->finish();
	}

	/**
	 * Flushes buffered packets
	 * @return void
	 */
	public function flush() {
		if ($this->flushing) {
			return;
		}
		if (!$s = sizeof($this->buffer)) {
			return;
		}
		$this->flushing = true;
		$this->appInstance->publish(
			's2c:' . $this->id,
			$this->toJson($this->buffer),
			function($redis) use ($s) {
				$this->flushing = false;
				if ($redis->result === 0) {
					return;
				}
				if (sizeof($this->buffer) > $s) {
					$this->buffer = array_slice($this->buffer, $s);
					$this->flush();
				} else {
					$this->buffer = [];
				}

			}
		);
		$this->onWrite();
	}

	public function sendPacket($pct, $cb = null) {
		$this->buffer[] = $pct;
		if ($cb !== null) {
			$this->onWrite->push($cb);
		}
		$this->flush();
	}

	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = 0x00, $cb = null) {
		$this->sendPacket('a' . $this->toJson([$data]), $cb);
		return true;
	}

}
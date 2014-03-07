<?php

namespace TheFox\PhpChat;

use Exception;
use RuntimeException;

use Rhumsaa\Uuid\Uuid;
use Rhumsaa\Uuid\Exception\UnsatisfiedDependencyException;

use TheFox\Utilities\Hex;
use TheFox\Network\AbstractSocket;
use TheFox\Dht\Kademlia\Node;

class Client{
	
	const MSG_SEPARATOR = "\n";
	const NODE_FIND_NUM = 8;
	const NODE_FIND_MAX_NODE_IDS = 1024;
	
	private $id = 0;
	private $status = array();
	
	private $server = null;
	private $socket = null;
	private $node = null;
	private $ip = '';
	private $port = 0;
	
	private $recvBufferTmp = '';
	private $requestsId = 0;
	private $requests = array();
	private $actionsId = 0;
	private $actions = array();
	
	public function __construct(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		$this->status['hasShutdown'] = false;
		$this->status['isChannel'] = false;
		
		$this->status['hasId'] = false;
	}
	
	public function __destruct(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
	}
	
	public function setId($id){
		$this->id = $id;
	}
	
	public function getId(){
		return $this->id;
	}
	
	public function getStatus($name){
		if(array_key_exists($name, $this->status)){
			return $this->status[$name];
		}
		return null;
	}
	
	public function setStatus($name, $value){
		$this->status[$name] = $value;
	}
	
	public function setServer(Server $server){
		$this->server = $server;
	}
	
	public function getServer(){
		return $this->server;
	}
	
	public function setSocket(AbstractSocket $socket){
		$this->socket = $socket;
	}
	
	public function getSocket(){
		return $this->socket;
	}
	
	public function setNode(Node $node){
		$this->node = $node;
	}
	
	public function getNode(){
		return $this->node;
	}
	
	public function setIp($ip){
		$this->ip = $ip;
	}
	
	public function getIp(){
		if(!$this->ip){
			$this->setIpPort();
		}
		return $this->ip;
	}
	
	public function setPort($port){
		$this->port = $port;
	}
	
	public function getPort(){
		if(!$this->port){
			$this->setIpPort();
		}
		return $this->port;
	}
	
	public function setIpPort($ip = '', $port = 0){
		$this->getSocket()->getPeerName($ip, $port);
		$this->setIp($ip);
		$this->setPort($port);
	}
	
	public function getIpPort(){
		return $this->getIp().':'.$this->getPort();
	}
	
	public function setSslPrv($sslKeyPrvPath, $sslKeyPrvPass){
		$this->ssl = openssl_pkey_get_private(file_get_contents($sslKeyPrvPath), $sslKeyPrvPass);
	}
	
	public function getLocalNode(){
		if($this->getServer()){
			return $this->getServer()->getLocalNode();
		}
		return null;
	}
	
	public function getSettings(){
		if($this->getServer()){
			return $this->getServer()->getSettings();
		}
		
		return null;
	}
	
	private function getLog(){
		#print __CLASS__.'->'.__FUNCTION__.''."\n";
		
		if($this->getServer()){
			return $this->getServer()->getLog();
		}
		
		return null;
	}
	
	private function log($level, $msg){
		#print __CLASS__.'->'.__FUNCTION__.': '.$level.', '.$msg."\n";
		
		if($this->getLog()){
			if(method_exists($this->getLog(), $level)){
				$this->getLog()->$level($msg);
			}
		}
	}
	
	private function getTable(){
		if($this->getServer()){
			return $this->getServer()->getTable();
		}
		
		return null;
	}
	
	private function requestAdd($name, $rid, $data = array()){
		$this->requestsId++;
		
		$request = array(
			'id' => $this->requestsId,
			'name' => $name,
			'rid' => $rid,
			'data' => $data,
		);
		
		$this->requests[$this->requestsId] = $request;
		
		return $request;
	}
	
	private function requestGetByRid($rid){
		foreach($this->requests as $requestId => $request){
			if($request['rid'] == $rid){
				return $request;
			}
		}
		return null;
	}
	
	private function requestRemove($request){
		unset($this->requests[$request['id']]);
	}
	
	public function actionAdd(ClientAction $action){
		$this->actionsId++;
		
		$action->setId($this->actionsId);
		
		$this->actions[$this->actionsId] = $action;
	}
	
	private function actionsGetByCriterion($criterion){
		$rv = array();
		foreach($this->actions as $actionsId => $action){
			if($action->hasCriterion($criterion)){
				$rv[] = $action;
			}
		}
		return $rv;
	}
	
	public function actionRemove(ClientAction $action){
		unset($this->actions[$action->getId()]);
	}
	
	public function dataRecv(){
		$data = $this->getSocket()->read();
		
		do{
			$separatorPos = strpos($data, static::MSG_SEPARATOR);
			if($separatorPos === false){
				$this->recvBufferTmp .= $data;
				$data = '';
			}
			else{
				$msg = $this->recvBufferTmp.substr($data, 0, $separatorPos);
				
				$this->msgHandle($msg);
				
				$data = substr($data, $separatorPos + 1);
			}
		}while($data);
	}
	
	private function msgHandle($msgRaw){
		$msgRaw = base64_decode($msgRaw);
		$msg = json_decode($msgRaw, true);
		
		$msgName = '';
		$msgData = array();
		if($msg){
			$msgName = strtolower($msg['name']);
			if(array_key_exists('data', $msg)){
				$msgData = $msg['data'];
			}
		}
		else{
			$this->log('error', 'json_decode failed');
		}
		
		#print __CLASS__.'->'.__FUNCTION__.': '.$this->getIp().':'.$this->getPort().' raw: '.$msgRaw."\n";
		print __CLASS__.'->'.__FUNCTION__.': '.$msgName."\n";
		
		if($msgName == 'nop'){}
		elseif($msgName == 'hello'){
			if(array_key_exists('ip', $msgData)){
				$ip = $msgData['ip'];
				if($ip != '127.0.0.1' && strIsIp($ip) && $this->getSettings()){
					$this->getSettings()->data['node']['ipPub'] = $ip;
					$this->getSettings()->setDataChanged(true);
				}
			}
			
			$this->sendId();
		}
		elseif($msgName == 'id'){
			#print __CLASS__.'->'.__FUNCTION__.': '.$msgName.', '.(int)$this->getStatus('hasId')."\n";
			
			if($this->getTable()){
				if(!$this->getStatus('hasId')){
					$id = '';
					$port = 0;
					$strKeyPub = '';
					$strKeyPubFingerprint = '';
					$isChannel = false;
					if(array_key_exists('id', $msgData)){
						$id = $msgData['id'];
					}
					if(array_key_exists('port', $msgData)){
						$port = (int)$msgData['port'];
					}
					if(array_key_exists('sslKeyPub', $msgData)){
						$strKeyPub = base64_decode($msgData['sslKeyPub']);
						$strKeyPubFingerprint = Node::genSslKeyFingerprint($strKeyPub);
					}
					if(array_key_exists('isChannel', $msgData)){
						$isChannel = (bool)$msgData['isChannel'];
					}
					
					if($isChannel){
						$this->setStatus('isChannel', true);
					}
					
					$idOk = false;
					
					if(strIsUuid($id)){
						if($strKeyPub){
							
							$node = new Node();
							$node->setIdHexStr($id);
							$node->setIp($this->getIp());
							$node->setPort($port);
							$node->setTimeLastSeen(time());
							
							$node = $this->getTable()->nodeEnclose($node);
							
							if(! $this->getLocalNode()->isEqual($node)){
								if($node->getSslKeyPub()){
									$this->log('debug', 'found old ssl public key');
									
									if( $node->getSslKeyPub() == $strKeyPub ){
										$this->log('debug', 'ssl public key ok');
										
										$idOk = true;
									}
									else{
										$this->sendError(230, $msgName);
										$this->log('warning', 'ssl public key changed since last handshake');
									}
								}
								else{
									$sslPubKey = openssl_pkey_get_public($strKeyPub);
									if($sslPubKey !== false){
										$sslPubKeyDetails = openssl_pkey_get_details($sslPubKey);
										
										if($sslPubKeyDetails['bits'] >= Node::SSL_KEY_LEN_MIN){
											$this->log('debug', 'no old ssl public key found. good. set new.');
											
											$idOk = true;
										}
										else{
											$this->sendError(220, $msgName);
										}
									}
									else{
										$this->sendError(240, $msgName);
									}
								}
							}
							else{
								$this->sendError(120, $msgName);
							}
						}
						else{
							$this->sendError(200, $msgName);
						}
					}
					else{
						$this->sendError(900, $msgName);
					}
					
					if($idOk){
						$node->setSslKeyPub($strKeyPub);
						
						$this->setStatus('hasId', true);
						$this->setNode($node);
						
						$this->sendIdOk();
						
						$this->log('debug', $this->getIp().':'.$this->getPort().' recv '.$msgName.': '.$id.', '.$port.', '.$node->getSslKeyPubFingerprint());
					}
					
				}
				else{
					$this->sendError(110, $msgName);
				}
			}
			else{
				$this->sendError(910, $msgName);
			}
		}
		elseif($msgName == 'id_ok'){
			$this->log('debug', $this->getIp().':'.$this->getPort().' recv '.$msgName);
			
			if($this->getStatus('hasId')){
				$actions = $this->actionsGetByCriterion(ClientAction::CRITERION_AFTER_ID_OK);
				foreach($actions as $actionsId => $action){
					$this->actionRemove($action);
					$action->functionExec($this);
				}
			}
			else{
				$this->sendError(100, $msgName);
			}
		}
		elseif($msgName == 'node_find'){
			if($this->getStatus('hasId')){
				$rid = '';
				$num = static::NODE_FIND_NUM;
				$nodeId = '';
				if(array_key_exists('rid', $msgData)){
					$rid = $msgData['rid'];
				}
				if(array_key_exists('num', $msgData)){
					$num = $msgData['num'];
				}
				if(array_key_exists('nodeId', $msgData)){
					$nodeId = $msgData['nodeId'];
				}
				
				$this->log('debug', $this->getIp().':'.$this->getPort().' recv '.$msgName.': '.$rid.', '.$nodeId);
				
				if($nodeId){
					$node = new Node();
					$node->setIdHexStr($nodeId);
					
					if( $node->isEqual($this->getLocalNode()) ){
						$this->log('debug', 'node find: find myself');
						
						$this->sendNodeFound($rid);
					}
					elseif( !$node->isEqual($this->getNode()) && $onode = $this->getTable()->nodeFindInBuckets($node) ){
						$this->log('debug', 'node find: find in buckets');
						
						$this->sendNodeFound($rid, array($onode));
					}
					else{
						$this->log('debug', 'node find: closest to "'.$node->getIdHexStr().'"');
						
						$nodes = $this->getTable()->nodeFindClosest($node, $num);
						foreach($nodes as $cnodeId => $cnode){
							if($cnode->isEqual($this->getNode())){
								unset($nodes[$cnodeId]);
								break;
							}
						}
						
						$this->sendNodeFound($rid, $nodes);
					}
				}
			}
			else{
				$this->sendError(100, $msgName);
			}
		}
		elseif($msgName == 'node_found'){
			if($this->getStatus('hasId')){
				$rid = '';
				$nodes = array();
				if(array_key_exists('rid', $msgData)){
					$rid = $msgData['rid'];
				}
				if(array_key_exists('nodes', $msgData)){
					$nodes = $msgData['nodes'];
				}
				
				if($rid){
					$this->log('debug', $this->getIp().':'.$this->getPort().' recv '.$msgName.': '.$rid);
					
					$request = null;
					$request = $this->requestGetByRid($rid);
					if($request){
						$this->requestRemove($request);
						
						$nodeId = $request['data']['nodeId'];
						$nodesFoundIds = $request['data']['nodesFoundIds'];
						$distanceOld =   $request['data']['distance'];
						$ip = ''; $port = 0;
						
						if($nodes){
							// Find the smallest distance.
							foreach($nodes as $nodeArId => $nodeAr){
								
								$node = new Node();
								if(isset($nodeAr['id'])){
									$node->setIdHexStr($nodeAr['id']);
								}
								if(isset($nodeAr['ip'])){
									$node->setIp($nodeAr['ip']);
								}
								if(isset($nodeAr['port'])){
									$node->setPort($nodeAr['port']);
								}
								if(isset($nodeAr['sslKeyPub'])){
									$node->setSslKeyPub(base64_decode($nodeAr['sslKeyPub']));
								}
								$node->setTimeLastSeen(time());
								
								$distanceNew = $this->getLocalNode()->distanceHexStr($node);
								
								$this->log('debug', 'node found: '.$nodeArId.', '.$nodeAr['id'].', do='.$distanceOld.', dn='.$distanceNew);
								
								if(!$this->getLocalNode()->isEqual($node)){
									if($this->getSettings()->data['node']['ipPub'] != $node->getIp() || $this->getLocalNode()->getPort() != $node->getPort()){
										if(!in_array($node->getIdHexStr(), $nodesFoundIds)){
											
											$nodesFoundIds[] = $nodeAr['id'];
											if(count($nodesFoundIds) > static::NODE_FIND_MAX_NODE_IDS){
												array_shift($nodesFoundIds);
											}
											
											if($nodeAr['id'] == $nodeId){
												$this->log('debug', 'node found: find completed');
												$ip = ''; $port = 0;
											}
											else{
												if($distanceOld != $distanceNew){
													$distanceMin = Node::idMinHexStr($distanceOld, $distanceNew);
													if($distanceMin == $distanceNew){ // Is smaller then $distanceOld.
														$distanceOld = $distanceNew;
														$ip = $node->getIp(); $port = $node->getPort();
													}
												}
											}
											
											$this->getTable()->nodeEnclose($node);
										}
										else{
											$this->log('debug', 'node found: already known');
										}
									}
									else{
										$this->log('debug', 'node found: myself, ip:port equal ('.$node->getIp().':'.$node->getPort().')');
									}
								}
								else{
									$this->log('debug', 'node found: myself, node equal');
								}
							}
						}
						
						if($ip){
							// Further search at the nearest node.
							$this->log('debug', 'node found: ip ('.$ip.':'.$port.') ok');
							
							$clientActions = array();
							$action = new ClientAction(ClientAction::CRITERION_AFTER_ID_OK);
							$action->functionSet(function($client){ $client->sendNodeFind($nodeId, $distanceOld, $nodesFoundIds); });
							$clientActions[] = $action;
							
							$this->getServer()->connect($ip, $port, $clientActions);
						}
					}
				}
				else{
					$this->sendError(900, $msgName);
				}
			}
			else{
				$this->sendError(100, $msgName);
			}
		}
		elseif($msgName == 'ping'){
			$id = '';
			if(array_key_exists('id', $msgData)){
				$id = $msgData['id'];
			}
			$this->sendPong($id);
		}
		elseif($msgName == 'error'){
			$code = 0;
			$msg = '';
			$name = '';
			if(array_key_exists('msg', $msgData)){
				$code = (int)$msgData['code'];
			}
			if(array_key_exists('msg', $msgData)){
				$msg = $msgData['msg'];
			}
			if(array_key_exists('msg', $msgData)){
				$name = $msgData['name'];
			}
			
			$this->log('debug', $this->getIp().':'.$this->getPort().' recv '.$msgName.': '.$code.', '.$msg.', '.$name);
		}
		elseif($msgName == 'quit'){
			$this->shutdown();
		}
	}
	
	private function msgCreate($name, $data){
		$json = array(
			'name' => $name,
			'data' => $data,
		);
		return json_encode($json);
	}
	
	private function dataSend($msg){
		$msg = base64_encode($msg);
		$this->getSocket()->write($msg.static::MSG_SEPARATOR);
	}
	
	public function sendHello(){
		$data = array(
			'ip' => $this->getIp(),
		);
		$this->dataSend($this->msgCreate('hello', $data));
	}
	
	private function sendId($isChannel = false){
		if(!$this->getLocalNode()){
			throw new RuntimeException('localNode not set.');
		}
		
		$sslKeyPub = base64_encode($this->getLocalNode()->getSslKeyPub());
		
		$data = array(
			'id'        => $this->getLocalNode()->getIdHexStr(),
			'port'      => $this->getLocalNode()->getPort(),
			'sslKeyPub' => $sslKeyPub,
			'isChannel' => (bool)$isChannel,
		);
		$this->dataSend($this->msgCreate('id', $data));
	}
	
	private function sendIdOk(){
		$data = array(
		);
		$this->dataSend($this->msgCreate('id_ok', $data));
	}
	
	private function sendNodeFind($nodeId, $distance = 'ffffffff-ffff-4fff-bfff-ffffffffffff', $nodesFoundIds = array()){
		if(!$this->getTable()){
			throw new RuntimeException('table not set.');
		}
		
		$rid = (string)Uuid::uuid4();
		
		$this->requestAdd('node_find', $rid, array(
			'nodeId' => $nodeId,
			'distance' => $distance,
			'nodesFoundIds' => $nodesFoundIds,
		));
		
		$data = array(
			'rid'       => $rid,
			'num'       => static::NODE_FIND_NUM,
			'nodeId'    => $nodeId,
		);
		$this->dataSend($this->msgCreate('node_find', $data));
	}
	
	private function sendNodeFound($rid, $nodes = array()){
		if(!$this->getTable()){
			throw new RuntimeException('table not set.');
		}
		
		$nodesOut = array();
		foreach($nodes as $nodeId => $node){
			$nodesOut[] = array(
				'id' => $node->getIdHexStr(),
				'ip' => $node->getIp(),
				'port' => $node->getPort(),
				'sslKeyPub' => base64_encode($node->getSslKeyPub()),
			);
		}
		
		$data = array(
			'rid'       => $rid,
			'nodes'     => $nodesOut,
		);
		$this->dataSend($this->msgCreate('node_found', $data));
	}
	
	private function sendPing($id = ''){
		$data = array(
			'id' => $id,
		);
		$this->dataSend($this->msgCreate('ping', $data));
	}
	
	private function sendPong($id = ''){
		$data = array(
			'id' => $id,
		);
		$this->dataSend($this->msgCreate('pong', $data));
	}
	
	private function sendError($errorCode = 999, $msgName = ''){
		$errors = array(
			// 100-199: ID
			100 => 'You need to identify',
			110 => 'You already identified',
			120 => 'You are using my ID',
			
			// 200-399: SSL
			200 => 'SSL: no public key found',
			210 => 'SSL: you need a key with minimum length of '.Node::SSL_KEY_LEN_MIN.' bits',
			220 => 'SSL: public key too short',
			230 => 'SSL: public key changed since last handshake',
			240 => 'SSL: invalid key',
			
			// 900-999: Misc
			900 => 'Invalid data',
			910 => 'Invalid setup',
			999 => 'Unknown error',
		);
		
		if(!isset($errors[$errorCode])){
			throw new RuntimeException('Error '.$errorCode.' not defined.');
		}
		
		$data = array(
			'code'   => $errorCode,
			'msg' => $errors[$errorCode],
			'name' => $msgName,
		);
		$this->dataSend($this->msgCreate('error', $data));
	}
	
	public function shutdown(){
		if(!$this->getStatus('hasShutdown')){
			$this->setStatus('hasShutdown', true);
			
			$this->getSocket()->shutdown();
			$this->getSocket()->close();
			
			if($this->ssl){
				openssl_free_key($this->ssl);
			}
		}
	}
	
}

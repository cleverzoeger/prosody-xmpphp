<?php
/**
 * XMPPHP: The PHP XMPP Library
 * Copyright (C) 2008  Nathanael C. Fritz
 * This file is part of SleekXMPP.
 * 
 * XMPPHP is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * XMPPHP is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with XMPPHP; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category   xmpphp 
 * @package	XMPPHP
 * @author	 Nathanael C. Fritz <JID: fritzy@netflint.net>
 * @author	 Stephan Wentz <JID: stephan@jabber.wentz.it>
 * @author	 Michael Garvin <JID: gar@netflint.net>
 * @copyright  2008 Nathanael C. Fritz
 */

/** XMPPHP_XMLStream */
require_once dirname(__FILE__) . "/XMPP.php";

/**
 * XMPPHP Main Class
 * 
 * @category   xmpphp 
 * @package	XMPPHP
 * @author	 Nathanael C. Fritz <JID: fritzy@netflint.net>
 * @author	 Stephan Wentz <JID: stephan@jabber.wentz.it>
 * @author	 Michael Garvin <JID: gar@netflint.net>
 * @copyright  2008 Nathanael C. Fritz
 * @version	$Id$
 */
class XMPPHP_BOSH extends XMPPHP_XMPP {

		protected $rid;
		protected $sid;
		protected $httpServer;
		protected $httpBuffer = Array();
		protected $session = false;

		public function connect($server, $wait='1', $session=false) {
			$this->httpServer = $server;
			$this->use_encryption = false;
			$this->session = $session;

			$this->rid = 3001;
			$this->sid = null;
			if($session)
			{
				$this->loadSession();
			}
			if(!$this->sid) {
				$body = $this->__buildBody();
				$body->addAttribute('hold','1');
				$body->addAttribute('to', $this->host);
				$body->addAttribute('route', "xmpp:{$this->host}:{$this->port}");
				$body->addAttribute('secure','true');
				$body->addAttribute('xmpp:version','1.6', 'urn:xmpp:xbosh');
				$body->addAttribute('wait', strval($wait));
				$body->addAttribute('ack','1');
				$body->addAttribute('xmlns:xmpp','urn:xmpp:xbosh');
				$buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
				xml_parse($this->parser, $buff, false);
				$response = $this->__sendBody($body);
				$rxml = new SimpleXMLElement($response);
				$this->sid = $rxml['sid'];

			} else {
				$buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
				xml_parse($this->parser, $buff, false);
			}
		}

		public function __sendBody($body=null, $recv=true) {
			if(!$body) {
				$body = $this->__buildBody();
			}
			$curlRessource = curl_init($this->httpServer);
			curl_setopt($curlRessource, CURLOPT_HEADER, 0);
			curl_setopt($curlRessource, CURLOPT_POST, 1);
			curl_setopt($curlRessource, CURLOPT_POSTFIELDS, $body->asXML());
			curl_setopt($curlRessource, CURLOPT_FOLLOWLOCATION, true);
			$header = array('Accept-Encoding: gzip, deflate','Content-Type: text/xml; charset=utf-8');
			curl_setopt($curlRessource, CURLOPT_HTTPHEADER, $header );
			curl_setopt($curlRessource, CURLOPT_VERBOSE, 0);
			$output = '';
			if($recv) {
				curl_setopt($curlRessource, CURLOPT_RETURNTRANSFER, 1);
				$output = curl_exec($curlRessource);
				$this->httpBuffer[] = $output;
			}
			curl_close($curlRessource);
			return $output;
		}

		public function __buildBody($sub=null) {
			$xml = new SimpleXMLElement("<body xmlns='http://jabber.org/protocol/httpbind' xmlns:xmpp='urn:xmpp:xbosh' />");
			$xml->addAttribute('content', 'text/xml; charset=utf-8');
			$xml->addAttribute('rid', $this->rid);
			$this->rid += 1;
			if($this->sid) $xml->addAttribute('sid', $this->sid);
			#if($this->sid) $xml->addAttribute('xmlns', 'http://jabber.org/protocol/httpbind');
			$xml->addAttribute('xml:lang', 'en');
			if($sub) { // ok, so simplexml is lame
				$domXML = dom_import_simplexml($xml);
				$domXMLSub = dom_import_simplexml($sub);
				$domXMLChild = $domXML->ownerDocument->importNode($domXMLSub, true);
				$domXML->appendChild($domXMLChild);
				$xml = simplexml_import_dom($domXML);
			}
			return $xml;
		}

		public function __process() {
			if($this->httpBuffer) {
				$this->__parseBuffer();
			} else {
				$this->__sendBody();
				$this->__parseBuffer();
			}
		}

		public function __parseBuffer() {
			while ($this->httpBuffer) {
				$idx = key($this->httpBuffer);
				$buffer = $this->httpBuffer[$idx];
				unset($this->httpBuffer[$idx]);
				if($buffer) {
					$xml = new SimpleXMLElement($buffer);
					$children = $xml->xpath('child::node()');
					foreach ($children as $child) {
						$buff = $child->asXML();
						$this->log->log("RECV: $buff",  XMPPHP_Log::LEVEL_VERBOSE);
						xml_parse($this->parser, $buff, false);
					}
				}
			}
		}

		public function send($msg) {
			$this->log->log("SEND: $msg",  XMPPHP_Log::LEVEL_VERBOSE);
			$msg = new SimpleXMLElement($msg);
			#$msg->addAttribute('xmlns', 'jabber:client');
			$this->__sendBody($this->__buildBody($msg), true);
			#$this->__parseBuffer();
		}

		public function reset() {
			$this->xml_depth = 0;
			unset($this->xmlobj);
			$this->xmlobj = array();
			$this->setupParser();
			#$this->send($this->stream_start);
			$body = $this->__buildBody();
			$body->addAttribute('to', $this->host);
			$body->addAttribute('xmpp:restart', 'true', 'urn:xmpp:xbosh');
			$buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
			#$response = $this->__sendBody($body);
			$this->been_reset = true;
			xml_parse($this->parser, $buff, false);
		}

		public function loadSession() {
			if(isset($_SESSION['XMPPHP_BOSH_RID'])) $this->rid = $_SESSION['XMPPHP_BOSH_RID'];
			if(isset($_SESSION['XMPPHP_BOSH_SID'])) $this->sid = $_SESSION['XMPPHP_BOSH_SID'];
			if(isset($_SESSION['XMPPHP_BOSH_authed'])) $this->authed = $_SESSION['XMPPHP_BOSH_authed'];
			if(isset($_SESSION['XMPPHP_BOSH_jid'])) $this->jid = $_SESSION['XMPPHP_BOSH_jid'];
			if(isset($_SESSION['XMPPHP_BOSH_fulljid'])) $this->fulljid = $_SESSION['XMPPHP_BOSH_fulljid'];
		}

		public function saveSession() {
			$_SESSION['XMPPHP_BOSH_RID'] = (string) $this->rid;
			$_SESSION['XMPPHP_BOSH_SID'] = (string) $this->sid;
			$_SESSION['XMPPHP_BOSH_authed'] = (boolean) $this->authed;
			$_SESSION['XMPPHP_BOSH_jid'] = (string) $this->jid;
			$_SESSION['XMPPHP_BOSH_fulljid'] = (string) $this->fulljid;
		}
}

<?php # $Id: ApiRequest.php 228 2013-08-29 11:38:45Z mkwayisi $

class Smsgh_ApiRequest {
	
	/**
	 * Data fields.
	 */
	private $username;
	private $password;
	private $hostname;
	private $port;
	private $protocol;
	private $timeout;
	
	private $method;
	private $uri;
	private $headers;
	
	private $fsock;
	private $error;
	
	/**
	 * Primary constructor.
	 */
	public function __construct(
		$hostname, $port, $protocol, $timeout, $username, $password)
	{
		$this->hostname = $hostname;
		$this->port = $port;
		$this->protocol = $protocol;
		$this->timeout = $timeout;
		$this->username = $username;
		$this->password = $password;
		$this->headers = array();
	}
	
	/**
	 * Gets or sets method.
	 */
	public function method($value = null) {
		if (is_null($value))
			return $this->method;
		else if (is_string($value))
			$this->method = $value;
		else throw new Smsgh_ApiException(
			"Parameter value must be of type 'string'");
		return $this;
	}
	
	/**
	 * Gets or sets URI.
	 */
	public function uri($value = null) {
		if (is_null($value))
			return $this->uri;
		else if (is_string($value))
			$this->uri = $value;
		else throw new Smsgh_ApiException(
			"Parameter value must be of type 'string'");
		return $this;
	}
	
	/**
	 * Gets or sets header.
	 */
	public function header($name, $value = null) {
		if (is_string($name)) {
			$name = strtolower($name);
			if (is_null($value))
				return isset($this->headers[$name]) ?
					$this->headers[$name] : null;
			else if (is_string($value)) {
				$this->headers[$name] = $value;
				return $this;
			}
		}
		throw new Smsgh_ApiException(
			"Both parameters' values must be of type 'string'");
	}
	
	#=================================================================]]
	
	/**
	 * Saves error.
	 */
	public function error($errno, $errstr, $file, $line) {
		$this->error = new Smsgh_ApiException($errstr);
	}
	
	/**
	 * Opens a socket connection.
	 */
	private function open() {
		if (!$this->fsock) {
			set_error_handler(array($this, 'error'));
			$this->fsock =
				fsockopen($this->protocol . '://' . $this->hostname,
					$this->port, $errno, $errstr, $this->timeout);
			restore_error_handler();
			
			if ($this->fsock) {
				stream_set_timeout($this->fsock, $this->timeout);
			} else {
				if (!$this->error) {
					if (!$errstr)
						$errstr = 'Unknown error';
					$this->error = new Smsgh_ApiException($errstr);
				}
				throw $this->error;
			}
		}
	}
	
	/**
	 * Closes a connection.
	 */
	private function close() {
		if ($this->fsock) {
			fclose($this->fsock);
			$this->fsock = null;
		}
	}
	
	/**
	 * Sends an HTTP request.
	 */
	public function send($body = null) {
		if (!(is_null($body) || is_string($body)))
			throw new Smsgh_ApiException(
				"Request body must be of type 'string'");
		
		if (!isset($this->headers['host']))
			$this->headers['host'] = $this->hostname;
		
		if (!isset($this->headers['authorization'])) {
			$this->headers['authorization'] = 'Basic '
				. base64_encode($this->username . ':' . $this->password);
		}
		
		if ($body)
			$this->headers['content-length'] = strlen($body);
		else {
			if (isset($this->headers['content-length']))
				unset($this->headers['content-length']);
			if (isset($this->headers['content-type']))
				unset($this->headers['content-type']);
		}
		
		$header = $this->method . ' ' . $this->uri . " HTTP/1.1\r\n";
		foreach ($this->headers as $name => $value)
			$header .= "$name: $value\r\n";
		
		$this->open();
		set_error_handler(array($this, 'error'));
		fwrite($this->fsock, "$header\r\n$body");
		restore_error_handler();
		
		if ($this->error) {
			$this->close();
			throw $this->error;
		}
		unset($header, $body);
		
		$body = null;
		$data = fgets($this->fsock);
		if ($data === false) {
			$this->close();
			throw new Smsgh_ApiException(
				"Remote host failed to respond");
		}
		
		// Check for presence of HTTP response line.
		$response = new Smsgh_ApiResponse;
		if (!strncmp($data, 'HTTP/', 5)) {
			$tokens = explode(' ', rtrim($data), 3);
			if (isset($tokens[1]))
				$response->status(intval($tokens[1]));
			if (isset($tokens[2]))
				$response->reason($tokens[2]);
		} else $body = true;
		
		do {
			// No headers were received.
			if ($body) {
				$data .= stream_get_contents($this->fsock);
				$body = null;
				break;
			}
			
			// We've got response headers.
			else do {
				$line = fgets($this->fsock);
				if ($line === false)
					break 2;
				$data .= $line;
				
				if ($line === "\r\n")
					break;
				
				$tokens = explode(':', $line, 2);
				if (isset($tokens[1]))
					$response->header($tokens[0], trim($tokens[1]));
			} while (!feof($this->fsock));
			
			// Check if the response data is of "regular" type.
			if (($length = $response->header('content-length')) !== null) {
				$length = intval($length);
				
				for (; $length > 0; $length -= strlen($chunk)) {
					$chunk = fread($this->fsock, min($length, 8192));
					if ($chunk === false)
						break;
					$body .= $chunk;
				}
				
				$data .= $body;
			}
			
			// Check if the response data is encoded.
			else if ($encoding = $response->header('transfer-encoding')) {
				switch (strtolower($encoding)) {
					
					// Chunked transfer encoding.
					case 'chunked':
						do {
							// Get length of chunked data.
							$length = fgets($this->fsock);
							if ($length === false)
								break;
							$data .= $length;
							$length = hexdec($length);
							
							static $var = 1;
							$var++;
							
							// Avoid a blocking call.
							if ($length == 0) {
								$chunk = fread($this->fsock, 2);
								if ($chunk !== false)
									$data .= $chunk;
								break;
							}
							
							// Read chunked data.
							for (; $length > 0; $length -= strlen($chunk)) {
								$chunk = fread(
									$this->fsock, min($length, 8192));
								if ($chunk === false)
									break 2;
								$data .= $chunk;
								$body .= $chunk;
							}
							
							// Read trailing CRLF.
							$chunk = fread($this->fsock, 2);
							if ($chunk === false)
								break;
							$data .= $chunk;
						} while (!feof($this->fsock));
						break;
					
					// Can we handle the other encoding types?
					default:
						throw new Smsgh_ApiException(
							'Unsupported transfer encoding type');
						break;
				}
			}
			
			// What kind of response data is this?
			else throw new Smsgh_ApiException('Bad response from server');
		} while (false);
		
		// Fill response properties.
		if ($body !== null)
			$response->body($body);
		$response->rawdata($data);
		$response->lock();
		
		// Close the connection if the remote host says so.
		if (trim(strtolower($response->header('Connection'))) == 'close')
			$this->close();
			
		return $response;
	}
	
	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}
}

<?php
/**
 * Classes e interfaces relacionadas com o protocolo HTTP
 * @package jonnydata\http
 */
namespace jonnydata\http;

use \InvalidArgumentException;
use \RuntimeException;
use \UnexpectedValueException;

/**
 * Requisição HTTP cURL. Implementação da interface HTTPRequest para uma
 * requisição HTTP que utiliza cURL.
 */
class CURL implements HTTPRequest {
	/**
	 * @var	resource
	 */
	private $curlResource;

	/**
	 * @var	HTTPConnection
	 */
	private $httpConnection;

	/**
	 * @var	HTTPResponse
	 */
	private $httpResponse;

	/**
	 * @var	boolean
	 */
	private $openned = false;

	/**
	 * @var	string
	 */
	private $requestBody;

	/**
	 * @var	array
	 */
	private $requestHeader = array();

	/**
	 * @var	array
	 */
	private $requestParameter = array();

	/**
	 * Destroi o objeto e fecha a requisição se estiver aberta.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * @see HTTPRequest::addRequestHeader()
	 */
	public function addRequestHeader($name, $value, $override = true) {
		if (is_scalar($name) && is_scalar($value)) {
			$key = strtolower($name);

			if ($override === true || !isset($this->requestHeader[$key])) {
				$this->requestHeader[$key] = array(
					'name' => $name,
					'value' => $value
				);

				return true;
			}

			return false;
		} else {
			throw new InvalidArgumentException('Name and value must be scalar');
		}
	}

	/**
	 * Autentica uma requisição HTTP.
	 * @param	HTTPAuthenticator $authenticator
	 * @see		HTTPRequest::authenticate()
	 */
	public function authenticate(HTTPAuthenticator $authenticator) {
		$authenticator->authenticate($this);
	}

	/**
	 * @see HTTPRequest::close()
	 */
	public function close() {
		if ($this->openned) {
			curl_close($this->curlResource);
			$this->openned = false;
		}
	}

	/**
	 * @see HTTPRequest::execute()
	 */
	public function execute($path = '/', $method = HTTPRequest::GET) {
		$targetURL = $this->httpConnection->getURI() . $path;
		$hasParameters = count($this->requestParameter) > 0;
		$query = $hasParameters ? http_build_query($this->requestParameter) : null;

		switch ($method) {
			case HTTPRequest::PUT :
			case HTTPRequest::POST :
				if ($method != HTTPRequest::POST) {
					curl_setopt($this->curlResource, CURLOPT_CUSTOMREQUEST, $method);
				} else {
					curl_setopt($this->curlResource, CURLOPT_POST, 1);
				}

				if (empty($this->requestBody)) {
					curl_setopt($this->curlResource, CURLOPT_POSTFIELDS, $query);
				} else {
					if ($hasParameters) {
						$targetURL .= '?' . $query;
					}

					curl_setopt($this->curlResource,
								CURLOPT_POSTFIELDS,
								$this->requestBody
								);
				}

				curl_setopt($this->curlResource, CURLOPT_URL, $targetURL);

				break;
			case HTTPRequest::DELETE :
			case HTTPRequest::HEAD :
			case HTTPRequest::OPTIONS:
			case HTTPRequest::TRACE:
				curl_setopt($this->curlResource, CURLOPT_CUSTOMREQUEST, $method);
			case HTTPRequest::GET:
				if ($hasParameters) {
					$targetURL .= '?' . $query;
				}

				curl_setopt($this->curlResource, CURLOPT_URL, $targetURL);

				break;
			default :
				throw new UnexpectedValueException( 'Unknown method.' );
		}

		$resp = curl_exec($this->curlResource);
		$errno = curl_errno($this->curlResource);
		$error = curl_error($this->curlResource);

		if ($errno != 0) {
			throw new RuntimeException($error, $errno);
		}

		$this->httpResponse = new HTTPResponse();
		$this->httpResponse->setRawResponse($resp);

		if ($this->httpResponse->hasResponseHeader('Set-Cookie')) {
			$cookieManager = $this->httpConnection->getCookieManager();

			if ($cookieManager != null) {
				$cookieManager->setCookie(
					$this->httpResponse->getHeader('Set-Cookie'),
					$this->httpConnection->getHostName()
				);
			}
		}

		$statusCode = $this->httpResponse->getStatusCode();

		return $statusCode < 400;
	}

	/**
	 * @see HTTPRequest::getResponse()
	 */
	public function getResponse() {
		return $this->httpResponse;
	}

	/**
	 * @see HTTPRequest::open()
	 */
	public function open(HTTPConnection $httpConnection) {
		if (function_exists('curl_init')) {
			/**
			 * Fechamos uma conexão existente antes de abrir uma nova
			 */
			$this->close();

			$curl = curl_init();

			/**
			 * Verificamos se o recurso CURL foi criado com êxito
			 */
			if (is_resource($curl)) {
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //FIXME
				curl_setopt($curl, CURLOPT_HEADER, true );
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true );
				curl_setopt($curl, CURLINFO_HEADER_OUT, true );

				if (($timeout = $httpConnection->getTimeout()) != null) {
					curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
				}

				if (($connectionTimeout = $httpConnection->getConnectionTimeout()) != null) {
					curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $connectionTimeout);
				}

				$headers = array();

				foreach ($this->requestHeader as $header) {
					$headers[] = sprintf('%s: %s',
										 $header['name'],
										 $header['value']);
				}

				curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

				$this->curlResource = $curl;
				$this->httpConnection = $httpConnection;
				$this->openned = true;
			} else {
				throw new RuntimeException('cURL failed to start');
			}
		} else {
			throw new RuntimeException('cURL not found.');
		}
	}

	/**
	 * Define um parâmetro que será enviado com a requisição, um parâmetro é um
	 * par nome-valor que será enviado como uma query string.
	 * @param	string $name Nome do parâmetro.
	 * @param	string $value Valor do parâmetro.
	 * @throws	InvalidArgumentException Se o nome ou o valor do campo não forem
	 * 			valores scalar.
	 * @see		HTTPRequest::setParameter()
	 */
	public function setParameter($name, $value) {
		$this->requestParameter[$name] = $value;
	}

	/**
	 * @see HTTPRequest::setRequestBody()
	 */
	public function setRequestBody($requestBody) {
		$this->requestBody = $requestBody;
	}
}
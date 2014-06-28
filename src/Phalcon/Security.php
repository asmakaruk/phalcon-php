<?php
/**
 * Security
 *
 * @author Andres Gutierrez <andres@phalconphp.com>
 * @author Eduar Carvajal <eduar@phalconphp.com>
 * @author Wenzel Pünter <wenzel@phelix.me>
 * @version 1.2.6
 * @package Phalcon
*/
namespace Phalcon;

use \Phalcon\DI\InjectionAwareInterface,
	\Phalcon\DiInterface,
	\Phalcon\Flash\Exception, //@note Look into the original code!
	\Phalcon\Security\Exception as SecException,
	\Phalcon\Filter,
	\Phalcon\Text,
	\Phalcon\Session\AdapterInterface as SessionAdapterInterface,
	\Phalcon\Http\RequestInterface;

/**
 * Phalcon\Security
 *
 * This component provides a set of functions to improve the security in Phalcon applications
 *
 *<code>
 *	$login = $this->request->getPost('login');
 *	$password = $this->request->getPost('password');
 *
 *	$user = Users::findFirstByLogin($login);
 *	if ($user) {
 *		if ($this->security->checkHash($password, $user->password)) {
 *			//The password is valid
 *		}
 *	}
 *</code>
 * 
 * @see https://github.com/phalcon/cphalcon/blob/1.2.6/ext/security.c
 */
class Security implements InjectionAwareInterface
{
	/**
	 * Dependency Injector
	 * 
	 * @var null|\Phalcon\DiInterface
	 * @var protected
	*/
	protected $_dependencyInjector;

	/**
	 * Work Factor
	 * 
	 * @var int
	 * @access protected
	*/
	protected $_workFactor = 8;

	/**
	 * Number of Bytes
	 * 
	 * @var int
	 * @access protected
	*/
	protected $_numberBytes = 16;

	/**
	 * CSRF
	 * 
	 * @var null
	 * @access protected
	*/
	protected $_csrf;

	/**
	 * Sets the dependency injector
	 *
	 * @param \Phalcon\DiInterface $dependencyInjector
	 * @throws Exception
	 */
	public function setDI($dependencyInjector)
	{
		if(is_object($dependencyInjector) === false ||
			$dependencyInjector instanceof DiInterface === false) {
			throw new Exception('Invalid parameter type.');
		}

		$this->_dependencyInjector = $dependencyInjector;
	}

	/**
	 * Returns the internal dependency injector
	 *
	 * @return \Phalcon\DiInterface|null
	 */
	public function getDI()
	{
		return $this->_dependencyInjector;
	}

	/**
	 * Sets a number of bytes to be generated by the openssl pseudo random generator
	 *
	 * @param int $randomBytes
	 * @throws Exception
	 */
	public function setRandomBytes($randomBytes)
	{
		if(is_int($randomBytes) === false) {
			throw new Exception('Invalid parameter type.');
		}

		if(count($randomBytes) < 16) {
			throw new SecException('At least 16 bytes are needed to produce a correct salt');
		}

		$this->_numberBytes = $randomBytes;
	}

	/**
	 * Returns a number of bytes to be generated by the openssl pseudo random generator
	 *
	 * @return int
	 */
	public function getRandomBytes()
	{
		return $this->_numberBytes;
	}

	/**
	 * Sets the default working factor for bcrypts password's salts
	 *
	 * @param int $workFactor
	 * @throws Exception
	 */
	public function setWorkFactor($workFactor)
	{
		if(is_int($workFactor) === false) {
			throw new Exception('Invalid parameter type.');
		}

		$this->_workFactor = $workFactor;
	}

	/**
	 * Returns the default working factor for bcrypts password's salts
	 *
	 * @return int
	 */
	public function getWorkFactor()
	{
		return $this->_workFactor;
	}

	/**
	 * Alphanumerical Filter
	 * 
	 * @param string $value
	 * @return string
	*/
	private static function filterAlnum($value)
	{
		$filtered = '';
		$value = (string)$value;
		$value_l = strlen($value);
		$zero_char = chr(0);

		for($i = 0; $i < $value_l; ++$i) {
			if($value[$i] == $zero_char) {
				break;
			}

			if(ctype_alnum($value[$i]) === true) {
				$filtered .= $value[$i];
			}
		}

		return $filtered;
	}

	/**
	 * Generate a >22-length pseudo random string to be used as salt for passwords
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getSaltBytes()
	{
		if(function_exists('openssl_random_pseudo_bytes') === false) {
			throw new SecException('Openssl extension must be loaded');
		}

		$safe_bytes = '';

		while(strlen($safe_bytes) < 22) {
			$random_bytes = openssl_random_pseudo_bytes($this->_numberBytes);

			//@note added check
			if($random_bytes === false) {
				throw new Exception('Error while generating random bytes.');
			}

			$base64bytes = base64_encode($random_bytes);

			$safe_bytes = self::filterAlnum($base64bytes);

			if(empty($safe_bytes) === true) {
				continue;
			}
		}

		return $safe_bytes;
	}

	/**
	 * Creates a password hash using bcrypt with a pseudo random salt
	 *
	 * @param string $password
	 * @param int|null $workFactor
	 * @return string
	 * @throws Exception
	 */
	public function hash($password, $workFactor = null)
	{
		if(is_string($password) === false) {
			throw new Exception('Invalid parameter type.');
		}

		if(is_null($workFactor) === true) {
			$workFactor = $this->_workFactor;
		} elseif(is_int($workFactor) === false) {
			throw new Exception('Invalid parameter type.');
		}

		$factor = sprintf('%02s', $workFactor);
		$salt_bytes = $this->getSaltBytes();
		$salt = '$2a$'.$factor.'$'.$salt_bytes;
		return crypt($password, $salt);
	}

	/**
	 * Checks a plain text password and its hash version to check if the password matches
	 *
	 * @param string $password
	 * @param string $passwordHash
	 * @param int|null $maxPasswordLength
	 * @return boolean|null
	 * @throws Exception
	 */
	public function checkHash($password, $passwordHash, $maxPasswordLength = null)
	{
		/* Type check */
		if(is_string($password) === false ||
			is_string($passwordHash) === false) {
			throw new Exception('Invalid parameter type.');
		}

		if(is_int($maxPasswordLength) === true) {
			if($maxPasswordLength > 0 && strlen($password) > $maxPasswordLength) {
				return false;
			}
		} elseif(is_null($maxPasswordLength) === false) {
			throw new Exception('Invalid parameter type.');
		}

		/* Hash */
		try {
			$hash = crypt($password, $passwordHash);
		} catch(\Exception $e) {
			return null;
		}

		if(is_string($hash) === false) {
			$hash = (string)$hash;
		}

		if(strlen($hash) === strlen($passwordHash)) {
			$n = strlen($hash);

			$check = false;
			for($i = 0; $i < $n; ++$i) {
				$check |= (ord($hash[$i])) ^ (ord($passwordHash[$i]));
			}

			return (bool)($check === 0 ? true : false);
		}

		return false;
	}

	/**
	 * Checks if a password hash is a valid bcrypt's hash
	 *
	 * @param string $passwordHash
	 * @return boolean
	 * @throws Exception
	 */
	public function isLegacyHash($passwordHash)
	{
		if(is_string($passwordHash) === false) {
			throw new Exception('Invalid parameter type.');
		}

		return (Text::startsWith($passwordHash, '$2a$'));
	}

	/**
	 * Generates a pseudo random token key to be used as input's name in a CSRF check
	 *
	 * @param int|null $numberBytes
	 * @return string
	 * @throws Exception
	 */
	public function getTokenKey($numberBytes = null)
	{
		if(is_null($numberBytes) === true) {
			$numberBytes = 12;
		} elseif(is_int($numberBytes) === false) {
			throw new Exception('Invalid parameter type.');
		}

		if(function_exists('openssl_random_pseudo_bytes') === false) {
			throw new SecException('Openssl extension must be loaded');
		}

		$random_bytes = openssl_random_pseudo_bytes($numberBytes);
		$base64bytes = base64_encode($random_bytes);
		$safe_bytes = self::filterAlnum($base64bytes);

		//@warning no length check for $safe_bytes

		if(is_object($this->_dependencyInjector) === false) {
			throw new Exception('A dependency injection container is required to access the \'session\' service');
		}

		$session = $this->_dependencyInjector->getShared('session');
		if(is_object($session) === false ||
			$session instanceof SessionAdapterInterface === false) {
			throw new Exception('Session service is unavailable.');
		}

		$session->set('$PHALCON/CSRF/KEY$', $safe_bytes);
	}

	/**
	 * Generates a pseudo random token value to be used as input's value in a CSRF check
	 *
	 * @param int|null $numberBytes
	 * @return string
	 * @throws Exception
	 */
	public function getToken($numberBytes = null)
	{
		if(is_null($numberBytes) === true) {
			$numberBytes = 12;
		} elseif(is_int($numberBytes) === false) {
			throw new Exception('Invalid parameter type.');
		}

		if(function_exists('openssl_random_pseudo_bytes') === false) {
			throw new SecException('Openssl extension must be loaded');
		}

		$random_bytes = openssl_random_pseudo_bytes($numberBytes);

		//@note MD5 is weak
		$token = md5($random_bytes);

		if(is_object($this->_dependencyInjector) === false) {
			throw new Exception('A dependency injection container is required to access the \'session\' service');
		}

		$session = $this->_dependencyInjector->getShared('session');
		if(is_object($session) === false ||
			$session instanceof SessionAdapterInterface === false) {
			throw new Exception('Session service is unavailable.');
		}

		$session->set('$PHALCON/CSRF$', $token);

		return $token;
	}

	/**
	 * Check if the CSRF token sent in the request is the same that the current in session
	 *
	 * @param string|null $tokenKey
	 * @param string|null $tokenValue
	 * @return boolean
	 * @throws Exception
	 */
	public function checkToken($tokenKey = null, $tokenValue = null)
	{
		/* Type check */
		if(is_null($tokenKey) === false && is_string($tokenKey) === false) {
			throw new Exception('Invalid parameter type.');
		}

		if(is_null($tokenValue) === false && is_string($tokenValue) === false) {
			throw new Exception('Invalid parameter type.');
		}

		/* Get session service */
		if(is_object($this->_dependencyInjector) === false) {
			throw new Exception('A dependency injection container is required to access the \'session\' service');
		}

		$session = $this->_dependencyInjector->getShared('session');
		if(is_object($session) === false ||
			$session instanceof SessionAdapterInterface === false) {
			throw new Exception('Session service is unavailable.');
		}

		/* Get token data */
		if(is_null($tokenKey) === true) {
			$tokenKey = $session->get('$PHALCON\CSRF\KEY$');
		}

		if(is_null($tokenValue) === true) {
			$request = $this->_dependencyInjector->getShared('service');

			if(is_object($request) === false ||
				$request instanceof RequestInterface === false) {
				throw new Exception('Request service is unavailable.');
			}

			//We always check if the value is correct in post
			$tokenValue = $request->getPost($tokenKey);
		}

		$session_token = $session->get('$PHALCON/CSRF$');

		//The value is the same?
		return ($tokenValue === $session_token ? true : false);
	}

	/**
	 * Returns the value of the CSRF token in session
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getSessionToken()
	{
		if(is_object($this->_dependencyInjector) === false) {
			throw new Exception('A dependency injection container is required to access the \'session\' service');
		}


		$session = $this->_dependencyInjector->getShared('session');
		if(is_object($session) === false ||
			$session instanceof SessionAdapterInterface === false) {
			throw new Exception('Session service is unavailable.');
		}

		return $session->get('$PHALCON/CSRF$');
	}
}
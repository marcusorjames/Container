<?php 
namespace ClanCats\Container;

use Closure;
use ClanCats\Container\{

	// exceptions
	Exceptions\UnknownServiceException,
	Exceptions\InvalidServiceException,
	Exceptions\ContainerException
};

class Container 
{
	/**
	 * The parameters
	 * 
	 * @var array
	 */
	protected $parameters = [];

	/**
	 * A mapping array to know how a service must be resolved.
	 * 
	 * @var array
	 */
	const RESOLVE_METHOD = 0;
	const RESOLVE_FACTORY = 1;
	const RESOLVE_SHARED = 2;
	const RESOLVE_SETTER = 3;
	protected $serviceResolverType = [];

	/**
	 * An array of methods that resolve a service inside the current container.
	 * This is mostly for the cached / dumped container which creates custom methods
	 * for each service to (try to) improve performance of the container.
	 * 
	 * @var array
	 */
	protected $resolverMethods = [];

	/**
	 * An array of factory callbacks 
	 * 
	 * @var array[ServiceFactoryInterface|Closure]
	 */
	private $resolverFactories = [];

	/**
	 * An array of factory callbacks that will be shared across the container.
	 * 
	 * @var array[ServiceFactoryInterface|Closure]
	 */
	private $resolverFactoriesShared = [];

	/**
	 * Array of already resolved shared factories
	 * 
	 * @var array
	 */
	protected $resolvedSharedServices = [];

	/**
	 * Construct a new service loader with a given cache directory
	 * 
	 * @param string 				$cacheDirectory
	 * @return void
	 */
	public function __construct(array $initalParameters = [])
	{
		$this->parameters = $initalParameters;
	}

	/**
	 * Does the container have the given parameters
	 * 
	 * @param string 			$name
	 * @return bool
	 */
	public function hasParameter(string $name) : bool
	{
		return array_key_exists($name, $this->parameters);
	}

	/**
	 * Get the given parameter with default
	 * 
	 * @param string 			$name
	 * @return mixed
	 */
	public function getParameter(string $name, $default = null)
	{
		return $this->hasParameter($name) ? $this->parameters[$name] : $default;
	}

	/**
	 * Set the given parameter with value
	 * 
	 * @param string 			$name
	 * @param mixed 			$value
	 * @return void
	 */
	public function setParameter(string $name, $value) : void
	{
		$this->parameters[$name] = $value;
	}

	/**
	 * Does the container have the given service
	 * 
	 * @param string 			$serviceName
	 */
	public function has(string $serviceName) : bool
	{
		return $serviceName === 'container' || isset($this->serviceResolverType[$serviceName]);
	}

	/**
	 * Simply sets a service on the shared container
	 * 
	 * @param string 			$serviceName
	 * @param mixed 			$serviceValue
	 */
	public function set(string $serviceName, $serviceValue) : void
	{
		if ($serviceName === 'container')
		{
			throw new ContainerException('Cannot overwrite self container reference!');
		}

		$this->resolvedSharedServices[$serviceName] = $serviceValue;
		$this->setServiceResolverType($serviceName, static::RESOLVE_SETTER);
	}

	/**
	 * Check if the given service has already been resolved
	 * 
	 * @param string 			$serviceName
	 * @return bool
	 */
	public function isResolved(string $serviceName) : bool
	{
		if ($serviceName === 'container') {
			return true; // the container itself is always resolved
		}

		if (!isset($this->serviceResolverType[$serviceName])) {
			return false; 
		}

		if ($this->serviceResolverType[$serviceName] === static::RESOLVE_FACTORY) {
			return false;
		}

		return isset($this->resolvedSharedServices[$serviceName]);
	}

	/**
	 * Release a shared resolved service 
	 * 
	 * @param string 				$serviceName
	 * @return bool
	 */
	public function release(string $serviceName) : bool
	{
		if (isset($this->resolvedSharedServices[$serviceName]))
		{
			unset($this->resolvedSharedServices[$serviceName]); return true;
		}

		return false;
	}

	/**
	 * Get a service by the given name
	 * 
	 * @param string 			$serviceName
	 * @return mixed
	 */
	public function get(string $serviceName)
	{
		// if the service container itself is requested
		if ($serviceName === 'container') { 
			return $this;
		}

		// check if the service name has a registered service type
		if (!isset($this->serviceResolverType[$serviceName]))
		{
			throw new UnknownServiceException('Could not find service named "' . $serviceName . '" registered in the container.');
		}
		$serviceResolverType = $this->serviceResolverType[$serviceName];

		// check if a service instance already exists
		if (
			isset($this->resolvedSharedServices[$serviceName]) && 
			$serviceResolverType !== static::RESOLVE_FACTORY
		) {
			return $this->resolvedSharedServices[$serviceName];
		}

		switch ($serviceResolverType)
		{
			// Default resolver for all services that are defined 
	 		// directly in the container. We can skip here an unnecessary method call.
			case static::RESOLVE_METHOD:
				return $this->{$this->resolverMethods[$serviceName]}();
			break;

			case static::RESOLVE_FACTORY:
				return $this->resolveServiceFactory($serviceName);
			break;

			case static::RESOLVE_SHARED:
				return $this->resolveServiceShared($serviceName);
			break;

			default:
				throw new UnknownServiceException('Could not resolve service named "' . $serviceName . '", the resolver type is unkown.');
			break;
		}
	}

	/**
	 * Resolve a service from the given factory
	 * 
	 * @param ServiceFactoryInterface|Closure 			$factory
	 * @return mixed
	 */
	private function resolveServiceFromFactory($factory)
	{	
		if ($factory instanceof ServiceFactoryInterface)
		{
			return $factory->create($this);
		}
		elseif ($factory instanceof Closure)
		{
			return $factory($this);
		}

		// otherwise throw an exception 
		throw new InvalidServiceException('Service could not be resolved, the registered factory is invalid.');
	}

	/**
	 * Resolver that will store the returned value for the next
	 * access.
	 * 
	 * @param string 			$method
	 */
	private function resolveServiceShared(string $name)
	{
		return $this->resolvedSharedServices[$name] = $this->resolveServiceFromFactory($this->resolverFactoriesShared[$name]);
	}

	/**
	 * Resolver that will simply return the factoryies value 
	 * 
	 * @param string 			$method
	 */
	private function resolveServiceFactory(string $name)
	{
		return $this->resolveServiceFromFactory($this->resolverFactories[$name]);
	}

	/**
	 * Binds a service factory to the container
	 * 
	 * @param
	 */
	public function bind(string $name, $factory, bool $shared = true) : void
	{
		if ($shared) {
			$this->bindSharedFactory($name, $factory);
		} else {
			$this->bindFactory($name, $factory);
		}
	}

	/**
	 * Binds a normal unshared factory to the service container
	 * 
	 * @param ServiceFactoryInterface|Closure 			$factory
	 * @return void
	 */
	public function bindFactory(string $name, $factory) : void
	{
		$this->setServiceResolverType($name, static::RESOLVE_FACTORY);
		$this->resolverFactories[$name] = $factory;
	}

	/**
	 * Binds a default shared factory to the service container
	 * 
	 * @param ServiceFactoryInterface|Closure 			$factory
	 * @return void
	 */
	public function bindSharedFactory(string $name, $factory) : void
	{
		$this->setServiceResolverType($name, static::RESOLVE_SHARED);
		$this->resolverFactoriesShared[$name] = $factory;
	}

	/**
	 * Add a service resolver type to the container. 
	 * The service resolver type tells the container where to look for the correct resolver.
	 * 
	 * @param string 			$serviceName
	 * @param int 				$serviceType
	 * @return void
	 */
	protected function setServiceResolverType(string $serviceName, int $serviceType) : void
	{
		$this->serviceResolverType[$serviceName] = $serviceType;
	}

	/**
	 * Get the resolver type of the given name
	 * 
	 * @param string 				$serviceName
	 * @return int
	 */
	public function getServiceResolverType(string $serviceName) : int
	{
		if (!isset($this->serviceResolverType[$serviceName]))
		{
			throw new UnknownServiceException('There is no type for the service named "' . $serviceName . '" specified.');
		}

		return $this->serviceResolverType[$serviceName];
	}
}	
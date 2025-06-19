<?php declare(strict_types = 1);

namespace Nettrine\DBAL\DI\Pass;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Context;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nettrine\DBAL\DI\DbalExtension;
use Nettrine\DBAL\DI\Helpers\BuilderMan;
use Nettrine\DBAL\DI\Helpers\Expecto;
use Nettrine\DBAL\DI\Helpers\SmartStatement;
use Nettrine\DBAL\Middleware\Debug\DebugMiddleware;
use Nettrine\DBAL\Middleware\Debug\DebugStack;
use Nettrine\DBAL\Tracy\ConnectionPanel;

/**
 * @phpstan-import-type TConnectionConfig from DbalExtension
 */
class ConnectionPass extends AbstractPass
{

	public function loadPassConfiguration(): void
	{
		$config = $this->getConfig();

		// Configure connections
		foreach ($config->connections as $connectionName => $connectionConfig) {
			$this->loadConnectionConfiguration($connectionName, $connectionConfig);
		}
	}

	public function beforePassCompile(): void
	{
		$config = $this->getConfig();

		// Configure connections
		foreach ($config->connections as $connectionName => $connectionConfig) {
			$this->beforeConnectionCompile($connectionName, $connectionConfig);
		}
	}

	/**
	 * @phpstan-param TConnectionConfig $connectionConfig
	 */
	public function loadConnectionConfiguration(string $connectionName, mixed $connectionConfig): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		// Validate connection configuration
		$processedConnectionConfig = $this->validateConnectionConfig($connectionName, $connectionConfig);

		// Configuration
		$configuration = $builder->addDefinition($this->prefix(sprintf('connections.%s.configuration', $connectionName)));
		$configuration->setFactory(Configuration::class)
			->setAutowired(false);

		// Configuration: schema assets filter
		if ($connectionConfig->schemaAssetsFilter !== null) {
			$configuration->addSetup('setSchemaAssetsFilter', [SmartStatement::from($connectionConfig->schemaAssetsFilter)]);
		}

		// Configuration: schema manager factory
		if ($connectionConfig->schemaManagerFactory !== null) {
			$configuration->addSetup('setSchemaManagerFactory', [SmartStatement::from($connectionConfig->schemaManagerFactory)]);
		}

		// Configuration: auto commit
		$configuration->addSetup('setAutoCommit', [$connectionConfig->autoCommit]);

		// Middlewares
		foreach ($connectionConfig->middlewares as $middlewareName => $middleware) {
			$builder->addDefinition($this->prefix(sprintf('connections.%s.middleware.%s', $connectionName, $middlewareName)))
				->setFactory($middleware)
				->addTag(DbalExtension::MIDDLEWARE_TAG, ['connection' => $connectionName, 'middleware' => $middlewareName])
				->setAutowired(false);
		}

		// Middlewares: debug
		if ($config->debug->panel) {
			$builder->addDefinition($this->prefix(sprintf('connections.%s.middleware.internal.debug.stack', $connectionName)))
				->setFactory(DebugStack::class, ['sourcePaths' => $config->debug->sourcePaths])
				->setAutowired(false);
			$builder->addDefinition($this->prefix(sprintf('connections.%s.middleware.internal.debug', $connectionName)))
				->setFactory(DebugMiddleware::class, [$this->prefix(sprintf('@connections.%s.middleware.internal.debug.stack', $connectionName)), $connectionName])
				->addTag(DbalExtension::MIDDLEWARE_INTERNAL_TAG, ['connection' => $connectionName, 'middleware' => 'debug'])
				->setAutowired(false);
		}

		// Connection
		$builder->addDefinition($this->prefix(sprintf('connections.%s.connection', $connectionName)))
			->setType(Connection::class)
			->setFactory($this->prefix('@connectionFactory') . '::createConnection', [
				$processedConnectionConfig,
				$this->prefix(sprintf('@connections.%s.configuration', $connectionName)),
				[],
			])
			->addTag(DbalExtension::CONNECTION_TAG, ['name' => $connectionName])
			->setAutowired($connectionName === 'default');
	}

	/**
	 * @phpstan-param TConnectionConfig $connectionConfig
	 */
	private function beforeConnectionCompile(string $connectionName, mixed $connectionConfig): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$configurationDef = $builder->getDefinition($this->prefix(sprintf('connections.%s.configuration', $connectionName)));
		assert($configurationDef instanceof ServiceDefinition);

		$connectionDef = $builder->getDefinition($this->prefix(sprintf('connections.%s.connection', $connectionName)));
		assert($connectionDef instanceof ServiceDefinition);

		// Configuration: result cache
		if ($connectionConfig->resultCache !== null) {
			$configurationDef->addSetup('setResultCache', [SmartStatement::from($connectionConfig->resultCache)]);
		}

		// Configuration: middlewares
		$configurationDef->addSetup('setMiddlewares', [BuilderMan::of($this)->getMiddlewaresBy($connectionName)]);

		// Connection: tracy panel
		if ($config->debug->panel) {
			$debugStackDef = $builder->getDefinition($this->prefix(sprintf('connections.%s.middleware.internal.debug.stack', $connectionName)));
			assert($debugStackDef instanceof ServiceDefinition);
			$connectionDef->addSetup(
				[ConnectionPanel::class, 'initialize'],
				[$debugStackDef, $connectionDef, $connectionName],
			);
		}
	}

	/**
	 * @return array<string, Structure>
	 */
	private function getDriverConfigSchema(): array
	{
		$shared = [
			'serverVersion' => Expect::string()->dynamic(),
			'wrapperClass' => Expect::string()->dynamic(),
			'defaultTableOptions' => Expect::arrayOf(Expect::mixed()->dynamic(), Expect::string()),
			'driverOptions' => Expect::array(),
			'replica' => Expect::arrayOf(
				Expect::arrayOf(
					Expect::scalar()->dynamic(),
					Expect::string()
				),
				Expect::string()->required()
			),
			'primary' => Expect::arrayOf(
				Expect::scalar()->dynamic(),
				Expect::string()
			),
		];

		return [
			'pdo_sqlite' => Expect::structure([
				'driver' => Expect::anyOf('pdo_sqlite'),
				'memory' => Expect::bool()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'path' => Expect::string()->dynamic(),
				'user' => Expect::string()->dynamic(),
				'host' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'sqlite3' => Expect::structure([
				'driver' => Expect::anyOf('sqlite3'),
				'memory' => Expect::bool()->dynamic(),
				'path' => Expect::string()->dynamic(),
				'host' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'pdo_mysql' => Expect::structure([
				'charset' => Expect::string()->dynamic(),
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('pdo_mysql'),
				'host' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'port' => Expecto::port(),
				'unix_socket' => Expect::string()->dynamic(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'mysqli' => Expect::structure([
				'charset' => Expect::string()->dynamic(),
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('mysqli'),
				'host' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'port' => Expecto::port(),
				'ssl_ca' => Expect::string()->dynamic(),
				'ssl_capath' => Expect::string()->dynamic(),
				'ssl_cert' => Expect::string()->dynamic(),
				'ssl_cipher' => Expect::string()->dynamic(),
				'ssl_key' => Expect::string()->dynamic(),
				'unix_socket' => Expect::string()->dynamic(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'pdo_pgsql' => Expect::structure([
				'application_name' => Expect::string()->dynamic(),
				'charset' => Expect::string()->dynamic(),
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('pdo_pgsql'),
				'gssencmode' => Expect::string()->dynamic(),
				'host' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'port' => Expecto::port(),
				'sslcert' => Expect::string()->dynamic(),
				'sslcrl' => Expect::string()->dynamic(),
				'sslkey' => Expect::string()->dynamic(),
				'sslmode' => Expect::string()->dynamic(),
				'sslrootcert' => Expect::string()->dynamic(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'pdo_oci' => Expect::structure([
				'charset' => Expect::string()->dynamic(),
				'connectstring' => Expect::string()->dynamic(),
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('pdo_oci'),
				'exclusive' => Expect::bool()->dynamic(),
				'host' => Expect::string()->dynamic(),
				'instancename' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'persistent' => Expect::bool()->dynamic(),
				'pooled' => Expect::bool()->dynamic(),
				'port' => Expecto::port(),
				'protocol' => Expect::string()->dynamic(),
				'service' => Expect::bool()->dynamic(),
				'servicename' => Expect::string()->dynamic(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'oci8' => Expect::structure([
				'charset' => Expect::string()->dynamic(),
				'connectstring' => Expect::string()->dynamic(),
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('oci8'),
				'exclusive' => Expect::bool()->dynamic(),
				'host' => Expect::string()->dynamic(),
				'instancename' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'persistent' => Expect::bool()->dynamic(),
				'pooled' => Expect::bool()->dynamic(),
				'port' => Expecto::port(),
				'protocol' => Expect::string()->dynamic(),
				'service' => Expect::bool()->dynamic(),
				'servicename' => Expect::string()->dynamic(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'pdo_sqlsrv' => Expect::structure([
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('pdo_sqlsrv'),
				'host' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'port' => Expecto::port(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'sqlsrv' => Expect::structure([
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('sqlsrv'),
				'host' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'port' => Expecto::port(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
			'ibm_db2' => Expect::structure([
				'dbname' => Expect::string()->dynamic(),
				'driver' => Expect::anyOf('ibm_db2'),
				'host' => Expect::string()->dynamic(),
				'password' => Expect::string()->dynamic(),
				'persistent' => Expect::bool()->dynamic(),
				'port' => Expecto::port(),
				'user' => Expect::string()->dynamic(),
				...$shared,
			])->castTo('array'),
		];
	}

	/**
	 * @phpstan-param TConnectionConfig $connectionConfig
	 * @return array<string, mixed>
	 */
	private function validateConnectionConfig(string $connectionName, mixed $connectionConfig): array
	{
		$config = (array) $connectionConfig;

		// Unset unrelevant configuration
		unset($config['middlewares']);
		unset($config['resultCache']);
		unset($config['schemaAssetsFilter']);
		unset($config['schemaManagerFactory']);
		unset($config['autoCommit']);
		unset($config['url']);

		// Filter out null values
		$config = array_filter($config, fn ($value) => $value !== null);

		$processor = new Processor();
		$processor->onNewContext[] = function (Context $context) use ($connectionName): void {
			$context->path = array_merge(['connections', $connectionName], $context->path);
		};

		/** @var array<string, mixed> $processed */
		$processed = $processor->process($this->getDriverConfigSchema()[$connectionConfig->driver], $config);

		return $processed;
	}

}

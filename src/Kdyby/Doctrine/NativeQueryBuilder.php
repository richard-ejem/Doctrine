<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Doctrine;

use Doctrine;
use Kdyby;
use Nette;
use Nette\Utils\ObjectMixin;



/**
 * @author Filip Procházka <filip@prochazka.su>
 *
 * @method NativeQueryBuilder setParameter($key, $value, $type = null)
 * @method NativeQueryBuilder setParameters(array $params, array $types = array())
 * @method NativeQueryBuilder setFirstResult($firstResult)
 * @method NativeQueryBuilder setMaxResults($maxResults)
 * @method NativeQueryBuilder select($select = NULL)
 * @method NativeQueryBuilder addSelect($select = NULL)
 * @method NativeQueryBuilder delete($delete = null, $alias = null)
 * @method NativeQueryBuilder update($update = null, $alias = null)
 * @method NativeQueryBuilder groupBy($groupBy)
 * @method NativeQueryBuilder addGroupBy($groupBy)
 * @method NativeQueryBuilder having($having)
 * @method NativeQueryBuilder andHaving($having)
 * @method NativeQueryBuilder orHaving($having)
 * @method NativeQueryBuilder orderBy($sort, $order = null)
 * @method NativeQueryBuilder addOrderBy($sort, $order = null)
 */
class NativeQueryBuilder extends Doctrine\DBAL\Query\QueryBuilder
{

	/**
	 * @var Mapping\ResultSetMappingBuilder
	 */
	private $rsm;

	/**
	 * @var Doctrine\ORM\EntityManager
	 */
	private $em;



	public function __construct(Doctrine\ORM\EntityManager $em)
	{
		parent::__construct($em->getConnection());
		$this->em = $em;
	}



	/**
	 * @return NativeQueryWrapper
	 */
	public function getQuery()
	{
		$query = new Doctrine\ORM\NativeQuery($this->em);
		$query->setResultSetMapping($this->getResultSetMapper());
		$query->setParameters($this->getParameters());

		$wrapped = new NativeQueryWrapper($query);
		$wrapped->setFirstResult($this->getFirstResult());
		$wrapped->setMaxResults($this->getMaxResults());

		$hasSelect = $this->getQueryPart('select') && $this->getType() === self::SELECT;
		if (!$hasSelect) {
			$select = $this->getResultSetMapper()->generateSelectClause();
			$this->select($select ?: '*');
		}

		$query->setSQL($this->getSQL());

		$this->setFirstResult($wrapped->getFirstResult());
		$this->setMaxResults($wrapped->getMaxResults());

		if (!$hasSelect) {
			$this->resetQueryPart('select');
		}

		$rsm = $this->getResultSetMapper();
		if (empty($rsm->fieldMappings) && empty($rsm->scalarMappings)) {
			throw new InvalidStateException("No field or columns mapping found, please configure the ResultSetMapper and some fields.");
		}

		return $wrapped;
	}



	public function getResultSetMapper()
	{
		if ($this->rsm === NULL) {
			$this->rsm = new Mapping\ResultSetMappingBuilder($this->em);
		}

		return $this->rsm;
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function from($from, $alias)
	{
		return parent::from($this->addTableResultMapping($from, $alias), $alias);
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function join($fromAlias, $join, $alias, $condition = null)
	{
		return call_user_func_array(array($this, 'innerJoin'), func_get_args());
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function innerJoin($fromAlias, $join, $alias, $condition = null)
	{
		if ($condition !== NULL) {
			list($condition) = array_values($this->separateParameters(array_slice(func_get_args(), 3)));
		}

		return parent::innerJoin($fromAlias, $this->addTableResultMapping($join, $alias, $fromAlias), $alias, $condition);
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function leftJoin($fromAlias, $join, $alias, $condition = null)
	{
		if ($condition !== NULL) {
			list($condition) = array_values($this->separateParameters(array_slice(func_get_args(), 3)));
		}

		return parent::leftJoin($fromAlias, $this->addTableResultMapping($join, $alias, $fromAlias), $alias, $condition);
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function rightJoin($fromAlias, $join, $alias, $condition = null)
	{
		if ($condition !== NULL) {
			list($condition) = array_values($this->separateParameters(array_slice(func_get_args(), 3)));
		}

		return parent::leftJoin($fromAlias, $this->addTableResultMapping($join, $alias, $fromAlias), $alias, $condition);
	}



	/**
	 * @param string $table
	 * @param string $alias
	 * @param string $joinedFrom
	 * @throws \Doctrine\ORM\Mapping\MappingException
	 * @return string
	 */
	protected function addTableResultMapping($table, $alias, $joinedFrom = NULL)
	{
		$rsm = $this->getResultSetMapper();
		$class = $relation = NULL;

		if (substr_count($table, '\\')) {
			$class = $this->em->getClassMetadata($table);
			$table = $class->getTableName();

		} elseif (isset($rsm->aliasMap[$joinedFrom])) {
			$fromClass = $this->em->getClassMetadata($rsm->aliasMap[$joinedFrom]);

			if ($fromClass->hasAssociation($table)) {
				$class = $this->em->getClassMetadata($fromClass->getAssociationTargetClass($table));
				$relation = $fromClass->getAssociationMapping($table);
				$table = $class->getTableName();

			} else {
				foreach ($fromClass->getAssociationMappings() as $mapping) {
					$targetClass = $this->em->getClassMetadata($mapping['targetEntity']);
					if ($targetClass->getTableName() === $table) {
						$class = $targetClass;
						$relation = $mapping;
						$table = $class->getTableName();
						break;
					}
				}
			}

		} else {
			/** @var Kdyby\Doctrine\Mapping\ClassMetadata $class */
			foreach ($this->em->getMetadataFactory()->getAllMetadata() as $class) {
				if ($class->getTableName() === $table) {
					break;
				}
			}
		}

		if (!$class instanceof Doctrine\ORM\Mapping\ClassMetadata || $class->getTableName() !== $table) {
			return $table;
		}

		if ($joinedFrom === NULL) {
			$rsm->addEntityResult($class->getName(), $alias);

		} elseif ($relation) {
			$rsm->addJoinedEntityResult($class->getName(), $alias, $joinedFrom, $relation);
		}

		return $class->getTableName();
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function where($predicates)
	{
		return call_user_func_array('parent::where', $this->separateParameters(func_get_args()));
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function andWhere($where)
	{
		return call_user_func_array('parent::andWhere', $this->separateParameters(func_get_args()));
	}



	/**
	 * {@inheritdoc}
	 * @return NativeQueryBuilder
	 */
	public function orWhere($where)
	{
		return call_user_func_array('parent::orWhere', $this->separateParameters(func_get_args()));
	}



	protected function separateParameters(array $args)
	{
		for ($i = 0; isset($args[$i]) && isset($args[$i + 1]) && ($arg = $args[$i]); $i++) {
			if (!preg_match_all('~((\\:|\\?)(?P<name>[a-z0-9_]+))(?=(?:\\z|\\s|\\)))~i', $arg, $m)) {
				continue;
			}

			foreach ($m['name'] as $l => $name) {
				$value = $args[++$i];
				$type = NULL;

				if ($value instanceof \DateTime || $value instanceof \DateTimeImmutable) {
					$type = DbalType::DATETIME;

				} elseif (is_array($value)) {
					$type = Connection::PARAM_STR_ARRAY;
				}

				$this->setParameter($name, $value, $type);
				unset($args[$i]);
			}
		}

		return $args;
	}



	/*************************** Nette\Object ***************************/



	/**
	 * Access to reflection.
	 * @return \Nette\Reflection\ClassType
	 */
	public static function getReflection()
	{
		return new Nette\Reflection\ClassType(get_called_class());
	}



	/**
	 * Call to undefined method.
	 *
	 * @param string $name
	 * @param array $args
	 *
	 * @throws \Nette\MemberAccessException
	 * @return mixed
	 */
	public function __call($name, $args)
	{
		return ObjectMixin::call($this, $name, $args);
	}



	/**
	 * Call to undefined static method.
	 *
	 * @param string $name
	 * @param array $args
	 *
	 * @throws \Nette\MemberAccessException
	 * @return mixed
	 */
	public static function __callStatic($name, $args)
	{
		return ObjectMixin::callStatic(get_called_class(), $name, $args);
	}



	/**
	 * Adding method to class.
	 *
	 * @param $name
	 * @param null $callback
	 *
	 * @throws \Nette\MemberAccessException
	 * @return callable|null
	 */
	public static function extensionMethod($name, $callback = NULL)
	{
		if (strpos($name, '::') === FALSE) {
			$class = get_called_class();
		} else {
			list($class, $name) = explode('::', $name);
		}
		if ($callback === NULL) {
			return ObjectMixin::getExtensionMethod($class, $name);
		} else {
			ObjectMixin::setExtensionMethod($class, $name, $callback);
		}
	}



	/**
	 * Returns property value. Do not call directly.
	 *
	 * @param string $name
	 *
	 * @throws \Nette\MemberAccessException
	 * @return mixed
	 */
	public function &__get($name)
	{
		return ObjectMixin::get($this, $name);
	}



	/**
	 * Sets value of a property. Do not call directly.
	 *
	 * @param string $name
	 * @param mixed $value
	 *
	 * @throws \Nette\MemberAccessException
	 * @return void
	 */
	public function __set($name, $value)
	{
		ObjectMixin::set($this, $name, $value);
	}



	/**
	 * Is property defined?
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	public function __isset($name)
	{
		return ObjectMixin::has($this, $name);
	}



	/**
	 * Access to undeclared property.
	 *
	 * @param string $name
	 *
	 * @throws \Nette\MemberAccessException
	 * @return void
	 */
	public function __unset($name)
	{
		ObjectMixin::remove($this, $name);
	}
}
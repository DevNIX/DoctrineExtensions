<?php

namespace Gedmo\Tree\Entity\Repository;

use Gedmo\Exception\InvalidArgumentException;
use Doctrine\ORM\Query;
use Gedmo\Tree\Strategy;
use Gedmo\Tree\Strategy\ORM\Closure;
use Gedmo\Tool\Wrapper\EntityWrapper;
use Doctrine\ORM\Proxy\Proxy;

/**
 * The ClosureTreeRepository has some useful functions
 * to interact with Closure tree. Repository uses
 * the strategy used by listener
 *
 * @author Gustavo Adrian <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @package Gedmo.Tree.Entity.Repository
 * @subpackage ClosureRepository
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class ClosureTreeRepository extends AbstractTreeRepository
{
    /** Alias for the level value used in the subquery of the getNodesHierarchy method */
    const SUBQUERY_LEVEL = 'level';

    /**
     * Get all root nodes query
     *
     * @return Query
     */
    public function getRootNodesQuery()
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);
        $qb = $this->_em->createQueryBuilder();
        $qb->select('node')
            ->from($config['useObjectClass'], 'node')
            ->where('node.' . $config['parent'] . " IS NULL");
        return $qb->getQuery();
    }

    /**
     * Get all root nodes
     *
     * @return array
     */
    public function getRootNodes()
    {
        return $this->getRootNodesQuery()->getResult();
    }

    /**
     * Counts the children of given TreeNode
     *
     * @param object $node - if null counts all records in tree
     * @param boolean $direct - true to count only direct children
     * @throws InvalidArgumentException - if input is not valid
     * @return integer
     */
    public function childCount($node = null, $direct = false)
    {
        $count = 0;
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);
        if (null !== $node) {
            if ($node instanceof $meta->name) {
                if (!$this->_em->getUnitOfWork()->isInIdentityMap($node)) {
                    throw new InvalidArgumentException("Node is not managed by UnitOfWork");
                }
                if ($direct) {
                    $qb = $this->_em->createQueryBuilder();
                    $qb->select('COUNT(node)')
                        ->from($config['useObjectClass'], 'node')
                        ->where('node.' . $config['parent'] . ' = :node');

                    $q = $qb->getQuery();
                } else {
                    $closureMeta = $this->_em->getClassMetadata($config['closure']);
                    $dql = "SELECT COUNT(c) FROM {$closureMeta->name} c";
                    $dql .= " WHERE c.ancestor = :node";
                    $dql .= " AND c.descendant <> :node";
                    $q = $this->_em->createQuery($dql);
                }
                $q->setParameters(compact('node'));
                $count = intval($q->getSingleScalarResult());
            } else {
                throw new InvalidArgumentException("Node is not related to this repository");
            }
        } else {
            $dql = "SELECT COUNT(node) FROM " . $config['useObjectClass'] . " node";
            if ($direct) {
                $dql .= ' WHERE node.' . $config['parent'] . ' IS NULL';
            }
            $q = $this->_em->createQuery($dql);
            $count = intval($q->getSingleScalarResult());
        }
        return $count;
    }

    /**
     * Get the Tree path query by given $node
     *
     * @param object $node
     * @throws InvalidArgumentException - if input is not valid
     * @return Query
     */
    public function getPathQuery($node)
    {
        $meta = $this->getClassMetadata();
        if (!$node instanceof $meta->name) {
            throw new InvalidArgumentException("Node is not related to this repository");
        }
        if (!$this->_em->getUnitOfWork()->isInIdentityMap($node)) {
            throw new InvalidArgumentException("Node is not managed by UnitOfWork");
        }
        $config = $this->listener->getConfiguration($this->_em, $meta->name);
        $closureMeta = $this->_em->getClassMetadata($config['closure']);

        $dql = "SELECT c, node FROM {$closureMeta->name} c";
        $dql .= " INNER JOIN c.ancestor node";
        $dql .= " WHERE c.descendant = :node";
        $dql .= " ORDER BY c.depth DESC";
        $q = $this->_em->createQuery($dql);
        $q->setParameters(compact('node'));
        return $q;
    }

    /**
     * Get the Tree path of Nodes by given $node
     *
     * @param object $node
     * @return array - list of Nodes in path
     */
    public function getPath($node)
    {
        return array_map(function($closure) {
            return $closure->getAncestor();
        }, $this->getPathQuery($node)->getResult());
    }

    /**
     * Get tree children query followed by given $node
     *
     * @param object $node - if null, all tree nodes will be taken
     * @param boolean $direct - true to take only direct children
     * @param string $sortByField - field name to sort by
     * @param string $direction - sort direction : "ASC" or "DESC"
     * @throws InvalidArgumentException - if input is not valid
     * @return Query
     */
    public function childrenQuery($node = null, $direct = false, $sortByField = null, $direction = 'ASC')
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);

        $qb = $this->_em->createQueryBuilder();
        if ($node !== null) {
            if ($node instanceof $meta->name) {
                if (!$this->_em->getUnitOfWork()->isInIdentityMap($node)) {
                    throw new InvalidArgumentException("Node is not managed by UnitOfWork");
                }
                $qb->select('c, node')
                    ->from($config['closure'], 'c')
                    ->innerJoin('c.descendant', 'node')
                    ->where('c.ancestor = :node');
                if ($direct) {
                    $qb->andWhere('c.depth = 1');
                } else {
                    $qb->andWhere('c.descendant <> :node');
                }
            } else {
                throw new \InvalidArgumentException("Node is not related to this repository");
            }
        } else {
            $qb->select('node')
                ->from($config['useObjectClass'], 'node');
            if ($direct) {
                $qb->where('node.' . $config['parent'] . ' IS NULL');
            }
        }
        if ($sortByField) {
            if ($meta->hasField($sortByField) && in_array(strtolower($direction), array('asc', 'desc'))) {
                $qb->orderBy('node.' . $sortByField, $direction);
            } else {
                throw new InvalidArgumentException("Invalid sort options specified: field - {$sortByField}, direction - {$direction}");
            }
        }
        $q = $qb->getQuery();
        if ($node) {
            $q->setParameters(compact('node'));
        }
        return $q;
    }

    /**
     * Get list of children followed by given $node
     *
     * @param object $node - if null, all tree nodes will be taken
     * @param boolean $direct - true to take only direct children
     * @param string $sortByField - field name to sort by
     * @param string $direction - sort direction : "ASC" or "DESC"
     * @return array - list of given $node children, null on failure
     */
    public function children($node = null, $direct = false, $sortByField = null, $direction = 'ASC')
    {
        $result = $this->childrenQuery($node, $direct, $sortByField, $direction)->getResult();
        if ($node) {
            $result = array_map(function($closure) {
                return $closure->getDescendant();
            }, $result);
        }
        return $result;
    }

    /**
     * Removes given $node from the tree and reparents its descendants
     *
     * @todo: may be improved, to issue single query on reparenting
     * @param object $node
     * @throws RuntimeException - if something fails in transaction
     * @return void
     */
    public function removeFromTree($node)
    {
        $meta = $this->getClassMetadata();
        if (!$node instanceof $meta->name) {
            throw new InvalidArgumentException("Node is not related to this repository");
        }
        $wrapped = new EntityWrapper($node, $this->_em);
        if (!$wrapped->hasValidIdentifier()) {
            throw new InvalidArgumentException("Node is not managed by UnitOfWork");
        }
        $config = $this->listener->getConfiguration($this->_em, $meta->name);
        $pk = $meta->getSingleIdentifierFieldName();
        $nodeId = $wrapped->getIdentifier();
        $parent = $wrapped->getPropertyValue($config['parent']);

        $dql = "SELECT node FROM {$config['useObjectClass']} node";
        $dql .= " WHERE node.{$config['parent']} = :node";
        $q = $this->_em->createQuery($dql);
        $q->setParameters(compact('node'));
        $nodesToReparent = $q->getResult();
        // process updates in transaction
        $this->_em->getConnection()->beginTransaction();
        try {
            foreach ($nodesToReparent as $nodeToReparent) {
                $id = $meta->getReflectionProperty($pk)->getValue($nodeToReparent);
                $meta->getReflectionProperty($config['parent'])->setValue($nodeToReparent, $parent);

                $dql = "UPDATE {$config['useObjectClass']} node";
                $dql .= " SET node.{$config['parent']} = :parent";
                $dql .= " WHERE node.{$pk} = :id";

                $q = $this->_em->createQuery($dql);
                $q->setParameters(compact('parent', 'id'));
                $q->getSingleScalarResult();

                $this->listener
                    ->getStrategy($this->_em, $meta->name)
                    ->updateNode($this->_em, $nodeToReparent, $node);

                $oid = spl_object_hash($nodeToReparent);
                $this->_em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['parent'], $parent);
            }

            $dql = "DELETE {$config['useObjectClass']} node";
            $dql .= " WHERE node.{$pk} = :nodeId";

            $q = $this->_em->createQuery($dql);
            $q->setParameters(compact('nodeId'));
            $q->getSingleScalarResult();
            $this->_em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->_em->close();
            $this->_em->getConnection()->rollback();
            throw new \Gedmo\Exception\RuntimeException('Transaction failed: '.$e->getMessage(), null, $e);
        }
        // remove from identity map
        $this->_em->getUnitOfWork()->removeFromIdentityMap($node);
        $node = null;
    }

    /**
     * Process nodes and produce an array with the
     * structure of the tree
     *
     * @param array - Array of nodes
     *
     * @return array - Array with tree structure
     */
    public function buildTreeArray(array $nodes)
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);
        $nestedTree = array();
        $idField = $meta->getSingleIdentifierFieldName();
        $hasLevelProp = !empty($config['level']);
        $levelProp = $hasLevelProp ? $config['level'] : self::SUBQUERY_LEVEL;

        if (count($nodes) > 0) {
            $l = 1;
            $refs = array();

            foreach ($nodes as $n) {
                $node = $n[0]['descendant'];
                $node['__children'] = array();
                $level = $hasLevelProp ? $node[$levelProp] : $n[$levelProp];

                if ($l < $level) {
                    $l = $level;
                }

                if ($l == 1) {
                    $tmp = &$nestedTree;
                } else {
                    $tmp = &$refs[$n['parent_id']]['__children'];
                }

                $key = count($tmp);
                $tmp[$key] = $node;
                $refs[$node[$idField]] = &$tmp[$key];
            }

            unset($refs);
        }

        return $nestedTree;
    }

    /**
     * {@inheritdoc}
     */
    public function getNodesHierarchy($node, $direct, array $config, array $options = array())
    {
        return $this->getNodesHierarchyQuery($node, $direct, $config, $options)->getArrayResult();
    }

    public function getNodesHierarchyQuery($node, $direct, array $config, array $options = array())
    {
        return $this->getNodesHierarchyQueryBuilder($node, $direct, $config, $options)->getQuery();
    }

    public function getNodesHierarchyQueryBuilder($node, $direct, array $config, array $options = array())
    {
        $meta = $this->getClassMetadata();
        $idField = $meta->getSingleIdentifierFieldName();
        $subQuery = '';
        $hasLevelProp = isset($config['level']) && $config['level'];

        if (!$hasLevelProp) {
            $subQuery = ', (SELECT MAX(c2.depth) + 1 FROM '.$config['closure'];
            $subQuery .= ' c2 WHERE c2.descendant = c.descendant GROUP BY c2.descendant) AS '.self::SUBQUERY_LEVEL;
        }

        $q = $this->_em->createQueryBuilder()
            ->select('c, node, p.'.$idField.' AS parent_id'.$subQuery)
            ->from($config['closure'], 'c')
            ->innerJoin('c.descendant', 'node')
            ->leftJoin('node.parent', 'p')
            ->where('c.ancestor = :node')
            ->addOrderBy(($hasLevelProp ? 'node.'.$config['level'] : self::SUBQUERY_LEVEL), 'asc');

        $defaultOptions = array();
        $options = array_merge($defaultOptions, $options);

        if (isset($options['childSort']) && is_array($options['childSort']) &&
            isset($options['childSort']['field']) && isset($options['childSort']['dir'])) {
            $q->addOrderBy(
                'node.'.$options['childSort']['field'],
                strtolower($options['childSort']['dir']) == 'asc' ? 'asc' : 'desc'
            );
        }

        $q->setParameters(compact('node'));

        return $q;
    }

    /**
     * {@inheritdoc}
     */
    protected function validate()
    {
        return $this->listener->getStrategy($this->_em, $this->getClassMetadata()->name)->getName() === Strategy::CLOSURE;
    }
}

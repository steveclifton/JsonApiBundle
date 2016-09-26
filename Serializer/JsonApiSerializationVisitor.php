<?php

/*
 * This file is part of the Mango package.
 *
 * (c) Steffen Brem <steffenbrem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mango\Bundle\JsonApiBundle\Serializer;

use JMS\Serializer\Context;
use JMS\Serializer\JsonSerializationVisitor;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use Mango\Bundle\JsonApiBundle\Configuration\Metadata\ClassMetadata as JsonApiClassMetadata;
use Mango\Bundle\JsonApiBundle\EventListener\Serializer\JsonEventSubscriber;
use Metadata\MetadataFactoryInterface;

/**
 * @author Steffen Brem <steffenbrem@gmail.com>
 */
class JsonApiSerializationVisitor extends JsonSerializationVisitor
{
    /**
     * @var MetadataFactoryInterface
     */
    protected $metadataFactory;

    /**
     * @var bool
     */
    protected $showVersionInfo;

    protected $isJsonApiDocument = false;

    /**
     * @param PropertyNamingStrategyInterface $propertyNamingStrategy
     * @param MetadataFactoryInterface        $metadataFactory
     * @param                                 $showVersionInfo
     */
    public function __construct(
        PropertyNamingStrategyInterface $propertyNamingStrategy,
        MetadataFactoryInterface $metadataFactory,
        $showVersionInfo
    ) {
        parent::__construct($propertyNamingStrategy);

        $this->metadataFactory = $metadataFactory;
        $this->showVersionInfo = $showVersionInfo;
    }

    /**
     * @return bool
     */
    public function isJsonApiDocument()
    {
        return $this->isJsonApiDocument;
    }

    /**
     * @param mixed $root
     *
     * @return array
     */
    public function prepare($root)
    {
        if (is_array($root) && array_key_exists('data', $root)) {
            $data = $root['data'];
        } else {
            $data = $root;
        }

        $this->isJsonApiDocument = $this->validateJsonApiDocument($data);

        if ($this->isJsonApiDocument) {
            $meta = null;
            if (is_array($root) && isset($root['meta']) && is_array($root['meta'])) {
                $meta = $root['meta'];
            }

            return $this->buildJsonApiRoot($data, $meta);
        }

        return $root;
    }

    protected function buildJsonApiRoot($data, array $meta = null)
    {
        $root = array(
            'data' => $data,
        );

        if ($meta) {
            $root['meta'] = $meta;
        }

        return $root;
    }

    /**
     * it is a JSON-API document if:
     *  - it is an object and is a JSON-API resource
     *  - it is an array containing objects which are JSON-API resources
     *  - it is empty (we cannot identify it)
     *
     * @param mixed $data
     *
     * @return bool
     */
    protected function validateJsonApiDocument($data)
    {
        if (is_array($data) && count($data) > 0 && !$this->hasResource($data)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getResult()
    {
        if (false === $this->isJsonApiDocument) {
            return parent::getResult();
        }

        $root = $this->getRoot();

        // TODO: Error handling
        if (isset($root['data']) && array_key_exists('errors', $root['data'])) {
            return parent::getResult();
        }

        if ($root) {
            $data = array();
            $meta = array();
            $included = array();
            $links = array();

            if (isset($root['data'])) {
                $data = $root['data'];
            }

            if (isset($root['included'])) {
                $included = $root['included'];
            }

            if (isset($root['meta'])) {
                $meta = $root['meta'];
            }

            if (isset($root['links'])) {
                $links = $root['links'];
            }

            // filter out duplicate primary resource objects that are in `included`
            $included = array_udiff(
                (array)$included,
                (isset($data['type'])) ? [$data] : $data,
                function($a, $b) {
                    return strcmp($a['type'].$a['id'], $b['type'].$b['id']);
                }
            );

            // start building new root array
            $root = array();

            if ($this->showVersionInfo) {
                $root['jsonapi'] = array(
                    'version' => '1.0',
                );
            }

            if ($meta) {
                $root['meta'] = $meta;
            }

            if ($links) {
                $root['links'] = $links;
            }

            $root['data'] = $data;

            if ($included) {
                $root['included'] = array_values($included);
            }

            $this->setRoot($root);
        }

        return parent::getResult();
    }

    /**
     * {@inheritdoc}
     */
    public function endVisitingObject(ClassMetadata $metadata, $data, array $type, Context $context)
    {
        $rs = parent::endVisitingObject($metadata, $data, $type, $context);

        if ($context->getDepth() > 0) {
            return $rs;
        }
        
        if ($rs instanceof \ArrayObject) {
            $rs = [];
            $this->setRoot($rs);

            return $rs;
        }

        /** @var JsonApiClassMetadata $jsonApiMetadata */
        $jsonApiMetadata = $this->metadataFactory->getMetadataForClass(get_class($data));

        if (null === $jsonApiMetadata) {
            return $rs;
        }

        $result = array();

        $result['type'] = $jsonApiMetadata->getResource()->getType();

        $idField = $jsonApiMetadata->getIdField();

        $result['id'] = isset($rs[$idField]) ? $rs[$idField] : null;
        
        $result['attributes'] = array_filter($rs, function($key) use ($idField, $jsonApiMetadata) {
            switch ($key) {
                case $idField:
                case 'relationships':
                case 'links':
                    return false;
            }

            if ($key === JsonEventSubscriber::EXTRA_DATA_KEY) {
                return false;
            } elseif ($jsonApiMetadata->hasRelationship($key)) {
                return false;
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
        
        if (isset($rs['relationships'])) {
            $result['relationships'] = $rs['relationships'];
        }

        $root = (array)$context->getVisitor()->getRoot();

        $contextGroups = $context->attributes->get('groups')->getOrElse([]);

        if (!in_array('Sideload', $contextGroups)) {
            foreach ($jsonApiMetadata->getRelationships() as $relationship) {
                $relationshipName = $relationship->getName();

                if ($relationship->isIncludedByDefault()) {
                    if (isset($rs[$relationshipName])) {
                        if ($this->isSequentialArray($rs[$relationshipName])) {
                            foreach ($rs[$relationshipName] as $relationshipData) {
                                $this->addIncluded($root, $jsonApiMetadata, $relationshipData);
                            }
                        } else {
                            $this->addIncluded($root, $jsonApiMetadata, $rs[$relationshipName]);
                        }
                    }
                }
            }
        }

        $context->getVisitor()->setRoot($root);
        
        if (isset($rs['links'])) {
            $result['links'] = $rs['links'];
        }

        return $result;
    }

    /**
     * @param array $root
     * @param JsonApiClassMetadata $jsonApiMetadata
     * @param array $relationshipData
     */
    private function addIncluded(array &$root, JsonApiClassMetadata $jsonApiMetadata, array $relationshipData)
    {
        echo 1;exit;
        if (!isset($relationshipData['id'])) {
            return;
        }
        
        if (!isset($root['included'])) {
            $root['included'] = [];
        }

        // filter out dupes
        foreach ($root['included'] as $included) {
            if (
                $relationshipData['id'] === $included['id']
                && $jsonApiMetadata->getResource()->getType() === $included['type']
            ) {
                return;
            }
        }

        $root['included'][] = [
            'id' => $relationshipData['id'],
            'type' => $jsonApiMetadata->getResource()->getType(),
            'attributes' => $result['attributes'] = array_filter($relationshipData, function($key) {
                switch ($key) {
                    case 'id':
                    case 'type':
                    case 'relationships':
                    case 'links':
                        return false;
                }
                return true;
            }, ARRAY_FILTER_USE_KEY)
        ];
    }

    /**
     * @param $items
     *
     * @return bool
     */
    protected function hasResource($items)
    {
        foreach ($items as $item) {
            return $this->isResource($item);
        }

        return false;
    }

    /**
     * Check if the given variable is a valid JSON-API resource.
     *
     * @param $data
     *
     * @return bool
     */
    protected function isResource($data)
    {
        if (is_object($data)) {
            /** @var JsonApiClassMetadata $metadata */
            if ($metadata = $this->metadataFactory->getMetadataForClass(get_class($data))) {
                if ($metadata->getResource()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array $arr
     * @return bool
     */
    private function isSequentialArray(array $arr)
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

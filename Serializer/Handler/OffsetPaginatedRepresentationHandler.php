<?php

/*
 * This file is part of the Mango package.
 *
 * (c) Steffen Brem <steffenbrem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mango\Bundle\JsonApiBundle\Serializer\Handler;

use JMS\Serializer\Context;
use JMS\Serializer\GraphNavigator;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use Mango\Bundle\JsonApiBundle\Representation\OffsetPaginatedRepresentation;
use Mango\Bundle\JsonApiBundle\Serializer\JsonApiSerializationVisitor;

/**
 * 
 */
class OffsetPaginatedRepresentationHandler implements SubscribingHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribingMethods()
    {
        return array(
            array(
                'direction' => GraphNavigator::DIRECTION_SERIALIZATION,
                'format' => 'json',
                'type' => OffsetPaginatedRepresentation::class,
                'method' => 'serialize',
            ),
//            array(
//                'direction' => GraphNavigator::DIRECTION_DESERIALIZATION,
//                'format' => 'json',
//                'type' => static::getType(),
//                'method' => 'deserialize',
//            ),
        );
    }

    /**
     * @param JsonApiSerializationVisitor $visitor
     * @param OffsetPaginatedRepresentation $representation
     * @param array                       $type
     * @param Context                     $context
     *
     * @return array
     */
    public function serialize(
        JsonApiSerializationVisitor $visitor,
        OffsetPaginatedRepresentation $representation,
        array $type,
        Context $context
    )
    {
        if (false === $visitor->isJsonApiDocument()) {
            return $context->accept($representation->getItems());
        }

        return $this->transformRoot($representation, $visitor, $context);
    }

    /**
     * Transforms root of visitor with additional data based on the representation.
     *
     * @param OffsetPaginatedRepresentation     $representation
     * @param JsonApiSerializationVisitor $visitor
     * @param Context                     $context
     *
     * @return mixed
     */
    protected function transformRoot(
        OffsetPaginatedRepresentation $representation,
        JsonApiSerializationVisitor $visitor,
        Context $context
    )
    {
        // serialize items
        $data = $context->accept($representation->toArray());

        $root = $visitor->getRoot();

        $root['meta'] = array(
            'offset' => $representation->getOffset(),
            'limit' => $representation->getLimit(),
            'total-results' => $representation->getTotalResults()
        );

        $root['links'] = array(
            'first' => $representation->getUriForPage(1),
//            'last' => $representation->getUriForPage($representation->getPages()),
//            'next' => $representation->hasNextPage() ? $representation->getUriForPage($representation->getNextPage()) : null,
//            'previous' => $representation->hasPreviousPage() ? $representation->getUriForPage($representation->getPreviousPage()) : null
        );

        $visitor->setRoot($root);

        return $data;
    }
    
}

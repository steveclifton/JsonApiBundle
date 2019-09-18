<?php

/*
 * This file is part of the Mango package.
 *
 * (c) Steffen Brem <steffenbrem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mango\Bundle\JsonApiBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Inflector\Inflector;

/**
 * JsonApi Request
 */
class JsonApiRequest
{
    /**
     * @var Request
     */
    protected $request;
    
    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->request = $requestStack->getMasterRequest();
    }

    /**
     * @return array
     */
    public function getFilters()
    {
        $filters = $this->request->query->get('filters', []);
        
        foreach($filters as $key => $value) {
            $filters[$key] = array_map('trim', explode(',', $value));
        }

        return $filters;
    }

    /**
     * @return string[]
     */
    public function getIncludedRelationships()
    {
        $includeParam = $this->request->query->get('include', []);
        
        $included = [];
        
        if (is_string($includeParam) && strlen($includeParam)) {
            $included = array_map('trim', explode(',', $includeParam));
            $included = array_filter($included);
        }
        $newIncluded = [];
        foreach ($included as $item) {
            $newIncluded[] = $item;
            $newIncluded[] = Inflector::singularize($item);
        }

        $newIncluded = array_unique($newIncluded);

        return $newIncluded;
    }

    /**
     * @param integer $default
     * @return integer
     */
    public function getPaginationLimit($default)
    {
        $pagination = $this->request->query->get('page', []);
        
        return isset($pagination['limit']) ? (int) $pagination['limit'] : $default;
    }
    
    /**
     * @return integer
     */
    public function getPaginationOffset()
    {
        $pagination = $this->request->query->get('page', []);

        return isset($pagination['offset']) ? (int) $pagination['offset'] : 0;
    }

    /**
     * @return array
     */
    public function getSort()
    {
        $sortParam = $this->request->query->get('sort', '');

        $sort = [];
        
        if (!empty($sortParam) && is_string($sortParam)) {
            $sort = array_map('trim', explode(',', $sortParam));
        }

        return $sort;
    }

    /**
     * @return array
     */
    public function getFields()
    {
        $fieldParamss = $this->request->query->get('fields', []);
        $fieldParamss = array_filter($fieldParamss);

        $fields = [];
        
        foreach ($fieldParamss as $type => $members) {
            $fields[$type] = [];
            
            $members = explode(',', $members);
            $members = array_map('trim', $members);
            
            foreach ($members as $member) {
                $fields[$type][] = $member;
            }
        }
        
        return $fields;
    }
}
